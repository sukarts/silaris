# Étape 5 — Structure des Dossiers

**Projet :** SILARIS — Plateforme de Gestion de Transit International (TMS)
**Version :** 1.0
**Statut :** En attente de validation
**Prérequis :** Étapes 1–4 validées

---

## 1. Vue d'ensemble du monorepo

```
silaris/
├── apps/
│   ├── api/                    # Backend Laravel 11 (PHP 8.3)
│   └── web/                    # Frontend Next.js 15 (TypeScript)
├── packages/
│   ├── api-client/             # Client TS généré depuis OpenAPI (types + hooks)
│   ├── ui/                     # Design system partagé (Tailwind + Radix)
│   └── config/                 # Configs partagées (eslint, tsconfig, tailwind preset)
├── docker/
│   ├── dev/                    # Docker Compose développement
│   ├── prod/                   # Dockerfiles production multi-stage
│   └── k8s/                    # Manifests Kubernetes (base + overlays kustomize)
├── docs/                       # Documentation projet (étapes, ADR, diagrammes, maquettes)
├── scripts/                    # Scripts outillage (setup, openapi-gen, release)
├── .github/
│   └── workflows/              # CI/CD GitHub Actions
├── pnpm-workspace.yaml
├── turbo.json                  # Orchestration builds/tests (Turborepo)
├── package.json                # Racine (scripts globaux)
├── .editorconfig
├── .gitignore
├── Makefile                    # Raccourcis dev : make up, make test, make fresh…
└── README.md
```

**Outillage racine :** pnpm workspaces (JS), Turborepo (cache de build), Makefile (commandes transverses PHP+JS).

---

## 2. Backend — `apps/api/` (Laravel 11)

```
apps/api/
├── app/                        # Couche framework MINIMALE (bootstrap uniquement)
│   ├── Console/                # Kernel console
│   ├── Exceptions/             # Handler global (rendu RFC 9457)
│   ├── Http/
│   │   ├── Kernel.php
│   │   └── Middleware/         # Middlewares globaux : TenantContext, SetLocale,
│   │                           #   ForceJson, AuditContext, RateLimitByTenant
│   └── Providers/              # AppServiceProvider, ModuleServiceProvider (auto-découverte)
│
├── src/Modules/                # ★ TOUT LE MÉTIER VIT ICI (18 modules)
│   ├── Shared/                 # Noyau partagé entre modules
│   │   ├── Domain/             #   ValueObjects communs : Money, Currency, Locode,
│   │   │                       #   Weight, Volume, Reference, TenantId, AggregateRoot,
│   │   │                       #   DomainEvent, Bus (CommandBus/QueryBus interfaces)
│   │   ├── Application/        #   Behaviors : TransactionPipeline, AuthzPipeline
│   │   └── Infrastructure/     #   Outbox, EventBusLaravel, RlsConnection,
│   │                           #   SequenceGenerator, SignedUrlService
│   │
│   ├── Tenancy/                # tenants, sociétés, agences, paramètres
│   ├── Identity/               # users, auth, MFA, sessions, RBAC, API keys
│   ├── Referential/            # pays, ports, aéroports, devises, incoterms, carriers
│   ├── Audit/                  # journal d'audit append-only
│   ├── Crm/                    # parties, contacts, opportunités, réclamations
│   ├── Shipment/               # dossiers, workflow, timeline, tâches, segments
│   ├── Ocean/                  # bookings, conteneurs, BL, navires, voyages, consolidations
│   ├── Air/                    # MAWB/HAWB, vols
│   ├── Road/                   # camions, chauffeurs, missions, POD
│   ├── Tracking/               # subscriptions, événements DCSA
│   ├── Pricing/                # grilles tarifaires, moteur de cotation, devis
│   ├── Billing/                # factures, avoirs, séquences, PDF
│   ├── Documents/              # GED, versions, checklist
│   ├── Notifications/          # canaux, préférences, templates, envois
│   ├── Search/                 # indexation Meilisearch
│   ├── Reporting/              # dashboards, KPIs, rapports, exports
│   ├── OdooSync/               # ACL Odoo : mapping, translators, sync, conflits
│   └── CarrierConnect/         # ACL compagnies : 9 connecteurs, registry, normalizer
│
├── bootstrap/
├── config/                     # + config/silaris.php (paramètres plateforme)
├── database/
│   ├── migrations/             # Migrations GLOBALES ordonnées (préfixe numéroté par module)
│   └── seeders/                # Référentiels (ports, incoterms…) + démo
├── public/
├── resources/
│   ├── lang/fr, en/            # Traductions backend (notifications, PDF)
│   └── views/pdf/              # Gabarits Blade → PDF (factures, devis, dossier)
├── routes/
│   ├── api.php                 # Agrège les routes de chaque module (auto-découverte)
│   └── console.php
├── storage/
├── tests/
│   ├── Architecture/           # Tests Pest Arch : règles de dépendance §4 Étape 2
│   ├── Unit/<Module>/          # Domain pur, sans DB
│   ├── Feature/<Module>/       # HTTP + DB (RLS active)
│   └── Integration/            # Connecteurs (mocks HTTP), OdooSync, files
├── composer.json               # PSR-4 : "Silaris\\Modules\\": "src/Modules/"
├── phpstan.neon                # Niveau 8
├── pint.json                   # Code style
└── .env.example
```

### 2.1 Anatomie complète d'un module (référence : Shipment)

```
src/Modules/Shipment/
├── Domain/
│   ├── Model/
│   │   ├── Shipment.php                  # Agrégat racine
│   │   ├── TransportSegment.php          # Entité enfant
│   │   ├── ShipmentTask.php
│   │   └── Enum/                         # Direction, TransportMode, Priority (enums PHP)
│   ├── ValueObject/
│   │   ├── ShipmentReference.php
│   │   └── Schedule.php                  # ETD/ETA/ATD/ATA + règles de retard
│   ├── Event/
│   │   ├── ShipmentCreated.php
│   │   ├── WorkflowStepAdvanced.php
│   │   ├── DelayDetected.php
│   │   └── ShipmentClosed.php
│   ├── Repository/
│   │   └── ShipmentRepository.php        # Interface (port)
│   ├── Service/
│   │   └── WorkflowEngine.php            # Interprète workflow_definitions du tenant
│   └── Exception/
│       ├── InvalidWorkflowTransition.php
│       └── ShipmentCannotBeClosed.php
│
├── Application/
│   ├── Command/
│   │   ├── CreateShipment/
│   │   │   ├── CreateShipmentCommand.php     # DTO immuable
│   │   │   └── CreateShipmentHandler.php
│   │   ├── AdvanceWorkflowStep/
│   │   └── CloseShipment/
│   ├── Query/
│   │   ├── GetShipmentDetails/
│   │   ├── GetShipmentTimeline/
│   │   └── ListShipments/                    # read model + filtres + pagination curseur
│   ├── Dto/
│   └── Port/
│       └── ShipmentNumberGenerator.php       # → implémenté par Shared SequenceGenerator
│
├── Infrastructure/
│   ├── Persistence/
│   │   ├── Model/                            # Modèles Eloquent (ShipmentModel…)
│   │   ├── EloquentShipmentRepository.php
│   │   └── Mapper/                           # Eloquent ↔ Domain
│   ├── Projection/
│   │   └── ShipmentListProjection.php        # Requêtes de lecture optimisées
│   └── Provider/
│       └── ShipmentServiceProvider.php       # Bindings, routes, migrations du module
│
└── Interface/
    ├── Http/
    │   ├── Controller/
    │   ├── Request/                          # FormRequests (validation)
    │   ├── Resource/                         # API Resources (sérialisation)
    │   └── routes.php                        # Routes du module (préfixe /api/v1/shipments)
    ├── Console/
    └── Listener/
        └── OnTrackingEventReceived.php       # Réagit au module Tracking
```

**Conventions backend :**
- Namespace : `Silaris\Modules\<Module>\<Couche>\...`
- 1 commande = 1 dossier (Command + Handler côte à côte).
- Modèles Eloquent suffixés `Model`, jamais exposés hors Infrastructure.
- Chaque module enregistre routes + bindings via son ServiceProvider ; auto-découverte par `ModuleServiceProvider` racine.
- Migrations globales dans `database/migrations/` avec préfixe module dans le nom (`2026_08_01_000100_tenancy_create_tenants.php`) — ordre inter-modules maîtrisé (FK).

---

## 3. Frontend — `apps/web/` (Next.js 15)

```
apps/web/
├── src/
│   ├── app/
│   │   ├── layout.tsx                   # Root : fonts, ThemeProvider, QueryProvider
│   │   ├── globals.css                  # Tokens CSS (Étape 4) + Tailwind
│   │   ├── (auth)/
│   │   │   ├── login/page.tsx
│   │   │   ├── mfa/page.tsx
│   │   │   └── reset-password/page.tsx
│   │   ├── (app)/                       # Layout : Sidebar + Topbar (auth requise)
│   │   │   ├── layout.tsx
│   │   │   ├── dashboard/page.tsx
│   │   │   ├── shipments/
│   │   │   │   ├── page.tsx             # Liste (filtres, vues)
│   │   │   │   ├── new/page.tsx         # Wizard création
│   │   │   │   └── [id]/
│   │   │   │       ├── layout.tsx       # Header dossier + stepper + tabs
│   │   │   │       ├── page.tsx         # Tab aperçu
│   │   │   │       ├── containers/ bl/ documents/ billing/
│   │   │   │       └── tasks/ communication/ audit/
│   │   │   ├── bookings/  containers/  air/  road/
│   │   │   ├── crm/       quotes/      billing/
│   │   │   ├── documents/ reports/
│   │   │   └── admin/
│   │   │       ├── users/ roles/ companies/ branches/
│   │   │       ├── referentials/ workflows/ notifications/
│   │   │       └── audit/ integrations/
│   │   ├── (portal)/                    # Layout portail client
│   │   │   └── portal/
│   │   │       ├── page.tsx             # Accueil
│   │   │       ├── shipments/[id]/  documents/  invoices/  quotes/
│   │   │       └── preferences/
│   │   ├── track/
│   │   │   └── [reference]/page.tsx     # Suivi public (SSR)
│   │   └── api/                         # Route handlers minimes (SSE proxy si besoin)
│   │
│   ├── features/                        # Logique par domaine (miroir modules backend)
│   │   ├── shipments/
│   │   │   ├── components/              # ShipmentTable, WorkflowStepper, Timeline…
│   │   │   ├── hooks/                   # useShipments, useAdvanceStep…
│   │   │   └── schemas.ts               # Zod (généré + surcharges UI)
│   │   ├── crm/  quotes/  billing/  documents/  tracking/
│   │   ├── auth/  admin/  dashboard/  portal/
│   │   └── notifications/               # Cloche, SSE, préférences
│   │
│   ├── components/                      # Transverse : AppShell, GlobalSearch (⌘K),
│   │                                    #   DataTable, FilterBar, KpiCard, StatusPill…
│   ├── lib/
│   │   ├── api.ts                       # Instance client (auth, erreurs, tenant header)
│   │   ├── auth.ts                      # Session, guards, permissions RBAC côté UI
│   │   ├── i18n/                        # fr/, en/ (messages)
│   │   ├── sse.ts                       # Client Server-Sent Events
│   │   └── utils.ts
│   ├── stores/                          # Zustand : ui.ts (sidebar, thème), prefs.ts
│   └── middleware.ts                    # Garde routes (auth, rôles, tenant)
├── public/fonts/                        # Instrument Sans, IBM Plex Mono (woff2 auto-hébergées)
├── e2e/                                 # Tests Playwright
├── next.config.ts
├── tailwind.config.ts                   # Preset depuis packages/config
└── package.json
```

**Conventions frontend :**
- `features/<domaine>` = miroir des modules backend ; un composant utilisé par 2+ features remonte dans `components/`.
- Aucune requête HTTP hors `packages/api-client` (hooks générés) — pas de fetch sauvage.
- Nommage : composants PascalCase, hooks `useX`, fichiers kebab-case pour les routes.
- Permissions : hook `useCan('shipments.create')` alimenté par le token — la sidebar et les actions se filtrent par RBAC.

---

## 4. Packages partagés

```
packages/
├── api-client/
│   ├── src/
│   │   ├── generated/          # ★ NE JAMAIS ÉDITER — openapi-ts depuis apps/api
│   │   │   ├── types.ts        # Tous les types API
│   │   │   ├── zod/            # Schémas Zod générés
│   │   │   └── hooks/          # Hooks TanStack Query par endpoint
│   │   └── index.ts
│   └── package.json            # script: generate (lit openapi.json de l'API)
├── ui/
│   ├── src/
│   │   ├── tokens.css          # Design tokens Étape 4 (source de vérité)
│   │   ├── primitives/         # Button, Input, Select, Dialog, Sheet, Tabs… (Radix)
│   │   ├── data/               # DataTable, Pill, KpiCard, Timeline, Stepper
│   │   └── index.ts
│   └── package.json
└── config/
    ├── eslint/  typescript/  tailwind/   # preset partagé (tokens → theme Tailwind)
    └── package.json
```

---

## 5. Docker, K8s, CI

```
docker/
├── dev/
│   ├── docker-compose.yml      # api, web, postgres, redis, meilisearch, minio,
│   │                           #   mailpit (emails dev), worker, scheduler
│   └── Dockerfile.api-dev      # PHP + Xdebug
├── prod/
│   ├── Dockerfile.api          # Multi-stage : composer → php-fpm + nginx (distroless-like)
│   ├── Dockerfile.web          # Multi-stage : pnpm build → node standalone
│   └── Dockerfile.worker       # Même image api, entrypoint horizon
└── k8s/
    ├── base/                   # Deployments, Services, HPA, ConfigMaps, CronJob scheduler
    └── overlays/               # staging/, production/ (kustomize)

.github/workflows/
├── ci.yml                      # lint + phpstan + tests (PHP), lint + tsc + tests (TS), e2e
├── build.yml                   # Images Docker → registry (tags SHA + semver)
└── deploy.yml                  # Déploiement par environnement + rollback
```

---

## 6. Règles transverses

1. **Un seul sens de dépendance JS** : `apps/web` → `packages/*`. Jamais l'inverse.
2. **Le contrat OpenAPI est un artefact du backend** : `apps/api` génère `openapi.json` ; `packages/api-client` le consomme ; le CI régénère et échoue si le client committé diverge.
3. **Pas de code partagé PHP↔TS** : le partage passe exclusivement par le contrat OpenAPI.
4. **`docs/` versionné** avec le code — les ADR évoluent par PR.
5. **Secrets jamais dans le repo** : `.env.example` exhaustifs, valeurs réelles par environnement (K8s secrets / vault).

---

## 7. Décisions prises

1. Métier backend intégralement sous `src/Modules/` (PSR-4 `Silaris\Modules\`) — `app/` réduit au bootstrap Laravel. Le framework est un détail d'infrastructure.
2. Module `Shared` minimal : value objects communs, bus, outbox, séquences — pas de fourre-tout (revue à chaque ajout).
3. Migrations centralisées ordonnées (préfixe module dans le nom) plutôt que par module — les FK inter-modules imposent un ordre global maîtrisé.
4. 1 commande = 1 dossier (Command + Handler) — navigation prévisible.
5. `features/` frontend en miroir des modules backend — vocabulaire unique dans tout le repo.
6. Client API 100 % généré, jamais édité — la dérive de contrat est structurellement impossible.
7. Tests d'architecture (Pest Arch) committés dès le squelette — les règles de dépendance de l'Étape 2 sont exécutables, pas déclaratives.

## 8. Tâches restantes

- Étape 6 : bootstrap Laravel réel + schéma base de données complet (dictionnaire, index, contraintes).
- Étape 7–8 : migrations + seeders dans cette structure.
- Initialisation git du repo (recommandée maintenant — dis-moi si je procède).

---

*Fin de l'Étape 5. En attente de validation avant l'Étape 6 — Base de données.*
