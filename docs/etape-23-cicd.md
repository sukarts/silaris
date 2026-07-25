# Étape 23 — CI/CD

**Projet :** SILARIS · **Statut :** Exécuté — outils qualité verts en local, workflows validés · **Prérequis :** Étape 22 validée

---

## 1. Mise en conformité préalable (la CI n'impose que ce qui passe déjà)
| Outil | Résultat local |
|---|---|
| **Pint** (preset laravel + strict_types + imports triés) | ~115 fichiers normalisés, `--test` propre |
| **Larastan niveau 5** | **0 erreur** après : 5 vrais correctifs (const morte, condition toujours vraie, type de retour `latestVersion`, 2 nullsafe inutiles) + baseline de 110 occurrences (propriétés magiques Eloquent dans les Resources — dette documentée, à réduire par annotations `@property`) |
| Suite tests | 34 passed / 127 assertions — inchangée après normalisation |
| `tsc --noEmit` | 0 erreur |

## 2. Workflows (`.github/workflows/`, YAML validés)

### `ci.yml` — push main + toutes PR (concurrency par ref)
- **backend** : PHP 8.3 + services Postgres 16/Redis · cache composer · `pint --test` · `phpstan` · `artisan test` (unitaires + architecture + feature sur base de service).
- **frontend** : pnpm + cache · `tsc --noEmit` · `next build` · **garde anti-dérive du client API** : régénère depuis la spec et échoue si `packages/api-client/src/generated/` diffère du commit.
- **e2e** (après backend+frontend) : migrate + seeds + démo · API `artisan serve` · front `next dev` · `wait-on` santé · **Playwright chromium** · rapport uploadé en artefact si échec.

### `build.yml` — main + tags `v*`
Matrice api/web → **GHCR**, tags `sha` + `tag` + `latest` (main), cache buildx `type=gha` par image.

### `deploy.yml`
- **staging** : automatique après build vert — kustomize overlay, image épinglée au SHA, `rollout status` bloquant.
- **production** : `workflow_dispatch` + **environnement GitHub protégé (approbation manuelle)**, kubeconfig secret dédié.

## 3. Décisions
1. La qualité bloque la PR ; le déploiement ne part que d'un build vert de main.
2. Dérive spec/client structurellement impossible (promesse Étape 5 tenue en CI).
3. Baseline Larastan committée : le stock est gelé, **toute nouvelle erreur casse la CI** ; réduction du stock = tâche de fond.
4. Secrets uniquement via environnements GitHub (KUBECONFIG staging/prod séparés) — rien dans le repo.

## 4. Incident environnement
Reboot machine → nvm retombé sur node 16 (`URL.canParse` manquant via corepack). Contournement session : PATH node 22. À figer côté user : `nvm alias default 22`.

## 5. Reste
- Seuil de couverture (pest --coverage) une fois xdebug/pcov ajouté à l'image CI.
- Scan images (Trivy) + SBOM dans build.yml — backlog sécurité.
- Exécution réelle des workflows = premier push GitHub (repo distant pas encore créé).

---

*Fin de l'Étape 23. En attente de validation avant l'Étape 24 — Documentation développeur.*
