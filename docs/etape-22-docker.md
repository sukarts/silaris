# Étape 22 — Docker

**Projet :** SILARIS · **Statut :** Exécuté et vérifié · **Prérequis :** Étape 21 validée

---

## 1. Livrables

### Développement — `docker/dev/docker-compose.yml`
| Service | Port(s) | Rôle |
|---|---|---|
| postgres:16 | 5433 | Base (volume persistant, healthcheck) |
| redis:7 | 6379 | Cache + files + rate limiting + verrous (AOF activé) |
| meilisearch v1.15 | 7700 | Recherche (master key dev) |
| minio + init | 9000/9001 | Stockage S3 (bucket `silaris-documents` auto-créé) |
| mailpit | 1025/8025 | SMTP dev + UI de consultation des emails |

Mode hybride assumé : API via Herd et front via pnpm en natif (DX rapide), infra conteneurisée. `make up / fresh / api / web / worker / test / e2e / openapi`.

### Production — `docker/prod/`
- **`Dockerfile.api` multi-stage** : composer (deps sans dev) → `php:8.3-fpm-alpine` + extensions compilées (pdo_pgsql, gd, intl, zip, bcmath, opcache, pcntl) + **opcache production** (validate_timestamps=0) → targets `runtime` / `worker` (Horizon) / `scheduler` — une seule image, trois rôles.
- **`Dockerfile.web` multi-stage** : pnpm workspace → build Next standalone → `node:22-alpine` non-root, ~150 Mo.
- `nginx-api.conf` : sidecar devant php-fpm (headers sécurité, body 30 Mo).

### Kubernetes — `docker/k8s/` (base + overlays kustomize)
- Deployments : api (php-fpm + sidecar nginx + **initContainer migrate**, probes sur `/health` et `/ready`), web, **worker Horizon** (preStop `horizon:terminate`, grace 60 s — pas de job tué), **scheduler replicas=1 Recreate**.
- Services, Ingress TLS (cert-manager), **HPA** api (2→8, CPU 70 %) et worker (2→6).
- ConfigMap générée ; secrets attendus documentés, jamais committés. Overlays staging/production prêts.

## 2. Bascule dev opérée et vérifiée
1. Postgres autonome supprimé → compose complet démarré (5 services healthy).
2. **Reproductibilité prouvée** : `migrate:fresh` (20 ✓) + 10 seeders + tenant démo — environnement complet reconstruit from scratch.
3. `.env` basculé : `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis` (predis), Meilisearch, MinIO (`DOCUMENTS_DISK=s3`), Mailpit.
4. Vérifications :
   - Cache Redis ✓ ; **file Redis prouvée** (job réel `odoo` : longueur 1 → `queue:work --once` → 0).
   - Meilisearch `{"status":"available"}` ✓.
   - **MinIO E2E** : upload document via API → objet `tenants/{id}/documents/{id}/v1/…` dans le bucket → téléchargement via URL signée restitue le contenu ✓.
5. **Image API production construite et validée** : PHP 8.3.32, 6/6 extensions (pdo_pgsql, gd, intl, zip, bcmath, pcntl), artisan boote, **416 Mo** (1,04 Go avant optimisation `.build-deps`). Bug corrigé : les libs runtime de gd (libpng/freetype/jpeg) étaient supprimées avec les paquets -dev — pattern `--virtual .build-deps` appliqué.
6. **Persistance validée en conditions réelles** : redémarrage machine pendant l'étape → stack relancée, données intactes (volumes).

## 3. Décisions
1. Dev hybride (natif + infra Docker) plutôt que tout-conteneur : itération PHP/Next sans rebuild ; le tout-conteneur reste possible (Dockerfiles prod utilisables en compose).
2. Une image API, trois targets (fpm/worker/scheduler) — un seul artefact à scanner/promouvoir.
3. initContainer de migration : le déploiement échoue avant de servir si la migration échoue.
4. Scheduler mono-replica Recreate — `schedule:work` non multi-instance (le `withoutOverlapping` protège en plus).

---

*Fin de l'Étape 22. En attente de validation avant l'Étape 23 — CI/CD.*
