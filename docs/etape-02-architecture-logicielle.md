# Étape 2 — Architecture Logicielle

**Projet :** SILARIS — Plateforme de Gestion de Transit International (TMS)
**Version :** 1.0
**Date :** Juillet 2026
**Statut :** En attente de validation
**Prérequis :** Étape 1 validée

---

## 1. Principes directeurs

| Principe | Application concrète dans SILARIS |
|---|---|
| Clean Architecture | Dépendances orientées vers le domaine ; le métier ne connaît ni Laravel, ni PostgreSQL, ni HTTP |
| SOLID | Interfaces par capacité (ports), implémentations substituables (adapters), classes à responsabilité unique |
| DDD | Bounded contexts explicites, agrégats, value objects, événements de domaine, langage ubiquitaire (celui du glossaire Étape 1) |
| CQRS pragmatique | Séparation Command/Query au niveau applicatif ; **pas** d'event sourcing ni de bases séparées lecture/écriture (complexité injustifiée à ce stade) |
| API First | Le frontend est un client de l'API comme les autres ; tout passe par `/api/v1` ; contrat OpenAPI = source de vérité |
| Multi-tenant by design | Aucune requête ne peut s'exécuter hors contexte tenant ; isolation garantie en profondeur (RLS), pas seulement en surface |
| Évolutivité | Monolithe modulaire découpé en modules étanches → extraction possible en services séparés sans réécriture |

---

## 2. Décision structurante n°1 — Monolithe modulaire (pas de microservices)

**Décision : monolithe modulaire Laravel, découpé en modules DDD étanches, avec 2 processus d'exécution séparés (HTTP + workers).**

**Justification :**
- Une équipe, un produit, un déploiement : les microservices ajouteraient réseau, observabilité distribuée, sagas, versioning inter-services — coût énorme, gain nul à ce stade.
- Le vrai besoin d'isolation est **logique** (modules étanches, contrats clairs), pas physique.
- Les charges asynchrones lourdes (tracking, sync Odoo, notifications) sont déjà isolées dans des **workers dédiés** scalables indépendamment.
- Chaque module respecte des règles de dépendance strictes (voir §4) → si un module doit devenir un service (ex. tracking à très fort volume), extraction propre possible.

**Frontière conservée :** communication inter-modules **exclusivement** via contrats publics (interfaces + événements), jamais d'accès direct aux modèles d'un autre module.

---

## 3. Décision structurante n°2 — Multi-tenancy : base partagée + RLS PostgreSQL

**Décision : une base PostgreSQL partagée, colonne `tenant_id` sur toutes les tables tenant-scopées, Row-Level Security PostgreSQL activée en défense en profondeur.**

**Options évaluées :**

| Option | Avantages | Inconvénients | Verdict |
|---|---|---|---|
| Base par tenant | Isolation physique maximale | Coût opérationnel explosif (migrations × n, backups × n, connexions), anti-économique en SaaS à volume | Rejeté (réservé aux clients « installation privée ») |
| Schéma par tenant | Isolation moyenne | Migrations multiples, limites PostgreSQL avec milliers de schémas, tooling Laravel fragile | Rejeté |
| **Base partagée + tenant_id + RLS** | Une migration, un backup, pooling efficace, scalable ; RLS = garde-fou au niveau moteur DB | Discipline requise (automatisée, voir ci-dessous) | **Retenu** |

**Mécanisme à 3 couches (défense en profondeur) :**

1. **Couche applicative** : middleware `TenantContext` résout le tenant (sous-domaine ou claim du token), le place dans un contexte immuable pour toute la requête. Global scope Eloquent `BelongsToTenant` appliqué automatiquement à tous les modèles tenant-scopés.
2. **Couche base de données** : RLS PostgreSQL — `CREATE POLICY tenant_isolation ON dossiers USING (tenant_id = current_setting('app.tenant_id')::uuid)`. La connexion positionne `app.tenant_id` en début de requête. Même un bug applicatif (scope oublié, requête brute) ne peut pas fuiter des données.
3. **Couche jobs/workers** : chaque job sérialise son `tenant_id` ; le worker restaure le contexte tenant avant exécution. Un job sans tenant explicite = exception.

**Cas particulier « installation privée »** : même codebase, un seul tenant dans la base. Aucun fork.

**Référentiels globaux** (pays, ports, aéroports, incoterms) : tables sans `tenant_id`, lecture partagée, extension par tenant via tables satellites (`tenant_ports` etc. — détail à l'Étape 6).

---

## 4. Découpage en modules (bounded contexts DDD)

```
Modules métier (Core Domain)
├── Shipment        — dossiers, workflow, timeline, tâches          [cœur]
├── Ocean           — bookings, conteneurs, BL, navires, voyages    [cœur]
├── Air             — MAWB/HAWB, vols                               [cœur]
├── Road            — flotte, chauffeurs, missions, POD             [cœur]
├── Tracking        — événements normalisés (DCSA), connecteurs     [cœur]
├── Pricing         — grilles tarifaires, moteur de cotation        [cœur]
├── Billing         — devis, proformas, factures, avoirs            [support]
├── Crm             — clients, prospects, fournisseurs, réclamations[support]
├── Documents       — GED, versioning, URLs signées                 [support]
├── Notifications   — canaux, préférences, modèles, envois          [support]
│
Modules génériques (Generic Subdomains)
├── Identity        — auth, MFA, sessions, RBAC, API keys
├── Tenancy         — tenants, sociétés, agences, paramètres
├── Referential     — pays, ports, aéroports, devises, incoterms
├── Audit           — journal d'audit immuable
├── Search          — indexation Meilisearch
├── Reporting       — dashboards, rapports, exports
│
Modules d'intégration (Anti-Corruption Layers)
├── OdooSync        — synchronisation Odoo, files, conflits, journal
└── CarrierConnect  — connecteurs compagnies (maritime V1, aérien plus tard)
```

**Règles de dépendance (imposées par tests d'architecture automatisés — Deptrac ou Pest Arch) :**

1. Modules métier → peuvent dépendre de : Identity, Tenancy, Referential, Audit (contrats publics uniquement).
2. Aucun module métier ne dépend d'un autre module métier **directement** — communication par événements de domaine ou interfaces publiées.
   - Ex. : `Billing` n'importe jamais un modèle de `Shipment` ; il écoute `ShipmentClosed` ou consomme `ShipmentSummaryQuery` (contrat publié).
3. `OdooSync` et `CarrierConnect` sont des ACL (Anti-Corruption Layers) : ils traduisent le monde extérieur vers le langage du domaine, jamais l'inverse.
4. Le framework (Laravel) n'apparaît **jamais** dans la couche Domain.

---

## 5. Architecture interne d'un module (Clean Architecture)

Structure type — exemple module `Shipment` :

```
src/Modules/Shipment/
├── Domain/                          # PHP pur. Zéro dépendance framework.
│   ├── Model/                       # Agrégats & entités : Shipment, WorkflowStep…
│   ├── ValueObject/                 # Reference, Incoterm, EtaEtd, Priority…
│   ├── Event/                       # ShipmentCreated, StepCompleted, DelayDetected…
│   ├── Repository/                  # Interfaces (ports) : ShipmentRepository
│   ├── Service/                     # Services de domaine : WorkflowEngine
│   └── Exception/                   # Exceptions métier typées
│
├── Application/                     # Cas d'usage. Orchestration, transactions.
│   ├── Command/                     # CreateShipment, AdvanceWorkflowStep… + Handlers
│   ├── Query/                       # GetShipmentTimeline, SearchShipments… + Handlers
│   ├── Dto/                         # Objets de transfert entrée/sortie
│   └── Port/                        # Interfaces vers l'extérieur (NotifierPort…)
│
├── Infrastructure/                  # Implémentations. Ici vit Laravel.
│   ├── Persistence/                 # EloquentShipmentRepository, modèles Eloquent
│   ├── Projection/                  # Read models optimisés (vues, tables dénormalisées)
│   └── Provider/                    # ShipmentServiceProvider (bindings DI)
│
└── Interface/                       # Points d'entrée
    ├── Http/                        # Controllers, FormRequests, API Resources
    ├── Console/                     # Commandes artisan du module
    └── Listener/                    # Écouteurs d'événements d'autres modules
```

**Flux Command (écriture) :**
```
HTTP Request → FormRequest (validation) → Controller
  → CommandBus → Handler (Application)
    → Agrégat (Domain, règles métier) → Repository (port)
      → EloquentRepository (Infrastructure) → PostgreSQL
    → Événements de domaine → dispatch (listeners synchrones ou queue)
  → API Resource → JSON
```

**Flux Query (lecture) :** contourne le domaine — QueryHandler → read model/projection SQL optimisée → DTO → JSON. C'est là qu'est le CQRS pragmatique : modèles de lecture dénormalisés pour les listes/dashboards, agrégats riches pour les écritures.

---

## 6. Backbone événementiel

**Trois catégories d'événements, trois usages :**

| Type | Portée | Transport | Exemple |
|---|---|---|---|
| Événement de domaine | Interne, inter-modules | Bus Laravel (sync ou queued) | `ShipmentDelayed` → module Notifications |
| Événement d'intégration | Sortant, vers systèmes tiers | Table `outbox` → webhooks sortants | `invoice.validated` → webhook tenant |
| Événement de tracking | Entrant, normalisé DCSA | Connecteurs → `tracking_events` | `VESSEL_DEPARTURE` → timeline dossier |

**Pattern Transactional Outbox** pour la fiabilité : l'événement d'intégration est écrit dans la table `outbox` **dans la même transaction** que la donnée métier ; un worker le publie ensuite (webhooks, sync Odoo). Garantie : jamais de donnée sans événement, jamais d'événement sans donnée.

**Files d'attente (Redis + Laravel Horizon), séparées par nature :**

| File | Contenu | Priorité / particularité |
|---|---|---|
| `default` | Jobs applicatifs divers | Normale |
| `notifications` | Emails, SMS, WhatsApp | Haute, retry court |
| `tracking` | Polling connecteurs, ingestion événements | Volume élevé, rate-limited par compagnie |
| `odoo` | Synchronisation Odoo | FIFO par entité, retry backoff exponentiel, dead-letter |
| `documents` | Antivirus, génération PDF, (OCR futur) | Lourde, basse priorité |
| `reports` | Exports PDF/Excel, rapports planifiés | Basse |

Chaque file = pool de workers dimensionnable indépendamment (et pods séparés en K8s).

---

## 7. Architecture des connecteurs (CarrierConnect)

**Pattern : Ports & Adapters + Registry.**

```
Domain (Tracking)
└── Port : CarrierTrackingProvider (interface unique)
      ├── trackContainer(ContainerNumber): TrackingResult
      ├── trackBillOfLading(BlNumber): TrackingResult
      ├── getVoyageSchedule(VoyageRef): PortCallCollection
      └── capabilities(): CarrierCapabilities   # ce que le connecteur sait faire

Infrastructure (CarrierConnect)
├── Adapters : MscConnector, CmaCgmConnector, MaerskConnector,
│              HapagLloydConnector, CoscoConnector, EvergreenConnector,
│              OneConnector, OoclConnector, YangMingConnector
├── ManualTrackingAdapter              # fallback saisie manuelle (même interface)
├── CarrierRegistry                    # résolution connecteur par code SCAC
├── Normalizer                         # statuts propriétaires → événements DCSA
├── RateLimiter / CircuitBreaker       # par compagnie (quotas API respectés)
└── ExchangeLogger                     # journalisation requête/réponse/latence
```

**Cycle de vie d'un tracking :**
1. Scheduler (fréquence par tenant) → job `RefreshTracking` par entité active (conteneur/BL non livré).
2. Registry résout le connecteur via le SCAC de la compagnie du dossier.
3. Appel API (circuit breaker : compagnie en panne → pause automatique, pas d'avalanche de retries).
4. Réponse brute journalisée → Normalizer → événements DCSA dédupliqués (idempotence par hash événement).
5. Nouveaux événements → `tracking_events` → événement de domaine `TrackingEventReceived` → mise à jour dossier, recalcul ETA, détection retard, notifications.

**Ajout d'une compagnie = 1 classe adapter + 1 entrée registry + 1 table de mapping statuts.** Zéro modification du cœur. Connecteurs aériens futurs : même contrat, entités AWB au lieu de conteneurs.

---

## 8. Couche d'intégration Odoo (OdooSync)

**Anti-Corruption Layer complet — Odoo ne « voit » jamais le domaine SILARIS et réciproquement.**

```
OdooSync/
├── Mapping/          # EntityMap : (module, entité SILARIS) ↔ (modèle Odoo, id) persistant
├── Translator/       # SilarisInvoice → payload account.move ; res.partner → dto Client…
├── Transport/        # Client JSON-RPC/REST Odoo (auth, retry, timeout)
├── Sync/
│   ├── Push/         # SILARIS → Odoo : clients, fournisseurs, devis, factures, avoirs
│   ├── Pull/         # Odoo → SILARIS : statuts paiement, taxes, devises (webhook + polling filet)
│   └── Conflict/     # détection version (write_date Odoo vs updated_at SILARIS), résolution
└── Journal/          # sync_logs : entité, sens, payload, résultat, erreur, durée
```

**Règles :**
- **Source de vérité déclarée par entité** : clients/fournisseurs/factures → SILARIS maître ; statuts paiement/taxes/devises → Odoo maître. Le maître écrase, l'esclave notifie en cas de divergence.
- Push via file `odoo` (outbox) : validation facture → job de sync ; jamais d'appel Odoo synchrone dans une requête utilisateur.
- Idempotence : chaque push porte une clé d'idempotence ; re-livraison sans doublon.
- Mode dégradé : Odoo down → circuit breaker ouvert, jobs s'accumulent, écran d'état pour le rôle Comptable, reprise automatique.
- Multi-versions Odoo : Translator par version majeure (17 cible, 16/18 tolérées) derrière la même interface.

---

## 9. Frontend Next.js — architecture

```
apps/web/                            # monorepo pnpm + Turborepo
├── src/
│   ├── app/                         # App Router
│   │   ├── (auth)/                  # login, MFA, reset — layout minimal
│   │   ├── (app)/                   # application interne — layout complet (sidebar)
│   │   │   ├── dashboard/
│   │   │   ├── shipments/           # dossiers + sous-routes maritime/aérien/routier
│   │   │   ├── crm/
│   │   │   ├── quotes/  billing/  documents/  reports/  admin/
│   │   ├── (portal)/                # portail client — layout dédié
│   │   └── track/[reference]/       # page publique de suivi (SSR, sans auth)
│   ├── features/                    # logique par domaine : hooks, composants, schémas Zod
│   ├── components/                  # UI transverse (design system local)
│   ├── lib/                         # client API typé, auth, i18n, utils
│   └── stores/                      # état UI global léger (Zustand)
packages/
├── api-client/                      # généré depuis OpenAPI (types + hooks TanStack Query)
├── ui/                              # design system partagé (Tailwind + Radix primitives)
└── config/                          # eslint, tsconfig partagés
```

**Choix clés :**

| Sujet | Décision | Raison |
|---|---|---|
| État serveur | TanStack Query exclusivement | Cache, invalidation, optimistic updates ; pas de Redux |
| État UI | Zustand (léger) | Sidebar, thème, préférences vues |
| Formulaires | React Hook Form + Zod | Schémas Zod **générés depuis l'OpenAPI** → validation front/back jamais désynchronisée |
| Rendu | App interne : CSR après auth (SPA-like). Page publique de suivi : SSR (SEO, rapidité) | Chaque mode à sa place |
| Temps réel | SSE (Server-Sent Events) pour notifications in-app et rafraîchissement timeline | Plus simple que WebSocket, suffisant (flux descendant) ; upgrade possible |
| Client API | Généré depuis OpenAPI (openapi-ts) | Contrat unique, types bout en bout |
| Thème | Mode clair/sombre via CSS variables + `prefers-color-scheme` | Exigence cahier des charges |

---

## 10. API — contrat et conventions

- Base : `/api/v1` — versionnement d'URL ; breaking changes ⇒ `/api/v2`, v1 maintenue (politique de dépréciation documentée).
- Espaces : `/api/v1/*` (app interne, token utilisateur), `/api/v1/portal/*` (portail client), `/api/v1/public/tracking` (public, rate-limited strict), `/api/v1/integrations/*` (API keys machine-to-machine).
- Conventions : ressources plurielles kebab-case, pagination par curseur sur les grandes collections, filtres normalisés (`?filter[status]=in_transit`), tri (`?sort=-eta`), includes contrôlés (`?include=client,containers`).
- Erreurs : RFC 9457 (Problem Details) — `{type, title, status, detail, errors[]}`.
- Idempotence : header `Idempotency-Key` sur les POST critiques (création facture, dossier).
- OpenAPI 3.1 généré depuis le code (attributs PHP), publié via Swagger UI interne ; le CI échoue si le spec diverge du code.

---

## 11. Sécurité applicative (rappel des choix, détail Étape 13)

| Sujet | Choix |
|---|---|
| Auth API | **Laravel Sanctum** (tokens opaques révocables) — retenu vs JWT : révocation immédiate, rotation simple, pas de gestion de clés de signature ; sessions SPA par cookies httpOnly + CSRF |
| MFA | TOTP (RFC 6238) obligatoire activable par tenant ; codes de récupération |
| SSO | Option Microsoft 365 / Google Workspace (OIDC) — architecture prête, implémentation phase 2 |
| RBAC | Permissions atomiques par module (Étape 1), gate central, vérification en Application layer (pas seulement HTTP) |
| Mots de passe | Argon2id, politique 12+ caractères (spec sécurité) |
| Documents | URLs S3 signées temporaires exclusivement ; bucket privé |
| Rate limiting | Par utilisateur, par tenant, par IP ; strict sur `/public/tracking` |
| Audit | Module Audit : écoute des événements de domaine + observer global ; table immuable (append-only, pas d'UPDATE/DELETE) |
| Secrets | Variables d'environnement + chiffrement applicatif (credentials Odoo, clés connecteurs) via `encrypted` casts ; K8s : secrets managés |

---

## 12. Recherche, cache, stockage

**Meilisearch** (retenu vs Elasticsearch : léger, latence < 50 ms, typo-tolerance native, ops minimal) :
- Un index par type d'entité (`shipments`, `clients`, `containers`, `invoices`, `documents`), champ filtrable `tenant_id` **imposé à chaque requête** côté backend (le front ne parle jamais à Meilisearch directement).
- Indexation via événements de domaine (queue `default`), réindexation complète par commande artisan.

**Redis** — 4 usages, bases logiques séparées : cache applicatif (référentiels, paramètres tenant), files Horizon, rate limiting, verrous distribués (ex. anti-double-sync Odoo).

**Stockage S3** : buckets par environnement, préfixe par tenant (`tenants/{id}/shipments/{id}/…`), versioning S3 natif activé, lifecycle vers stockage froid pour archives, chiffrement au repos (SSE), antivirus (ClamAV en job) avant mise à disposition.

---

## 13. Observabilité

| Domaine | Outil | Détail |
|---|---|---|
| Logs | Logs structurés JSON → stack centralisée (Loki ou ELK) | `tenant_id`, `user_id`, `request_id` (correlation ID) sur chaque ligne |
| Métriques | Prometheus + Grafana | HTTP (latence, erreurs), files (profondeur, âge), connecteurs (succès/échec par compagnie), sync Odoo |
| Traces | OpenTelemetry (activable) | Requêtes lentes, chaînes job → API externe |
| Erreurs | Sentry (backend + frontend) | Alerting |
| Uptime | Healthchecks `/health` (liveness) et `/ready` (readiness : DB, Redis, S3, Meilisearch) | Sondes K8s + alertes |
| Queues | Laravel Horizon UI (interne) | Supervision fine des files |

---

## 14. Topologie de déploiement

**Conteneurs (Docker Compose dev → Kubernetes prod-ready) :**

```
[ Nginx / Ingress ]
   ├── web        : Next.js (Node)                  × n
   ├── api        : Laravel FPM + Nginx sidecar      × n
   ├── worker-*   : php artisan horizon (par groupe de files) × n
   ├── scheduler  : php artisan schedule:work        × 1 (leader election en K8s)
   ├── postgres   : PostgreSQL 16 (managé en prod recommandé + réplica)
   ├── redis      : Redis 7 (managé en prod recommandé)
   ├── meilisearch
   └── minio      : dev uniquement (prod : S3/objet managé)
```

- **Stateless partout** sauf données (Postgres, Redis, S3, Meilisearch) → scaling horizontal simple, load balancer en tête (session par token, aucune affinité serveur requise).
- 12-factor : config par environnement, images identiques dev→prod, migrations exécutées en job de déploiement.
- Environnements : dev, test, préproduction, production — chacun sa base, ses clés, ses journaux (spec sécurité).
- HA : ≥ 2 réplicas api/web/workers, PostgreSQL avec réplica + failover, backups automatisés chiffrés hors site (RPO 15 min via WAL archiving, RTO 2 h).

---

## 15. Structure du monorepo

```
silaris/
├── apps/
│   ├── api/            # Laravel (modules dans src/Modules/)
│   └── web/            # Next.js
├── packages/
│   ├── api-client/     # client TS généré (OpenAPI)
│   ├── ui/             # design system
│   └── config/         # configs partagées
├── docs/               # ce dossier (étapes, ADR, diagrammes)
├── docker/             # Dockerfiles, compose, k8s manifests
└── .github/workflows/  # CI/CD
```

Un seul repo : cohérence de versions front/back garantie, PR atomiques cross-stack, CI unique. Détail complet à l'Étape 5.

---

## 16. Registre des décisions (ADR synthétique)

| # | Décision | Alternatives rejetées | Motif principal |
|---|---|---|---|
| ADR-01 | Monolithe modulaire | Microservices | Coût distribué injustifié ; modules étanches suffisent ; extraction future possible |
| ADR-02 | Multi-tenant : base partagée + tenant_id + RLS PostgreSQL | Base/schéma par tenant | Économie SaaS, ops simple, RLS = isolation garantie au niveau moteur |
| ADR-03 | CQRS applicatif léger | Event sourcing, bases lecture séparées | Lisibilité, dashboards rapides sans complexité event-store |
| ADR-04 | Sanctum | JWT | Révocation immédiate, simplicité, pas de gestion de clés |
| ADR-05 | Transactional Outbox pour intégrations | Dispatch direct post-commit | Fiabilité : jamais d'événement perdu ni fantôme |
| ADR-06 | Connecteurs = Ports & Adapters + Registry + normalisation DCSA | Code par compagnie dans le domaine | Ajout compagnie sans toucher au cœur ; testabilité (fakes) |
| ADR-07 | Odoo = ACL asynchrone, source de vérité par entité | Sync synchrone bidirectionnelle | Résilience, pas de couplage temps réel, conflits gérables |
| ADR-08 | Meilisearch | Elasticsearch | Suffisant fonctionnellement, ops léger, latence excellente |
| ADR-09 | SSE pour temps réel | WebSockets (Reverb/Pusher) | Flux descendant uniquement ; plus simple ; upgrade possible |
| ADR-10 | Monorepo pnpm/Turborepo | Repos séparés | Types partagés, CI atomique, DX |
| ADR-11 | Files Redis séparées par nature + Horizon | File unique | Isolation des charges, scaling ciblé, SLA différenciés |
| ADR-12 | PHP 8.3+ / Laravel 11, PostgreSQL 16, Next.js 15/React 18+, TypeScript strict | — | Versions LTS/stables à date |

---

## 17. Tâches restantes (étapes suivantes)

- Étape 3 : diagrammes C4 (contexte, conteneurs, composants), ERD, flux métier (séquences booking/tracking/sync Odoo).
- Étape 4 : maquettes UI/UX.
- Étape 5 : structure de dossiers détaillée (squelette réel du monorepo).
- Étapes 6–8 : schéma PostgreSQL complet, migrations, seeders.
- Points ouverts Q2, Q6, Q7 (Étape 1) toujours ouverts — défauts appliqués.

---

*Fin de l'Étape 2. En attente de validation avant l'Étape 3 — Diagrammes (C4, ERD, flux métier).*
