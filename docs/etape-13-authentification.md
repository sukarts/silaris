# Étape 13 — Authentification

**Projet :** SILARIS · **Statut :** Exécuté et vérifié (serveur + curl) · **Prérequis :** Étape 12 validée
**Routes :** 102 sous `/api/v1` (dont 13 auth)

---

## 1. Livrables

### Authentification interne (`/v1/auth/*`)
| Endpoint | Comportement |
|---|---|
| `POST /auth/login` | Email+mot de passe. MFA actif → `{mfa_required, challenge}` (challenge 5 min en cache) ; sinon token Sanctum (expiration 12 h configurable) |
| `POST /auth/mfa/verify` | Challenge + code TOTP (fenêtre ±1) **ou code de récupération** (consommé à usage unique) |
| `GET /auth/me` | Profil + rôles + **permissions effectives à plat** (pilote le RBAC frontend) + agences |
| `POST /auth/logout` / `logout-all` | Révocation token courant / tous appareils |
| `POST /auth/change-password` | Vérif mot de passe actuel + politique + révocation des autres sessions |
| `POST /auth/forgot-password` | Réponse constante (anti-énumération), token hashé en base, 60 min |
| `POST /auth/reset-password` | Vérif token + politique + révocation totale des tokens |
| `POST /auth/mfa/enable` → `confirm` → `disable` | Secret 32 chars + URI otpauth (QR côté front) ; confirm exige 1er code valide et retourne **8 codes de récupération une seule fois** (stockés hashés) ; disable = mot de passe + TOTP |

### Portail client (`/v1/portal/auth/*`)
Login / me / logout — guard Sanctum sur `portal_accounts`, token à ability `portal`.

### Sécurité transverse
- **Argon2id** (`HASH_DRIVER=argon2id`) — hasher strict, comptes démo re-hashés.
- **Politique mots de passe** (spec sécurité) : 12+ car., majuscule, minuscule, chiffre, spécial — `PasswordPolicy::rule()` réutilisée partout.
- **Séparation des populations** : middlewares `internal` / `portal-user` — un token portail sur une route interne → **403** (testé), et inversement.
- **Rate limiting login** : 5/min par IP+email, 20/min par IP — anti brute-force (testé : 6e tentative → 429).
- **Journal de sessions** : `sessions_log` (login, login_failed, mfa_failed, logout, token_revoked) avec IP + user-agent.
- `mfa_secret` chiffré au repos (cast `encrypted`), `password_hash`/`mfa_*` jamais sérialisés.
- ResolveTenant : le tenant vient **exclusivement** de l'utilisateur authentifié (X-Tenant-Slug ne subsiste qu'en local/testing, désormais inutile pour les routes protégées).
- Toutes les routes internes : `auth:sanctum + internal + tenant + throttle:api`.

## 2. Tests exécutés

| Test | Résultat |
|---|---|
| Login admin → token, `/auth/me` → rôle admin, 115 permissions | ✓ |
| Route interne avec token seul (tenant auto) | ✓ 3 dossiers |
| Sans token → 401 ; token portail sur interne → **403** | ✓ |
| Login portail → me → SICOA SARL | ✓ |
| Mot de passe faible → 422 sur `new_password` | ✓ |
| Enrôlement MFA : secret + confirm avec TOTP calculé → 8 recovery codes | ✓ |
| Login → challenge → verify TOTP → token | ✓ |
| Verify avec code de récupération → token ; **réutilisation → 422** | ✓ |
| Brute-force : 5×422 puis **429** | ✓ (a même interféré avec les tests — preuve de fonctionnement) |
| `sessions_log` : logins + mfa_failed tracés | ✓ |

## 3. Incident corrigé
Comptes démo hashés en bcrypt avant activation Argon2id → hasher strict refusait (`This password does not use the Argon2id algorithm`). Re-hash des 8 comptes. Produit neuf : aucun hash legacy n'existera en production.

## 4. Reste
- Envoi réel des emails reset/invitation → module Notifications (Étape 15+).
- SSO Microsoft/Google (OIDC) → prévu architecture, implémentation différée (phase 2).
- Autorisation par permission sur chaque route → Étape 14.

---

*Fin de l'Étape 13. En attente de validation avant l'Étape 14 — Gestion des rôles (RBAC).*
