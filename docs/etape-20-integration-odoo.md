# Étape 20 — Intégration Odoo

**Projet :** SILARIS · **Statut :** Exécuté et vérifié (3 tests Pest, serveur Odoo simulé JSON-RPC) · **Prérequis :** Étape 19 validée

---

## 1. Architecture livrée (ACL — ADR-07)

### Transport
- `OdooClient` : JSON-RPC `/jsonrpc` (`authenticate` + `execute_kw`), compatible Odoo 16/17/18, timeout 30 s + 2 retries.
- **Deux familles d'erreurs distinctes** : `OdooUnavailable` (réseau/HTTP → retry backoff) vs `OdooRequestFailed` (erreur métier Odoo → **dead letter immédiat**, pas de retry aveugle).
- `OdooClientFactory` : connexion du tenant courant, api_key **chiffrée** (Crypt).

### Synchronisation
| Sens | Composant | Détail |
|---|---|---|
| Push | `PushInvoiceToOdoo` (queue `odoo`, 5 essais, backoff 1 min→1 h) | Facture validée → `account.move` **brouillon** (jamais d'écriture comptable — la validation comptable reste dans Odoo) ; client poussé à la volée si non mappé ; devise résolue par code ISO ; taxes mappées ; **idempotent** (mapping → write, sinon create) |
| Push | `PushPartyToOdoo` | parties → `res.partner` (customer/supplier_rank, adresse, contact principal), checksum anti-écrasement |
| Pull | `PullTaxes` | `account.tax` ventes → `tax_rates` (Odoo maître) |
| Pull | `PullPaymentStatuses` | `payment_state` → `invoices.payment_status` — **seul OdooSync écrit ce champ** |

### Orchestration & supervision
- Hook : validation facture → `PushInvoiceToOdoo::dispatch()->afterCommit()`.
- `odoo:sync` (scheduler horaire) : healthcheck (statut persisté), pull taxes + paiements par tenant.
- `GET /v1/odoo/status` (permission `odoo.read`) : connexion, santé 7 j (vue), **dead letters**, 20 derniers échanges.
- `PUT /v1/odoo/config` (`odoo.configure`) : test de connexion avant enregistrement, credentials chiffrés.
- Journal `odoo_sync_logs` sur chaque opération (payload, erreur, tentatives, durée).
- **Mode dégradé** : Odoo down → jobs s'accumulent avec backoff, plateforme inaffectée, reprise automatique.

## 2. Tests (Pest + Http::fake JSON-RPC, base `silaris_test` dédiée)
| Test | Vérifie |
|---|---|
| Push facture complète | auth → création `res.partner` (501) → résolution devise → `account.move` (9001) ; statut `synced`, `odoo_id`, **2 mappings persistés**, log success, **payload account.move conforme** (move_type, partner_id, ligne 1000.0) |
| Erreur métier Odoo | statut `sync_failed` + log `dead_letter` (pas de retry) |
| Pull paiements | `payment_state=paid` → `payment_status=paid` (1 mise à jour) |

## 3. Incidents corrigés
1. Helper `problem()` non gardé → fatal silencieux quand Pest boote l'app deux fois → `function_exists`.
2. Migration Sanctum publiée par `vendor:publish` (Étape 13) en doublon de la nôtre (et incompatible : tokenable bigint vs uuid) → supprimée.
3. `tests/Pest.php` manquant (skeleton) → créé.

## 4. Reste
- Push devis (`sale.order`) — même pattern, backlog court terme.
- Webhook Odoo entrant (paiement temps réel) — le pull horaire couvre le besoin en attendant.
- UI supervision (écran Comptable consommant `/v1/odoo/status`).

---

*Fin de l'Étape 20. En attente de validation avant l'Étape 21 — Tests (unitaires, intégration, E2E).*
