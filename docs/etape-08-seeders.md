# Étape 8 — Seeders

**Projet :** SILARIS · **Statut :** Exécuté et vérifié · **Prérequis :** Étape 7 validée

---

## 1. Livrables — 11 seeders dans `apps/api/database/seeders/`

### Référentiels (idempotents — upsert, exécutables en production)
`php artisan db:seed`

| Seeder | Contenu | Volumétrie |
|---|---|---|
| CurrencySeeder | Devises ISO 4217 (XOF/XAF avec 0 décimale) | 26 |
| CountrySeeder | Pays corridors majeurs (liste complète importable plus tard) | 84 |
| PortSeeder | Ports UN/LOCODE — monde + couverture Afrique de l'Ouest | 132 |
| AirportSeeder | Hubs cargo IATA/ICAO | 61 |
| IncotermSeeder | Incoterms 2020 avec `cost_allocation` (qui paie quoi → moteur cotation) | 11 |
| CarrierSeeder | 9 compagnies (SCAC + `connector_key` du registre CarrierConnect) | 9 |
| AirlineSeeder | Compagnies cargo (préfixes AWB) | 14 |
| GoodsTypeSeeder | Marchandises génériques + produits CI (cacao, cajou…) + 9 classes IMO | 25 |
| CarrierStatusMappingSeeder | Statuts propriétaires → codes DCSA (GTIN, LOAD, DEPA, TRSH, ARRI, DISC, GTOT, RETU) | 72 |
| PermissionSeeder | Catalogue permissions (29 modules × actions) + 8 rôles système avec affectations | 115 perms, 8 rôles |

### Démonstration (interdit en production — garde `app()->environment`)
`php artisan db:seed --class=DemoTenantSeeder`

Tenant **TransAfrica Logistics** (slug `demo`) : 1 société (TAL, XOF), 2 agences (ABJ, SPY), 7 utilisateurs (1 par rôle, mdp `password`), workflow standard 8 étapes, 9 parties (4 clients, 1 prospect avec opportunité, 4 fournisseurs typés), compte portail client, grille tarifaire FCL Asie→Abidjan, devis accepté Q-2026-0412 (4 lignes, marge visible), **3 dossiers** :
1. `TAL-2026-00128` — import FCL Shanghai→Abidjan en transit : booking MSC confirmé (3 cut-offs), navire MSC AURELIA + voyage 226W + 4 escales, 2 conteneurs 40HC (numéros ISO 6346 **générés avec check digit calculé**), MBL seaway + HBL telex, subscription tracking + 5 événements DCSA, timeline 6 événements (dont retard +2 j notifié), checklist 6 documents, 1 tâche ouverte
2. `TAL-2026-00124` — export FCL cacao Abidjan→Le Havre en booking (HS code, 320 sacs)
3. `TAL-2026-00109` — import aérien CDG→ABJ en dédouanement : MAWB `17612345675` (**mod 7 valide**) + HAWB, 2 segments de vol EK via DXB, mission de livraison planifiée (camion + chauffeur lié au user)

Facturation : TVA CI 18 %, facture validée F-2026-0315 (4 lignes, totaux cohérents), séquences alignées (`shipment:ABJ:2026`=128, `invoice`=315…).

## 2. Vérifications exécutées

- 11/11 seeders DONE, ré-exécutables (upsert/updateOrInsert).
- 2/2 conteneurs passent `iso6346_check()` en base.
- Trigger immuabilité : `UPDATE invoices SET total_excl_tax=1` sur F-2026-0315 → **rejeté** par `protect_validated_invoice()`.
- Comptes démo : `admin@demo.silaris.app` … `chauffeur@demo.silaris.app` / `password` ; portail : `contact@sicoa-demo.ci` / `password`.

## 3. Décisions

1. Référentiels et démo strictement séparés — DatabaseSeeder ne référence jamais DemoTenantSeeder.
2. Check digits **calculés** (helper PHP ISO 6346) — jamais de numéros codés en dur potentiellement invalides.
3. Séquences pré-positionnées sur les derniers numéros démo — la numérotation continue proprement.
4. `cost_allocation` des incoterms structuré (main_carriage/insurance/customs/delivery × seller/buyer) — directement exploitable par le moteur de cotation (Étape 10).

---

*Fin de l'Étape 8. En attente de validation avant l'Étape 9 — Modèles.*
