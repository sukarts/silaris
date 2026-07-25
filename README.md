# SILARIS — Transport Management System

Plateforme SaaS de gestion de transit international pour transitaires, commissionnaires en douane et logisticiens : dossiers maritime/aérien/routier/multimodal, tracking automatique (connecteurs compagnies, standard DCSA), cotations, facturation opérationnelle, portail client, intégration comptable **Odoo**.

> La comptabilité (écritures, TVA, grand livre) vit dans Odoo — jamais ici. SILARIS pousse des documents et rapatrie taxes + statuts de paiement.

## Stack

| Couche | Technologie |
|---|---|
| API | Laravel 12 · PHP 8.3 · Sanctum · Horizon · monolithe modulaire DDD (`apps/api/src/Modules/`) |
| Front | Next.js 15 · React 19 · TypeScript strict · Tailwind v4 · TanStack Query · Zustand |
| Données | PostgreSQL 16 (multi-tenant : `tenant_id` + **RLS**) · Redis 7 · Meilisearch · S3/MinIO |
| Contrat | OpenAPI 3.1 générée (Scramble) → client TS généré (`packages/api-client`) |
| Déploiement | Docker multi-stage · Kubernetes (kustomize) · GitHub Actions |

## Démarrage rapide

Prérequis : Docker Desktop, PHP 8.3 + Composer (Laravel Herd recommandé sur macOS), Node 22 + pnpm.

```bash
make up                          # infra : postgres, redis, meilisearch, minio, mailpit
cd apps/api && composer install && cp .env.example .env && php artisan key:generate
make fresh                       # migrations + référentiels + tenant de démonstration
pnpm install
make api                         # API → http://localhost:8088
make web                         # Front → http://localhost:3000
```

Comptes de démonstration (mot de passe `password`) : `admin@demo.silaris.app`, `agent@…`, `commercial@…`, `comptable@…`, `chauffeur@…` · Portail client : `contact@sicoa-demo.ci` · Suivi public : `/track` (ex. `MSKU8842016`).

## Commandes

```bash
make test        # 34 tests backend (unitaires + architecture + feature)
make e2e         # Playwright
make openapi     # régénère spec + client TS (obligatoire après changement d'API)
make worker      # traite les files redis (odoo, tracking, notifications)
```

## Documentation

| Document | Contenu |
|---|---|
| [docs/dev/onboarding.md](docs/dev/onboarding.md) | Machine vierge → environnement qui tourne |
| [docs/dev/architecture.md](docs/dev/architecture.md) | Modules, frontières, recettes (ajouter un endpoint, un connecteur…) |
| [docs/dev/conventions.md](docs/dev/conventions.md) | Style, nommage, patterns imposés par la CI |
| [docs/dev/api.md](docs/dev/api.md) | Consommer l'API : auth, erreurs, pagination |
| [docs/](docs/) | Conception complète : analyse fonctionnelle, ADR, ERD, 28 étapes |

## Qualité

CI sur chaque PR : Pint · Larastan (0 erreur hors baseline) · tests dont **tests d'architecture** (les frontières DDD sont exécutables) · build front · garde anti-dérive du client API · E2E.

Licence : propriétaire.
