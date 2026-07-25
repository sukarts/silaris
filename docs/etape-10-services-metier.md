# Étape 10 — Services Métier

**Projet :** SILARIS · **Statut :** Exécuté et vérifié · **Prérequis :** Étape 9 validée
**Volume :** 116 fichiers PHP cumulés sous `src/Modules/`

---

## 1. Livrables

### Shared — bus et publication d'événements
| Composant | Rôle |
|---|---|
| `CommandBus` / `QueryBus` (ports) + `TransactionalCommandBus` / `SimpleQueryBus` | Handler résolu par convention (`FooCommand` → `FooHandler`, même namespace) ; commandes en transaction DB, queries hors transaction |
| `DomainEventPublisher` | **Outbox transactionnel** : insert `outbox_events` dans la même transaction que la donnée + dispatch applicatif `DB::afterCommit` — jamais d'événement perdu ni fantôme (ADR-05) |

### Shipment — application complète du pattern
| Composant | Rôle |
|---|---|
| `WorkflowEngine` (Domain service) + port `WorkflowDefinitionProvider` | Étape initiale, transitions autorisées, documents requis par étape, conditions de clôture (évaluées sur faits fournis) |
| `EloquentWorkflowDefinitionProvider` | Lit la config tenant, cache 5 min, résolution du workflow par défaut (spécificité mode/direction > `any`) |
| `ShipmentMapper` + `EloquentShipmentRepository` | Agrégat ↔ Eloquent ; `save(aggregate, extraAttributes)` — colonnes hors domaine passées à la création uniquement |
| `SequenceReferenceGenerator` | Référence `{SOCIÉTÉ}-{ANNÉE}-{SEQ:5}` via `next_sequence()` pg (sans trou, même transaction) |
| `CreateShipmentHandler` | Résout workflow par défaut, génère référence, crée l'agrégat, persiste, publie événements |
| `AdvanceWorkflowStepHandler` | **Garde documentaire** : vérifie les documents requis pour l'étape cible avant transition ; exception typée `MissingRequiredDocuments` |
| `CloseShipmentHandler` | Établit les faits (`delivery_confirmed`, `invoice_issued`) depuis la base, le moteur juge selon la config tenant |
| `ListShipmentsHandler` | Read model direct sur `v_shipments_list` : filtres, recherche, tri contrôlé, **pagination par curseur** |
| `GetShipmentTimelineHandler` | Timeline avec filtre visibilité client (portail) |

### Pricing — moteur de cotation
| Composant | Rôle |
|---|---|
| `QuoteCalculator` (Domain, pur) | Applique grilles vente + achat : par conteneur (taille), kg (tranches, **poids taxable aérien**), m³, **w/m maritime** (max(t, m³)), forfait, % valeur (assurance), **minimums de perception** ; marge = vente − achat par ligne |
| `CargoSpec` | `airChargeableKg()`, `seaWmUnits()` — règles métier dans le VO |
| `EloquentTariffProvider` | Grilles applicables par mode/trajet/date ; **grille dédiée client prioritaire** sur grille générale (dédoublonnage par service) |

Providers enregistrés : `SharedServiceProvider` (bus + TenantContext), `ShipmentServiceProvider`, `PricingServiceProvider`.

## 2. Tests bout en bout exécutés (base démo réelle)

| Test | Résultat |
|---|---|
| `CreateShipmentCommand` via CommandBus | ✓ `TAL-2026-00129` (séquence continue après 128), statut `creation`, eta_initial figée |
| Outbox alimentée dans la même transaction | ✓ `shipment.created` présent |
| Avance `creation→booking` sans documents requis | ✓ **rejetée** : « Documents requis manquants : commercial_invoice, packing_list » |
| Même transition après fourniture des documents | ✓ statut `booking` |
| Transition illégale `booking→delivery` | ✓ rejetée (InvalidWorkflowTransition) |
| Cotation 2×40HC CNSHA→CIABJ sur grille démo | ✓ freight 4 900 USD + THC 570 000 XOF + douane 350 000 + livraison 300 000 (multi-devises par ligne) |
| `ListShipmentsQuery` sur vue | ✓ 4 dossiers ouverts, curseur OK |

## 3. Décisions

1. **Résolution de handler par convention** plutôt que mapping explicite — zéro registre à maintenir, erreur claire si handler absent.
2. **Faits de clôture évalués par l'Application, jugés par le Domain** — le moteur ne connaît ni missions ni factures ; testable sans DB.
3. Cache 5 min sur les définitions de workflow — invalidation naturelle, config peu volatile.
4. Multi-devises par ligne de cotation assumé — la conversion vers la devise du devis se fait à la création du devis (taux datés), pas dans le calculateur.
5. Priorité grille client > grille générale, dédoublonnée par (service, unité, taille).

---

*Fin de l'Étape 10. En attente de validation avant l'Étape 11 — API REST.*
