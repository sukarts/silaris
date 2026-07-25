# Étape 28 — Plan de Maintenance

**Statut :** Livré — clôt le cycle des 28 étapes.

---

## 1. Dette technique — inventaire honnête (issue du build, priorisée)

### P1 — avant mise en production commerciale
| Dette | Origine | Effort |
|---|---|---|
| **Écriture automatique du journal d'audit** : la table `audit_logs` (partitionnée, immuable) existe, l'API de lecture aussi — l'observer global qui y écrit chaque mutation reste à brancher | Étape 6/12 | 2-3 j |
| **Envoi réel des notifications** sur événements (départ/arrivée/retard/facture) : templates + préférences + deliveries en base, `DelayDetected` publié dans l'outbox — le worker outbox→canaux (email d'abord, Twilio SMS/WhatsApp ensuite) reste à écrire ; l'OTP de retrait prouve déjà la chaîne email | Étape 15 backlog | 3-5 j |
| Emails transactionnels reset/invitation (aujourd'hui : token loggé) | Étape 13 | 1 j |
| Recherche globale ⌘K : Meilisearch opérationnel, indexation Scout à câbler sur les modèles + endpoint + palette front | Étape 17 | 2-3 j |
| Onboarding tenant (procédure super-admin scriptée → écran plateforme) | Étape 26 | 2 j |

### P2 — confort et complétude UI
UI passe 2 : bookings/BL/conteneurs dédiés, documents (upload UI), admin (users/roles/workflows), moniteur Odoo, préférences notifications portail, formulaire devis persistant + **PDF devis/factures** (dompdf prêt — gabarits à faire), widgets dashboard configurables (table prête). Suppression de `rawApi` quand Scramble décrira les query params (annotations à poser).

### P3 — dette outillée
Baseline Larastan (110 propriétés magiques → annotations `@property`, objectif -20/mois) ; seuil de couverture en CI (pcov) ; scan images Trivy + SBOM ; E2E portail + création dossier.

### Différés assumés (Étape 1 §2.3)
OCR documents, GPS routier, connecteurs aériens (contrats prêts), PWA mobile, IA. Webhooks Odoo entrants (le pull horaire couvre), webhooks sortants tenants (outbox prête).

## 2. Politique de dépendances
- **Hebdo** : Dependabot/Renovate groupés (patch/minor auto si CI verte ; major = PR dédiée revue).
- **Sécurité** : advisories Composer bloquantes en CI (déjà vécu : Laravel 11→12 imposé au build) ; `pnpm audit` en CI ; correctifs sécurité déployés sous 72 h (P1 : 24 h).
- **Cadence framework** : Laravel majeur sous 3 mois après release ; Next majeur sous 6 mois ; PHP suivant le cycle officiel (8.3 → 8.4 après 1 mois de stabilité CI) ; PostgreSQL majeur annuel avec répétition sur staging.

## 3. Cycle de release
`main` toujours déployable · staging continu · production 1-2 releases/semaine (approbation) · hotfix : branche depuis main, fast-track CI, production le jour même · versions `vX.Y.Z` taguées (build.yml), changelog par release.

## 4. Support (modèle SLA proposé aux tenants)
| Niveau | Exemple | Prise en charge | Résolution cible |
|---|---|---|---|
| P1 — plateforme indisponible | API down, connexion impossible | 30 min (h. ouvrées étendues) | 4 h |
| P2 — fonction majeure dégradée | sync Odoo en échec massif, tracking global HS | 2 h | 1 j ouvré |
| P3 — anomalie contournable | écran défectueux, mapping statut manquant | 1 j | release suivante |
| P4 — question/demande | usage, évolution | 2 j | backlog priorisé |

Support opéré avec les outils livrés : `/v1/odoo/status`, `carrier_exchange_logs`, `sessions_log`, audit, Horizon.

## 5. Gouvernance du code après livraison
Les 28 documents d'étapes + ADR restent la référence ; toute décision structurante = nouvel ADR dans `docs/`. Tests d'architecture = gardiens permanents des frontières. Toute nouvelle règle métier suit le pattern éprouvé : exception typée `DomainException` + `error_code` + test.

---

## Clôture du cycle — état final

**28/28 étapes livrées.** Produit fonctionnel démontré de bout en bout : multi-tenant RLS testée, workflow configurable actif, tracking DCSA idempotent, colis LCL étiquetés QR avec remise à double contrôle (règlement + OTP), portail client cloisonné, suivi public, intégration Odoo résiliente testée, RBAC 11 rôles vérifié, 34 tests + 6 tests d'architecture + 3 E2E, CI/CD complet, images production, K8s, 7 guides utilisateur, 4 guides développeur, 2 guides ops.
