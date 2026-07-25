# Onboarding développeur

Objectif : machine vierge → environnement complet en ~20 min.

## 1. Outils

| Outil | Version | Installation macOS |
|---|---|---|
| Docker Desktop | récent | docker.com |
| PHP + Composer | 8.3+ | [Laravel Herd](https://herd.laravel.com) (inclut les deux) |
| Node | 22 LTS | `nvm install 22 && nvm alias default 22` (⚠ pas node 16 : corepack casse) |
| pnpm | 11 | fourni par corepack (`corepack enable`) |

## 2. Setup

```bash
git clone <repo> silaris && cd silaris
make up                                   # infra Docker (5 services)
cd apps/api
composer install
cp .env.example .env && php artisan key:generate
cd ../..
make fresh                                # base + référentiels + démo
pnpm install
pnpm --filter @silaris/api-client generate
```

`.env` dev déjà orienté services compose : Postgres `127.0.0.1:5433`, Redis, Meilisearch `:7700`, MinIO `:9000` (`DOCUMENTS_DISK=s3`), Mailpit SMTP `:1025` (UI `:8025`).

## 3. Lancer

Trois terminaux : `make api` (8088) · `make web` (3000) · `make worker` (files). Scheduler local si besoin : `php artisan schedule:work`.

## 4. Vérifier

```bash
curl localhost:8088/api/v1/health         # {"status":"ok"}
make test                                 # 34 passed
```

Puis http://localhost:3000 → `admin@demo.silaris.app` / `password`.

## 5. Pièges connus

| Symptôme | Cause / remède |
|---|---|
| `URL.canParse is not a function` | node 16 actif → `nvm use 22` |
| 403 sur tout en local | token portail utilisé sur route interne (ou l'inverse) — deux populations étanches |
| `unrecognized configuration parameter "app.tenant_id"` | requête hors contexte tenant : normal, la RLS fait son travail — passez par l'API ou `TenantContext::set()` |
| Tests qui voient l'utilisateur précédent | guard Sanctum caché entre requêtes d'un même test → `freshAuth()` (cf. tests/Fixtures.php) |
| Jamais de `next build` pendant `next dev` | corrompt `.next` du serveur dev |
