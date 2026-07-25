# Architecture — guide opérationnel

Référence complète : [étape 2](../etape-02-architecture-logicielle.md) (ADR), [étape 3](../etape-03-diagrammes.md) (C4/ERD). Ici : ce qu'il faut savoir pour contribuer.

## Monolithe modulaire

18 modules sous `apps/api/src/Modules/`, 4 couches chacun :

```
Domain/          PHP pur — agrégats, VO, événements, ports. JAMAIS d'Illuminate.
Application/     Cas d'usage — FooCommand + FooHandler (même dossier), queries (read models).
Infrastructure/  Eloquent (modèles suffixés Model), adapters, providers.
Interface/       Http (controllers, routes.php, portal_routes.php, public_routes.php), Console, Listeners.
```

**Règles (cassent la CI — tests `tests/Architecture/`)** : Domain sans framework ; Domains métier étanches entre eux (communication = événements + contrats) ; ACL (OdooSync, CarrierConnect) jamais importées par un Domain.

## Multi-tenant — 3 couches
1. `ResolveTenant` middleware → `TenantContext` (tenant de l'utilisateur authentifié).
2. Trait `BelongsToTenant` (scope Eloquent + auto-fill).
3. **RLS PostgreSQL** (`app.tenant_id`) — garantie finale : même une requête brute ne fuit pas.
Jobs : passer `tenantId` au constructeur, `TenantContext::set()` en tête de `handle()`.

## Écritures vs lectures
- Invariants métier → CommandBus (transaction auto) → agrégat → repository → `DomainEventPublisher::publishFrom()` (outbox même transaction + listeners après commit).
- CRUD simple sans invariant → contrôleur + Eloquent direct (décision étape 12).
- Lectures → QueryBus / vues SQL (`v_shipments_list`…), pagination curseur.

## Recettes

**Endpoint dans un module existant** : contrôleur `Interface/Http/Controller` → route + `->can('module.action')` dans `routes.php` → permission au `PermissionSeeder` si nouvelle → `make openapi` → test feature.

**Nouveau module** : dossier 4 couches + ServiceProvider (bindings) enregistré dans `bootstrap/providers.php` + migrations préfixées + règles dans `tests/Architecture/`.

**Connecteur compagnie** : entrée dans `CarrierRegistry::API_KEY_CARRIERS` (URL + header) — ou classe dédiée si auth exotique (cf. `MaerskConnector` OAuth2) ; mappings statuts → `CarrierStatusMappingSeeder` ; credentials chiffrés par tenant dans `carrier_api_credentials`. Zéro modification du cœur.

**Événement de domaine** : classe `Domain/Event` implémentant `DomainEvent` → `record()` dans l'agrégat → listeners via `Interface/Listener` (autre module) ; l'outbox alimente webhooks sortants et intégrations.

## Frontend
`features/<domaine>` = miroir des modules backend. HTTP uniquement via `@silaris/api-client` (`api` typé, `rawApi` transitoire). RBAC UI : `useCan('perm')` — cosmétique, la vraie autorisation est côté API. Tokens design : `globals.css` (source : étape 4).
