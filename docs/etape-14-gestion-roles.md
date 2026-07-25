# Étape 14 — Gestion des Rôles (RBAC effectif)

**Projet :** SILARIS · **Statut :** Exécuté et vérifié · **Prérequis :** Étape 13 validée

---

## 1. Livrables

| Composant | Rôle |
|---|---|
| `Gate::before` (AppServiceProvider) | Toute vérification `can:<module>.<action>` résolue contre le catalogue de permissions de l'utilisateur ; `null` pour les autres populations (Policies classiques restent possibles) |
| `UserModel::permissionKeys()` | Union des permissions des rôles, **cache 60 s** ; `hasPermission()`, `hasAllBranchAccess()` (super_admin/admin/director) |
| Invalidation de cache | Changement de rôles utilisateur → forget individuel ; modification d'un rôle → forget pour tous ses porteurs |
| `CurrentUser` (Shared, scoped) | Contexte requête : id, permissions, agences affectées, flag toutes-agences — peuplé par `EnsureInternalUser`, consommé par la couche Application sans dépendre du framework d'auth |
| **`can:` sur 100 % des routes internes** | 11 fichiers de routes réécrits — chaque endpoint porte sa permission exacte (`shipments.advance`, `invoices.validate`, `bl.issue`, `pod.create`, `users.reset_mfa`…) |
| **Scope agences** | `ListShipmentsHandler` filtre par agences affectées ; direction/admin voient tout |

## 2. Matrice testée (serveur réel, 4 rôles)

| Endpoint | admin | agent | comptable | chauffeur |
|---|---|---|---|---|
| GET /shipments | 200 | 200 | 200 | **403** |
| POST /shipments | 422* | 422* | **403** | **403** |
| GET /parties | 200 | 200 | 200 | **403** |
| GET /fleet/trucks | 200 | 200 | **403** | 200 |
| GET /admin/users | 200 | **403** | **403** | **403** |
| POST /invoices/{id}/validate | 200 | **403** | **403** | **403** |

\* 422 = permission OK, validation du corps ensuite — l'autorisation passe **avant** la validation.

**Scope agences vérifié** : dossier créé sur l'agence SPY par l'admin → admin liste 5 dossiers, agent (affecté ABJ) en liste 4.

## 3. Décisions
1. Permission = source unique d'autorisation ; les rôles ne sont qu'un groupement. Le frontend reçoit les permissions à plat via `/auth/me`.
2. Cache 60 s + invalidation explicite : compromis latence/fraîcheur ; une révocation urgente passe par `logout-all`.
3. Scope agences appliqué aux read models (liste) ; les accès directs par id restent bornés par tenant+RLS — resserrage par agence sur show/update possible ultérieurement si exigé.
4. `can:` avant validation : pas de fuite d'information de schéma aux non-autorisés.

---

*Fin de l'Étape 14. En attente de validation avant l'Étape 15 — Frontend Next.js.*
