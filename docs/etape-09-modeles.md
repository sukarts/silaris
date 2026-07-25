# Étape 9 — Modèles

**Projet :** SILARIS · **Statut :** Exécuté et vérifié · **Prérequis :** Étape 8 validée

---

## 1. Livrables — 84 fichiers PHP sous `apps/api/src/Modules/`

### Noyau partagé (`Shared/`)
| Fichier | Rôle |
|---|---|
| `Domain/AggregateRoot.php` | Enregistrement d'événements de domaine (collect-then-dispatch) |
| `Domain/DomainEvent.php` | Interface : type, agrégat, horodatage, payload outbox |
| `Domain/Exception/DomainException.php` | Base exceptions métier → 422 HTTP avec `errorCode()` |
| `Domain/ValueObject/Money.php` | Montants en sous-unité entière + devise — **zéro float**, add/subtract avec contrôle devise |
| `Domain/ValueObject/Locode.php` | UN/LOCODE validé par regex |
| `Domain/ValueObject/WeightKg.php` / `VolumeM3.php` | Grammes/litres entiers ; `airChargeableWeight()` (règle IATA /6000) |
| `Infrastructure/Tenancy/TenantContext.php` | Contexte tenant scoped ; **positionne `app.tenant_id` pg à chaque `set()`** → RLS active |
| `Infrastructure/Persistence/BaseModel.php` | PK uuid v7, guarded désactivé (écritures via handlers uniquement) |
| `Infrastructure/Persistence/Concerns/BelongsToTenant.php` | Global scope + auto-fill tenant_id — 2e ligne de défense (RLS = garantie finale) |
| `Infrastructure/SharedServiceProvider.php` | TenantContext en singleton scoped (reset entre jobs) |

### Domaine Shipment — agrégat de référence (pattern pour tous les modules cœur)
- **Enums PHP** : `Direction`, `TransportMode` (avec `isSea()`), `Priority` — castés directement par Eloquent.
- **VO `Schedule`** : ETD/ETA/ATD/ATA + ETA initiale, `delayHours()` = mesure de dérive.
- **Agrégat `Shipment`** (PHP pur, zéro Laravel) : `create()` / `reconstitute()` (sans événements), `advanceTo()` (transitions validées, exception typée), `updateEta()` (émet `DelayDetected` si dérive ≥ seuil tenant), `close()` (conditions de clôture injectées), release d'événements.
- **4 événements** : ShipmentCreated, WorkflowStepAdvanced, DelayDetected, ShipmentClosed.
- **Port** `ShipmentRepository` (implémentation Eloquent + mapper : Étape 10).

### Modèles Eloquent (Infrastructure) — 44 classes, 12 modules
Tenancy (3), Identity (3 — `UserModel` = Authenticatable + Sanctum + MFA chiffré + hidden), Crm (6 — `PortalAccountModel` = guard distinct), Shipment (8 dont workflow), Ocean (9), Air (2), Road (6), Tracking (3), Documents (2 — `s3_key` hidden), Pricing (4), Billing (3), Referential (8 — PK string, pas de scope), Audit (1 — timestamps off).

Conventions appliquées : suffixe `Model`, casts `immutable_datetime`/`decimal:n`/`array` (jsonb), enums domaine castés sur ShipmentModel, relations cross-modules **en lecture seulement** (compromis documenté : couche Infrastructure uniquement, jamais Domain).

## 2. Vérifications exécutées

| Test | Résultat |
|---|---|
| `php -l` sur les 84 fichiers | ✓ 0 erreur |
| Chargement dossier démo + 5 relations (client, agent, events, cargo, branch) | ✓ `TAL-2026-00128 / SICOA SARL / Awa Koné` |
| Cast enum : `mode->name = SeaFcl`, `isSea() = true` | ✓ |
| Auto-référence MBL → HBL (`houses`) + snapshot jsonb consignee | ✓ |
| Scope tenant : 9 parties visibles avec contexte | ✓ |
| Agrégat pur : transition valide, **transition interdite rejetée**, retard détecté → 3 événements émis (`created`, `step_advanced`, `delay_detected`) | ✓ |
| `Money` : 1470000.50 + 285000.25 = 1755000.75 XOF sans float | ✓ |

## 3. Corrections en cours de route
- Bug zsh (`set -- $var` sans split) → 9 fichiers nommés avec espaces, supprimés et régénérés.
- `laravel/tinker` ajouté en dev.
- `SharedServiceProvider` enregistré dans `bootstrap/providers.php`.

## 4. Décisions
1. **`reconstitute()` séparé de `create()`** — la réhydratation depuis la base n'émet jamais d'événements.
2. **Transitions injectées dans l'agrégat** (liste résolue par le WorkflowEngine) — l'agrégat valide, le moteur configure : le domaine ignore la table workflow_steps.
3. Relations Eloquent **cross-modules tolérées en Infrastructure** pour les lectures — les écritures restent cloisonnées par module (contrat/événements).
4. `UserModel.mfa_secret` en cast `encrypted` — chiffré applicativement au repos.

---

*Fin de l'Étape 9. En attente de validation avant l'Étape 10 — Services métier.*
