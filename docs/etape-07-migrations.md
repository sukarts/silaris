# Étape 7 — Migrations

**Projet :** SILARIS · **Statut :** En attente de validation · **Prérequis :** Étape 6 validée

---

## 1. Livrables

- [`apps/api/composer.json`](../apps/api/composer.json) — dépendances Laravel 11 + PSR-4 `Silaris\Modules\`
- **18 migrations** dans [`apps/api/database/migrations/`](../apps/api/database/migrations/) implémentant les 72 tables de l'Étape 6 :

| # | Fichier | Contenu |
|---|---|---|
| 000001 | referential | 9 référentiels globaux (countries, ports, airports, currencies, exchange_rates, incoterms, carriers, airlines, goods_types) |
| 000002 | tenancy | tenants, companies, branches, tenant_settings (surcharge société/agence) |
| 000003 | identity | users (email unique par tenant, insensible casse), RBAC complet, Sanctum, api_keys, sessions_log |
| 000004 | workflow | workflow_definitions/steps (transitions + conditions jsonb), sequences |
| 000005 | core_functions | `iso6346_check()`, `awb_mod7()`, `next_sequence()` — validations moteur |
| 000006 | crm | parties unifiées + CHECK type/supplier_kind, contacts, adresses, opportunités, réclamations, portal_accounts |
| 000007 | pricing | tariffs/tariff_lines (buy/sell, tranches poids, minimums), quotes/quote_lines (line_total généré) |
| 000008 | shipment | shipments + trigger type client, timeline, tâches, commentaires, segments, cargo ; FK complaints→shipments résolue ici |
| 000009 | ocean | vessels, voyages, port_calls, bookings (3 cut-offs), containers (CHECK ISO 6346), assignments (unique actif partiel + index surestaries), BL (snapshots jsonb, CHECK master/parent), consolidations |
| 000010 | air | AWB (CHECK mod 7 sur master, chargeable_weight **colonne générée** IATA), flight_legs |
| 000011 | road | trucks, trailers, drivers, missions, mission_stops, proof_of_deliveries |
| 000012 | tracking | subscriptions (index partiel actifs), **tracking_events partitionnée par mois** (default + 3 partitions initiales), carrier_status_mappings |
| 000013 | documents | documents (CHECK rattachement), versions (checksum, scan AV), journal téléchargements |
| 000014 | billing | tax_rates (miroir Odoo), invoices (7 CHECK + **triggers immuabilité/anti-suppression** après validation), invoice_lines |
| 000015 | notifications | templates multilingues, préférences canal×événement (XOR user/portail), notifications, deliveries |
| 000016 | integrations | odoo_connections (credentials chiffrés), entity_maps (PK composite + index inverse), sync_logs, carrier_api_credentials (circuit breaker), exchange_logs, webhooks |
| 000017 | technical | **audit_logs partitionnée** + outbox (index partiel non-publiés), saved_views, dashboard_widgets, tables framework |
| 000018 | rls_and_views | Rôle `silaris_app`, **RLS sur 54 tables** (USING + WITH CHECK), REVOKE audit, 7 vues + 1 matérialisée |

## 2. Points d'implémentation

- Partitionnement et RLS hors Schema Builder (non supporté) → `DB::unprepared` SQL brut, encapsulé et réversible (`down()` complets).
- Ordre FK inter-modules respecté : pricing avant shipment (quote_id), CRM avant shipment (complaints.shipment_id ajoutée en 000008).
- Partitions futures (tracking_events, audit_logs) créées par commande artisan planifiée (Étape 10, scheduler) — 3 mois + default livrés.
- `silaris_app` : NOLOGIN — le LOGIN réel par environnement hérite de ce rôle (créé par l'ops, jamais en migration).

## 3. Exécution — VALIDÉE ✓

Environnement : Docker Desktop (installé), PHP 8.3.32 via image `serversideup/php:8.3-cli`, PostgreSQL 16 (conteneur `silaris-pg`, réseau `silaris-dev`).

- Squelette Laravel généré et fusionné dans `apps/api` (nos composer.json et migrations préservés).
- **Laravel 12** retenu (Laravel 11 bloqué par advisories de sécurité Composer — ajustement `composer.json` : framework ^12, Pest ^3, Larastan ^3).
- `composer install` : 156 paquets.
- **`php artisan migrate` : 18/18 DONE** — 96 tables (partitions incluses), 56 tables sous RLS, 7 vues + 1 matérialisée.

Correctif apporté en cours d'exécution : les **FK auto-référentes** (bills_of_lading.parent_id, air_waybills.parent_id, invoices.original_invoice_id) doivent être ajoutées dans un `Schema::table` séparé après le `Schema::create` — Laravel émet la contrainte PK après les FK dans un même create, PostgreSQL rejette.

Tests de validation exécutés :
| Test | Résultat |
|---|---|
| `iso6346_check('CSQU3054383')` (vecteur canonique) | ✓ true ; mauvais check digit → false |
| `awb_mod7('17612345675')` | ✓ true |
| Partitions tracking_events | ✓ 4 (default + 3 mois) |
| `silaris_app` UPDATE sur audit_logs | ✓ interdit |
| RLS lecture : tenant A voit sa société, tenant B voit 0 | ✓ |
| RLS écriture cross-tenant | ✓ rejetée (`violates row-level security policy`) |
| Requête sans contexte tenant | ✓ erreur (`unrecognized configuration parameter`) |

Commande de re-exécution :
```bash
cd apps/api && docker run --rm --network silaris-dev -v "$PWD":/app -w /app serversideup/php:8.3-cli php artisan migrate
```

---

*Fin de l'Étape 7. En attente de validation avant l'Étape 8 — Seeders.*
