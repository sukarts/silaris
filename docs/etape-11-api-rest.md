# Étape 11 — API REST

**Projet :** SILARIS · **Statut :** Exécuté et vérifié (serveur réel + curl) · **Prérequis :** Étape 10 validée

---

## 1. Livrables

### Socle API (`bootstrap/app.php`, `app/Http/Middleware`, `routes/api.php`)
| Composant | Détail |
|---|---|
| **Erreurs RFC 9457** | Toutes les erreurs API en `application/problem+json` : DomainException → 422 + `error_code`, ValidationException → 422 + `errors[]`, 401/403/404/HttpException mappées. Types URI `https://silaris.app/errors/*` |
| `ForceJsonResponse` | L'API ne répond que JSON |
| `ResolveTenant` | Tenant = utilisateur authentifié (Étape 13) ; en local/testing : en-tête `X-Tenant-Slug` (outillage dev). Sans tenant → 400. Positionne le contexte + `app.tenant_id` RLS |
| Agrégation de routes | `routes/api.php` charge `src/Modules/*/Interface/Http/routes.php` (interne, middleware tenant) et `public_routes.php` (public, throttle) — un module ajoute ses routes sans toucher au cœur |
| Rate limiters | `public-tracking` : 20/min + 300/jour par IP ; `api` : 120/min par utilisateur |
| Santé | `GET /v1/health` (liveness) ; `GET /v1/ready` (readiness DB, extensible Redis/S3/Meili) |

### Module Shipment — chaîne HTTP complète (pattern pour l'Étape 12)
- `StoreShipmentRequest` (validation stricte : exists avec contrainte type=client, regex UN/LOCODE, cohérence ETA≥ETD), `AdvanceStepRequest`, `ListShipmentsRequest` (filtres/tri/curseur bornés).
- `ShipmentResource` (détail + relations conditionnelles), `ShipmentListResource` (read model), `TimelineEventResource`.
- `ShipmentController` : `index` (QueryBus), `store` (201 + Location), `show`, `timeline`, `advance`, `close` — **aucune logique métier dans le contrôleur**, tout passe par les bus.

### Tracking public
- `GET /v1/public/tracking?q=…` : résolution multi-type (réf dossier → BL/HBL/MBL → AWB → conteneur), validation du format d'entrée, **aucun montant/document/nom de tiers**, 50 événements max (status_change + tracking uniquement).

### OpenAPI
- **Scramble** installé : spec OpenAPI 3.1 générée depuis le code (`php artisan scramble:export`), UI `/docs/api` en dev. 8 endpoints documentés automatiquement.

## 2. Tests exécutés (serveur `artisan serve`, curl)

| Test | Résultat |
|---|---|
| `GET /v1/health` | ✓ 200 |
| Tracking public par référence dossier | ✓ statut + trajet + ETA + 5 événements filtrés |
| Tracking public par n° conteneur `MSKU8842016` | ✓ résout le même dossier |
| Liste dossiers tenant demo (4 ouverts, retard signalé, compteurs) | ✓ |
| Requête sans tenant | ✓ 400 `application/problem+json` |
| POST invalide | ✓ 422 RFC 9457, 8 champs en erreur |
| Transition illégale `transit→closure` | ✓ 422 + `error_code: shipment.invalid_workflow_transition` |
| UUID inexistant | ✓ 404 problem |
| Export OpenAPI 3.1 | ✓ 8 chemins |

## 3. Décisions
1. Erreurs métier exposées avec `error_code` stable — le frontend traduit par code, jamais par parsing de message.
2. En-tête `X-Tenant-Slug` **strictement limité à local/testing** — en production seul l'utilisateur authentifié détermine le tenant.
3. Scramble (génération depuis le code + attributs) plutôt que annotations swagger-php — zéro dérive spec/code, CI exportera la spec pour `packages/api-client`.
4. Résolution tracking public sans scope tenant assumée et contrôlée : lecture minimale, validation d'entrée, rate limiting agressif.

## 4. Reste (Étape 12)
Contrôleurs CRUD des autres modules (CRM, Ocean, Air, Road, Pricing, Billing, Documents, Admin) sur ce même pattern.

---

*Fin de l'Étape 11. En attente de validation avant l'Étape 12 — Contrôleurs.*
