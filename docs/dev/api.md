# Consommer l'API

Base : `/api/v1` · Spec vivante : `make openapi` → `packages/api-client/openapi.json` · UI Swagger dev : `http://localhost:8088/docs/api`.

## Espaces
| Préfixe | Auth | Public visé |
|---|---|---|
| `/api/v1/auth/*` | — (throttle login) | Connexion interne, MFA, reset |
| `/api/v1/*` | Bearer (token utilisateur) + permission | Application interne |
| `/api/v1/portal/*` | Bearer (token portail) | Espace client |
| `/api/v1/public/*` | — (throttle strict) | Suivi public, téléchargements signés |

Les deux populations de tokens sont étanches (403 croisés).

## Authentification
```
POST /auth/login {email, password}
 → {token, expires_at, user}                    # MFA inactif
 → {mfa_required: true, challenge}              # MFA actif (challenge 5 min)
POST /auth/mfa/verify {challenge, code}         # TOTP ou code de récupération
GET  /auth/me                                   # profil + permissions à plat (RBAC front)
```
`Authorization: Bearer <token>` — expiration 12 h (`SANCTUM_EXPIRATION_MINUTES`).

## Erreurs — RFC 9457 (`application/problem+json`)
```json
{ "type": "https://silaris.app/errors/shipment.invalid_workflow_transition",
  "title": "Règle métier violée", "status": 422,
  "detail": "Transition workflow interdite : transit → closure",
  "error_code": "shipment.invalid_workflow_transition" }
```
Validation : `errors` par champ. Brancher la logique sur `error_code`, jamais sur `detail`.

## Pagination & filtres
Curseur : `?per_page=25&cursor=…` → `{data, next_cursor, prev_cursor}`. Filtres `?filter[status][]=transit&filter[delayed]=1`, tri `?sort=-eta`.

## Rate limits
Login 5/min (IP+email) · interne 120/min/utilisateur · public tracking 20/min + 300/jour par IP. Réponse 429 standard.

## Client TypeScript
```ts
import { createSilarisClient } from "@silaris/api-client";
const api = createSilarisClient({ baseUrl, getToken, onUnauthorized });
const { data, error } = await api.GET("/v1/shipments", { params: { query: { per_page: 50 } } });
```
Client 100 % généré — ne jamais éditer `src/generated/`.
