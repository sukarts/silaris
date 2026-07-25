# Étape 6 — Base de Données

**Projet :** SILARIS — Plateforme de Gestion de Transit International (TMS)
**Version :** 1.0
**Statut :** En attente de validation
**Prérequis :** Étapes 1–5 validées · ERD de conception : Étape 3
**Moteur :** PostgreSQL 16

---

## 1. Conventions générales

| Sujet | Convention |
|---|---|
| Clés primaires | `uuid` **v7** (ordonnables temporellement → index B-tree efficaces, non devinables) générés applicativement |
| Nommage | Tables plurielles `snake_case` ; FK `<singulier>_id` ; index `ix_<table>_<cols>` ; unique `ux_` ; check `ck_` ; FK `fk_` |
| Horodatage | `timestamptz` partout (UTC en base, conversion à l'affichage) ; `created_at`/`updated_at` sur toutes les tables |
| Multi-tenant | `tenant_id uuid NOT NULL` sur toute table tenant-scopée + politique RLS (§7) |
| Suppression | **Soft delete** (`deleted_at`) uniquement sur : parties, users, documents, tariffs. Le reste : suppression interdite (audit) ou dure (tables techniques). Jamais de soft delete sur les tables légales (invoices → statut `voided` via avoir) |
| Monnaie | `numeric(14,2)` + `currency_code char(3)` systématiquement appariés ; jamais de float |
| Poids/volumes | `numeric(12,3)` kg / `numeric(12,3)` m³ |
| Énumérations | `text` + contrainte `CHECK` (pas de type ENUM pg — migrations pénibles) ; valeurs = enums PHP |
| JSON | `jsonb` uniquement pour données réellement semi-structurées (settings, payloads, snapshots) — jamais pour ce qui se requête relationnellement |
| Codes référentiels | UN/LOCODE `char(5)`, IATA `char(3)`, devise ISO 4217 `char(3)`, incoterm `char(3)`, SCAC `varchar(4)` |

---

## 2. Inventaire des tables (72)

### Référentiels globaux — sans tenant_id (8)
| Table | Rôle |
|---|---|
| `countries` | ISO 3166 (code2 PK, code3, noms fr/en) |
| `ports` | UN/LOCODE, nom, pays, lat/lon |
| `airports` | IATA PK, ICAO, nom, pays |
| `currencies` | ISO 4217, symbole, décimales |
| `exchange_rates` | devise→devise, taux, date (PK composite devise+devise+date) |
| `incoterms` | code, libellé, version (2020), répartition coûts |
| `carriers` | compagnies maritimes : SCAC, nom, connecteur disponible |
| `airlines` | préfixe AWB (3 chiffres), IATA, nom |

### Tenancy & Identity (11)
`tenants`, `companies`, `branches`, `users`, `user_branches`, `roles`, `permissions`, `role_permissions`, `user_roles`, `api_keys`, `personal_access_tokens` (Sanctum) — + `sessions_log` (connexions suspectes).

### Workflow & paramètres (4)
`workflow_definitions`, `workflow_steps`, `sequences`, `tenant_settings`.

### CRM (6)
`parties`, `party_contacts`, `party_addresses`, `opportunities`, `complaints`, `portal_accounts`.

### Dossiers (6)
`shipments`, `shipment_events`, `shipment_tasks`, `shipment_comments`, `transport_segments`, `cargo_items`.

### Maritime (8)
`bookings`, `containers`, `container_assignments`, `vessels`, `voyages`, `port_calls`, `bills_of_lading`, `consolidations` (+ `consolidation_items`). → 9

### Aérien (2)
`air_waybills`, `flight_legs`.

### Routier (5)
`trucks`, `trailers`, `drivers`, `missions` (+ `mission_stops`), `proof_of_deliveries`. → 6

### Tracking (3)
`tracking_subscriptions`, `tracking_events` (partitionnée), `carrier_status_mappings`.

### Documents (2)
`documents`, `document_versions`.

### Cotation & Facturation (7)
`tariffs`, `tariff_lines`, `quotes`, `quote_lines`, `invoices`, `invoice_lines`, `tax_rates` (répliqué depuis Odoo).

### Notifications (4)
`notification_templates`, `notification_preferences`, `notifications`, `notification_deliveries`.

### Intégrations (6)
`odoo_connections`, `odoo_entity_maps`, `odoo_sync_logs`, `carrier_api_credentials`, `webhook_endpoints`, `webhook_deliveries`.

### Technique (5)
`audit_logs` (partitionnée), `outbox_events`, `saved_views`, `dashboard_widgets`, `failed_jobs`/`job_batches` (Laravel).

---

## 3. Dictionnaire de données — tables cœur

Types abrégés : `u`=uuid, `t`=text, `tz`=timestamptz, `n(p,s)`=numeric, `b`=boolean, `i`=integer, `jb`=jsonb.

### 3.1 `tenants`
| Colonne | Type | Null | Défaut / Contrainte |
|---|---|---|---|
| id | u | non | PK |
| name | t | non | |
| slug | t | non | `ux_tenants_slug` — sous-domaine |
| plan | t | non | `'standard'` · ck ∈ {trial, standard, enterprise, private} |
| locale_default | t | non | `'fr'` |
| settings | jb | non | `'{}'` — préférences plateforme (fréquence tracking, seuil retard…) |
| is_active | b | non | `true` |
| created_at / updated_at | tz | non | |

### 3.2 `users`
| Colonne | Type | Null | Contrainte |
|---|---|---|---|
| id | u | non | PK |
| tenant_id | u | non | FK tenants — `null` interdit même pour super-admin (tenant plateforme dédié) |
| email | t | non | `ux_users_tenant_email (tenant_id, lower(email))` |
| password_hash | t | non | Argon2id |
| first_name / last_name | t | non | |
| phone | t | oui | E.164 |
| mfa_secret | t | oui | chiffré applicativement (cast encrypted) |
| mfa_enabled | b | non | `false` |
| mfa_recovery_codes | jb | oui | hashés |
| locale | t | non | `'fr'` |
| is_active | b | non | `true` |
| last_login_at | tz | oui | |
| password_changed_at | tz | non | politique expiration |
| deleted_at | tz | oui | soft delete |

`user_roles(user_id, role_id)` PK composite ; `user_branches(user_id, branch_id)` PK composite ; `permissions(key text PK, module text)` — remplies par seeder, jamais modifiables en runtime.

### 3.3 `parties` (clients / prospects / fournisseurs)
| Colonne | Type | Null | Contrainte |
|---|---|---|---|
| id | u | non | PK |
| tenant_id | u | non | FK, RLS |
| type | t | non | ck ∈ {client, prospect, supplier} |
| supplier_kind | t | oui | ck ∈ {ocean_carrier, airline, trucker, customs_agent, handler, insurer, port_agent, overseas_agent} ; `CHECK (type <> 'supplier' OR supplier_kind IS NOT NULL)` |
| code | t | non | `ux_parties_tenant_code` — code court interne |
| name | t | non | |
| tax_id | t | oui | n° identification fiscale |
| currency_code | char(3) | oui | FK currencies — devise de facturation |
| payment_terms_days | i | oui | |
| credit_limit | n(14,2) | oui | plafond encours |
| notification_prefs | jb | non | `'{}'` — défauts (surchargeable par portal_account) |
| owner_id | u | oui | FK users — commercial référent |
| odoo_id | i | oui | id res.partner (rempli par OdooSync) |
| converted_from_prospect_at | tz | oui | traçabilité conversion |
| deleted_at | tz | oui | |

### 3.4 `shipments` — table centrale
| Colonne | Type | Null | Contrainte |
|---|---|---|---|
| id | u | non | PK |
| tenant_id | u | non | FK, RLS |
| reference | t | non | `ux_shipments_tenant_reference` — générée via `sequences` |
| client_id | u | non | FK parties · `CHECK` via trigger : type=client |
| branch_id | u | non | FK branches |
| company_id | u | non | FK companies (société juridique porteuse) |
| agent_id | u | non | FK users |
| supervisor_id | u | oui | FK users |
| direction | t | non | ck ∈ {import, export} |
| mode | t | non | ck ∈ {sea_fcl, sea_lcl, air, road, multimodal} |
| status | t | non | clé de l'étape workflow courante |
| workflow_definition_id | u | non | FK — **figé à la création** (changement de config n'affecte pas les dossiers en cours) |
| incoterm_code | char(3) | non | FK incoterms |
| origin_locode / destination_locode | char(5) | non | FK ports (ou airports via table unifiée `locations` — voir décision D-4) |
| priority | t | non | `'normal'` · ck ∈ {low, normal, high, critical} |
| etd / eta / atd / ata | tz | oui | `CHECK (ata IS NULL OR eta IS NOT NULL)` |
| eta_initial | tz | oui | première ETA — mesure de dérive |
| estimated_cost / estimated_revenue | n(14,2) | oui | + `currency_code` société |
| quote_id | u | oui | FK quotes — dossier issu d'un devis |
| closed_at | tz | oui | |
| closed_by | u | oui | FK users |
| created_at / updated_at | tz | non | |

### 3.5 `shipment_events` (timeline)
| Colonne | Type | Contrainte |
|---|---|---|
| id | u | PK |
| tenant_id | u | RLS |
| shipment_id | u | FK CASCADE |
| type | t | ck ∈ {status_change, tracking, document, comment, task, billing, system} |
| title | t | affichage direct |
| payload | jb | données spécifiques au type |
| source | t | ck ∈ {internal, carrier_api, odoo, portal, system} |
| actor_id | u NULL | FK users — null si automatique |
| occurred_at | tz | horodatage métier (≠ created_at technique) |

### 3.6 `containers` + `container_assignments`
`containers` : `number varchar(11)` avec **`CHECK` ISO 6346 + validation check digit par fonction pg `iso6346_check(text)`** (immutable, créée en migration), `ux_containers_tenant_number`, `size_type` ck ∈ {20GP, 40GP, 40HC, 45HC, 20RF, 40RF, 20OT, 40OT, 20FR, 40FR, 20TK}, `tare_kg`, `max_payload_kg`.

`container_assignments` : container_id + shipment_id + booking_id, `seal_number`, `vgm_kg` + `vgm_verified_at`, `free_time_days i`, `free_time_ends_at tz` (calculée à l'ATA), `gate_in_at`, `loaded_at`, `discharged_at`, `gate_out_at`, `returned_at`. `ux_assignment_active` : un conteneur ne peut avoir qu'une affectation active (index partiel `WHERE returned_at IS NULL`).

### 3.7 `bills_of_lading`
| Colonne | Type | Contrainte |
|---|---|---|
| id / tenant_id / shipment_id | u | |
| parent_id | u NULL | FK self — HBL → MBL ; `CHECK (type='master' OR parent_id IS NOT NULL)` différé pour LCL en cours de constitution → contrainte applicative + vue de contrôle |
| type | t | ck ∈ {master, house} |
| number | t | `ux_bl_tenant_number` |
| release_type | t | ck ∈ {original, telex, seaway} |
| status | t | ck ∈ {draft, verified, issued, surrendered} |
| shipper / consignee / notify_party | jb | snapshot figé à l'émission (pas de FK — le BL est un document légal immuable) |
| goods_description | t | |
| gross_weight_kg / volume_m3 / packages_count | n / n / i | |
| issued_at / issued_by | tz / u | |

### 3.8 `air_waybills`
Idem structure BL + : `number char(11)` avec **`CHECK awb_mod7(number)`** (fonction pg : série 7 chiffres mod 7 = chiffre contrôle), `airline_id` FK, `gross_weight_kg`, `volume_m3`, `chargeable_weight_kg` **colonne générée** : `GREATEST(gross_weight_kg, volume_m3 * 166.667)`.

### 3.9 `tracking_events` — PARTITIONNÉE
```sql
CREATE TABLE tracking_events (
  id uuid NOT NULL,
  tenant_id uuid NOT NULL,
  subscription_id uuid NOT NULL,
  shipment_id uuid,
  dcsa_event_code text NOT NULL,        -- référentiel DCSA (ARRI, DEPA, LOAD, DISC, GTIN, GTOT…)
  raw_status text,
  location_locode char(5),
  vessel_imo text,
  occurred_at timestamptz NOT NULL,
  event_hash text NOT NULL,             -- sha256(subscription+code+lieu+date) → déduplication
  raw_payload jsonb,
  created_at timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (id, occurred_at)
) PARTITION BY RANGE (occurred_at);
-- Partitions mensuelles auto-créées par job scheduler (+1 mois d'avance)
-- ux_tracking_events_hash UNIQUE (event_hash, occurred_at)
```
Volume attendu : millions de lignes — partitions mensuelles, détachées vers archive après 24 mois (§9).

### 3.10 `invoices`
| Colonne | Type | Contrainte |
|---|---|---|
| id / tenant_id | u | |
| company_id | u | FK — la séquence légale est par société |
| type | t | ck ∈ {proforma, invoice, credit_note} |
| number | t | `ux_invoices_company_type_number` |
| party_id / shipment_id | u | FK |
| original_invoice_id | u NULL | `CHECK (type <> 'credit_note' OR original_invoice_id IS NOT NULL)` |
| status | t | ck ∈ {draft, validated, synced, sync_failed} |
| payment_status | t | ck ∈ {none, unpaid, partial, paid} — écrit uniquement par OdooSync |
| currency_code | char(3) | |
| total_excl_tax / total_tax / total_incl_tax | n(14,2) | `CHECK (total_incl_tax = total_excl_tax + total_tax)` |
| validated_at / validated_by | tz / u | après validation : **lignes et totaux immuables** (trigger de protection) |
| odoo_id | i NULL | account.move |
| credit_reason | t NULL | obligatoire pour avoir (ck) |

`invoice_lines` : service_code, description, quantity n(12,3), unit ck ∈ {container, kg, m3, flat, percent, unit}, unit_price, currency_code, tax_rate_id FK, line_total **générée**.

### 3.11 `sequences` — numérotation sans trou
```sql
CREATE TABLE sequences (
  tenant_id uuid NOT NULL,
  scope text NOT NULL,          -- 'shipment:ABJ:2026' | 'invoice:COMPANY_ID:invoice' …
  last_value bigint NOT NULL DEFAULT 0,
  PRIMARY KEY (tenant_id, scope)
);
-- Usage : UPDATE … SET last_value = last_value + 1 RETURNING last_value
-- dans la MÊME transaction que l'INSERT métier → sans trou, sous verrou ligne.
```

### 3.12 `audit_logs` — PARTITIONNÉE, append-only
`id, tenant_id, user_id NULL, action text, entity_type, entity_id, old_values jsonb, new_values jsonb, ip inet, user_agent text, request_id uuid, occurred_at tz` — PARTITION BY RANGE (occurred_at), mensuelle. `REVOKE UPDATE, DELETE ON audit_logs FROM app_role;` — immuabilité garantie par droits pg, pas par discipline.

### 3.13 `outbox_events`
`id, tenant_id, aggregate_type, aggregate_id, event_type, payload jsonb, occurred_at, published_at NULL, attempts i, last_error text`. Index partiel `ix_outbox_unpublished ON outbox_events (occurred_at) WHERE published_at IS NULL` — le poller ne scanne que le backlog.

### 3.14 `odoo_entity_maps`
`tenant_id, entity_type text, silaris_id uuid, odoo_model text, odoo_id int, last_pushed_at, last_pulled_at, checksum text` — PK (tenant_id, entity_type, silaris_id) + `ux_odoo_map_reverse (tenant_id, odoo_model, odoo_id)`. Checksum = détection modification concurrente (conflits).

### 3.15 `documents` + `document_versions`
`documents` : shipment_id NULL (docs hors dossier possibles : contrats client), party_id NULL, type ck (11 valeurs Étape 3), visibility ck ∈ {internal, client, confidential}, status ck ∈ {missing, received, validated}, is_archived, deleted_at.
`document_versions` : version i (ux document_id+version), s3_key t (jamais exposée — URLs signées générées à la demande), mime_type, size_bytes bigint, checksum_sha256, av_scan_status ck ∈ {pending, clean, infected, error}, uploaded_by.

---

## 4. Stratégie d'index

### 4.1 Règles
1. Toute FK reçoit un index (pg ne le fait pas automatiquement).
2. Index composites orientés requêtes réelles, `tenant_id` **en tête** de chaque index multi-tenant (toutes les requêtes filtrent par tenant via RLS).
3. Index partiels pour les états chauds (backlog, actifs).
4. `EXPLAIN ANALYZE` en CI sur les 10 requêtes critiques (liste dossiers, timeline, recherche facture…) avec seuils.

### 4.2 Index critiques (extraits)
```sql
-- Liste dossiers (écran principal — filtres + tri ETA)
ix_shipments_tenant_status_eta   (tenant_id, status, eta)
ix_shipments_tenant_client       (tenant_id, client_id)
ix_shipments_tenant_agent        (tenant_id, agent_id) WHERE closed_at IS NULL
-- Timeline
ix_shipment_events_shipment_date (shipment_id, occurred_at DESC)
-- Tracking : subscriptions à rafraîchir
ix_tracking_subs_active          (last_polled_at) WHERE status = 'active'
-- Surestaries : alertes franchise
ix_assignments_free_time         (tenant_id, free_time_ends_at)
                                 WHERE returned_at IS NULL AND free_time_ends_at IS NOT NULL
-- Factures
ix_invoices_tenant_party_status  (tenant_id, party_id, payment_status)
-- Recherche référence multi-type (page publique) : ux déjà en place sur
--   shipments.reference, bills_of_lading.number, air_waybills.number, containers.number
-- Audit
ix_audit_tenant_entity           (tenant_id, entity_type, entity_id, occurred_at DESC)
```

---

## 5. Contraintes d'intégrité

1. **FK systématiques** avec `ON DELETE RESTRICT` par défaut. `CASCADE` uniquement enfants intrinsèques (shipment_events, invoice_lines, quote_lines, document_versions, flight_legs, mission_stops, port_calls). `SET NULL` jamais utilisé (perte d'information).
2. **CHECK métier en base** (2e ligne de défense après le domaine) : ISO 6346, AWB mod 7, totaux facture, cohérence type/parent BL, avoir → facture d'origine, montants ≥ 0.
3. **Triggers de protection** (rares, justifiés) : immuabilité facture validée ; interdiction UPDATE/DELETE audit_logs (doublée par REVOKE) ; vérification type partie (client_id → type=client).
4. **Contraintes différées** : aucune — les invariants multi-lignes (MBL↔HBL en constitution) restent applicatifs + vues de contrôle qualité (§8).

---

## 6. Fonctions PostgreSQL utilitaires

| Fonction | Rôle |
|---|---|
| `iso6346_check(text) returns boolean` | Valide check digit conteneur (IMMUTABLE → utilisable en CHECK) |
| `awb_mod7(text) returns boolean` | Valide chiffre contrôle AWB |
| `next_sequence(tenant uuid, scope text) returns bigint` | Incrément atomique numérotation |
| `set_tenant(uuid)` | `SET app.tenant_id` — appelée par le middleware de connexion |

---

## 7. Row-Level Security

```sql
-- Rôle applicatif distinct du propriétaire des tables (sinon RLS bypassée)
CREATE ROLE silaris_app LOGIN;              -- connexions Laravel
GRANT USAGE ON SCHEMA public TO silaris_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO silaris_app;
REVOKE UPDATE, DELETE ON audit_logs FROM silaris_app;

-- Sur CHAQUE table tenant-scopée (généré par la migration) :
ALTER TABLE shipments ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON shipments
  USING (tenant_id = current_setting('app.tenant_id')::uuid)
  WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid);
```

- `current_setting('app.tenant_id')` positionné par le middleware (requêtes HTTP) et par le bootstrap des jobs (workers) — `SET LOCAL` dans la transaction.
- Absence de setting → `current_setting` lève une erreur → **aucune requête possible hors contexte tenant**. Les traitements plateforme (super-admin, migrations) utilisent un rôle distinct `silaris_admin` non soumis aux policies.
- Tables globales (référentiels) : RLS désactivée, `GRANT SELECT` seulement à `silaris_app` ; écritures référentiels via `silaris_admin`.
- `WITH CHECK` empêche aussi l'écriture cross-tenant (INSERT/UPDATE avec mauvais tenant_id rejeté par le moteur).

---

## 8. Vues utiles

| Vue | Contenu |
|---|---|
| `v_shipments_list` | Read model liste dossiers : dossier + client.name + agent + compteurs (docs manquants, conteneurs, tâches ouvertes) + retard calculé — évite 6 jointures répétées |
| `v_active_delays` | Dossiers dont eta > eta_initial + seuil tenant, avec jours de retard |
| `v_demurrage_alerts` | Affectations conteneurs dont free_time_ends_at < now() + 3 j, non restitués |
| `v_missing_documents` | Checklist documentaire incomplète par dossier actif |
| `v_revenue_operational` | CA opérationnel par mois/agence/mode (factures validées, hors proforma) |
| `v_agent_workload` | Dossiers actifs et tâches ouvertes par agent |
| `v_odoo_sync_health` | Backlog + échecs sync par entité (écran Comptable) |
| `v_bl_consistency` | Contrôle qualité : HBL sans MBL émis, MBL sans HBL (LCL) — invariant applicatif §5.4 |

Vues simples (non matérialisées) sauf `v_revenue_operational` → **matérialisée**, rafraîchie par scheduler (toutes les heures) — dashboard direction sans coût requête.

---

## 9. Stratégie d'archivage et rétention

| Donnée | Politique |
|---|---|
| `tracking_events` | Partitions mensuelles ; > 24 mois : `DETACH PARTITION` → export Parquet vers S3 (préfixe archive/) → DROP. Restauration possible par réattachement |
| `audit_logs` | Partitions mensuelles ; > durée définie par politique tenant (défaut 36 mois) : export chiffré S3 puis DROP partition |
| `shipments` clôturés | Jamais supprimés (historique métier). `is_archived` visuel après N mois (paramètre tenant) — exclus des vues actives, toujours consultables |
| `documents` | À la clôture dossier : `is_archived=true` ; S3 lifecycle → classe froide après 12 mois ; rétention légale paramétrable par type (docs douaniers ≥ durée réglementaire) |
| `notifications`, `webhook_deliveries`, `odoo_sync_logs` | Purge > 12 mois (job scheduler) — données techniques |
| `outbox_events` publiés | Purge > 30 jours |
| Backups | pgBackRest : full hebdo, incrémental quotidien, WAL archiving continu → RPO ≤ 15 min ; rétention 30 j chaud + 12 mois froid ; test de restauration mensuel automatisé (job CI infra) |

---

## 10. Décisions prises

1. **UUID v7** partout (ordonnable, index-friendly) — généré applicativement, pas de dépendance extension pg.
2. **Pas de type ENUM PostgreSQL** — `text + CHECK`, évolutif par simple migration de contrainte.
3. **Soft delete minimal** (4 tables) — le reste : restriction FK + statuts métier. Jamais sur documents légaux (facture annulée = avoir).
4. **Table `locations` écartée** : `origin_locode`/`destination_locode` référencent ports OU aéroports selon le mode — résolution applicative + CHECK par mode (un dossier aérien référence des IATA mappés en pseudo-LOCODE UN standard, ex. `FRCDG`). Simplicité > généricité ici.
5. **Snapshots `jsonb` sur les documents légaux** (BL parties, adresses facture) — un BL émis ne change pas quand la fiche client change.
6. **`chargeable_weight_kg` en colonne générée** — la règle IATA vit aussi en base, impossible d'insérer une valeur incohérente.
7. **Partitionnement natif** sur les 2 tables à forte volumétrie (tracking_events, audit_logs) dès le départ — se rattrape très mal après coup.
8. **Immuabilité par droits pg** (REVOKE + triggers) sur audit et factures validées — la garantie ne repose pas sur le code applicatif.
9. **RLS avec rôle applicatif dédié** + `WITH CHECK` — lecture ET écriture cross-tenant impossibles au niveau moteur.
10. Read models via **vues** (1 matérialisée) plutôt que tables projetées — CQRS léger conforme ADR-03.

## 11. Tâches restantes

- Étape 7 : migrations Laravel implémentant ce schéma (+ bootstrap app Laravel).
- Étape 8 : seeders (référentiels complets : ~250 ports, aéroports, devises, incoterms, 9 carriers ; jeu de démo).
- Dictionnaire des tables secondaires complété dans le code des migrations (commentaires `COMMENT ON`).

---

*Fin de l'Étape 6. En attente de validation avant l'Étape 7 — Migrations.*
