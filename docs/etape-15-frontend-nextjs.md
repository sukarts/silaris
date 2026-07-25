# Étape 15 — Frontend Next.js

**Projet :** SILARIS · **Statut :** Exécuté et vérifié (navigateur, E2E réel) · **Prérequis :** Étape 14 validée

---

## 1. Livrables

### Monorepo activé
- Racine : `package.json` + `turbo.json` + workspace pnpm (`allowBuilds` géré), scripts `api:openapi` / `api:client`.
- `packages/config` : tsconfig strict partagé.
- `packages/api-client` : **client typé généré** — spec OpenAPI 3.1 exportée depuis Scramble (78 endpoints) → `openapi-typescript` (5 282 lignes de types) + `openapi-fetch` ; middleware token Bearer + gestion 401 (redirection login) ; type `ProblemDetails` RFC 9457.

### Application `apps/web` (Next 15, React 19, Tailwind v4)
| Élément | Détail |
|---|---|
| Design system | Tokens Étape 4 en CSS variables (clair/sombre via `prefers-color-scheme`) mappés dans `@theme` Tailwind v4 ; fonts **Instrument Sans + IBM Plex Mono** auto-hébergées via `next/font` |
| `stores/auth.ts` | Zustand persisté (token, user, permissions) + hook `useCan()` — garde RBAC UI |
| `lib/api.ts` | Client configuré + `rawApi` (wrapper transitoire documenté tant que la spec ne décrit pas tous les query params) + `problemMessage()` |
| Login | Flux 2 étapes : identifiants → **écran MFA** (TOTP/récupération) ; erreurs RFC 9457 affichées |
| `AppShell` | Sidebar marine (design Étape 4), **navigation filtrée par permissions**, topbar recherche + user + déconnexion, garde d'auth avec attente d'hydratation du store |
| Dashboard | KPIs live calculés depuis l'API (dossiers actifs, conteneurs, retards, docs manquants) |
| Dossiers | Table read model : référence mono, client, mode, trajet, StatusPill, ETA, docs, agent |

## 2. Vérifications E2E (navigateur réel)
- Login `admin@demo.silaris.app` → dashboard : **KPIs réels** (5 dossiers, 2 conteneurs, 1 retard).
- Navigation `/shipments` : 5 dossiers live (références, trajets UN/LOCODE, statuts).
- Session persistante après rechargement complet (hydratation zustand attendue avant garde).
- Sidebar admin complète (115 permissions) — se filtrera automatiquement pour les autres rôles.
- `next build` production : 0 erreur TS strict, pages statiques.

## 3. Incidents corrigés
1. Double préfixe `/v1` (baseUrl + chemins spec) → 404 ; baseUrl = `/api`.
2. Course d'hydratation zustand → redirection login à tort ; garde asynchrone.
3. `useAuth.persist` indisponible en SSR → accès en effet client uniquement.
4. Casts `as never` incompatibles openapi-fetch → wrapper `rawApi` transitoire (les appels typés prendront le relais quand Scramble décrira les query params — dette notée).

## 4. Reste
- Étape 16 : tableau de bord complet (graphiques, alertes, widgets).
- Étape 17 : tous les écrans CRUD (détail dossier avec stepper/timeline, CRM, cotations, facturation, admin).
- Étape 18 : portail client + page publique de suivi.
- Annotations Scramble sur les query params → suppression de `rawApi`.

---

*Fin de l'Étape 15. En attente de validation avant l'Étape 16 — Tableau de bord.*
