# Étape 19 — Connecteurs Compagnies Maritimes

**Projet :** SILARIS · **Statut :** Exécuté et vérifié (pipeline complet sur base réelle) · **Prérequis :** Étape 18 validée

---

## 1. Architecture livrée (conforme ADR-06)

### Contrats Domain (module Tracking)
- `CarrierTrackingProvider` : port unique — `trackContainer`, `trackBillOfLading`, `capabilities()`.
- `TrackingResult` / `TrackingEventDto` : sortie normalisée DCSA de tous les connecteurs.
- `CarrierUnavailable` : signal de panne — replanification sans perte.

### CarrierConnect (ACL Infrastructure)
| Composant | Rôle |
|---|---|
| `AbstractDcsaConnector` | HTTP (timeout 20 s, 2 retries), parse le format d'événements **DCSA T&T v2.x** (standard adopté par les majors), journalisation systématique, normalisation |
| `MaerskConnector` | OAuth2 client credentials (token caché jusqu'à expiration) + Consumer-Key |
| `ApiKeyDcsaConnector` | Générique paramétré — couvre **MSC, CMA CGM, Hapag-Lloyd, COSCO, Evergreen, ONE, OOCL, Yang Ming** (seuls varient URL de base + nom d'en-tête de clé) |
| `FakeCarrierConnector` | Simulateur déterministe (traversée Shanghai→Abidjan qui avance dans le temps réel) — **jamais résolu en production** |
| `CarrierRegistry` | Résolution par SCAC : credentials tenant (chiffrés) → connecteur réel ; sinon hors prod → simulateur ; sinon → CarrierUnavailable (fallback : saisie manuelle, RF-TRK-08) |
| `CircuitBreaker` | 5 échecs consécutifs → circuit ouvert 15 min, **persisté en base** (survit aux redémarrages) |
| `ExchangeLogger` | Chaque appel tracé : opération, sujet, HTTP, latence, erreur (RF-TRK-06) |
| `StatusNormalizer` | Statuts propriétaires → DCSA via `carrier_status_mappings` (72 seedés) ; inconnu → `UNKN` conservé brut (aucune perte, mapping ajouté ensuite) |

### Ingestion (`TrackingIngestionService`)
1. **Déduplication** par `event_hash` (`insertOrIgnore` sur index unique) — repolling strictement idempotent.
2. Insertion `tracking_events` (partitionnée) + entrée **timeline dossier** libellée en français.
3. **Jalons automatiques** : `DEPA` → ATD, `ARRI` → ATA (via l'agrégat, jamais d'écrasement d'une valeur existante).
4. **Nouvelle ETA** → `Shipment::updateEta()` → `DelayDetected` si dérive ≥ seuil tenant → outbox → notifications.

### Orchestration
- `tracking:refresh` : par tenant, abonnements actifs dus (fréquence `tracking_refresh_minutes` du tenant), circuit ouvert → skip, échecs comptés.
- Scheduler : refresh toutes les 30 min, `db:create-partitions` mensuel (+3 mois d'avance), refresh horaire de `mv_revenue_operational`.

## 2. Vérifié sur base réelle
| Test | Résultat |
|---|---|
| Run 1 (simulateur) | 6 événements ingérés, séquence `GTIN→LOAD→DEPA→DEPA→TRSH→ARRI` |
| **Run forcé n°2** | **0 nouveau, 0 doublon hash** — idempotence prouvée |
| ATA auto | posée à l'événement ARRI (dossier TAL-2026-00128) |
| Timeline dossier | 12 entrées, libellés français, source `carrier_api` |
| Fenêtre de polling | abonnement récent ignoré sans `--subscription` (fréquence tenant respectée) |

## 3. Incident corrigé
Simulateur : ancre temporelle incluant l'heure d'exécution → hashes instables → dédup inopérante. Fix : timestamps déterministes (heure fixe dérivée du numéro). Leçon générale consignée : **tout événement ingéré doit avoir un timestamp source stable**, jamais dérivé du moment du poll.

## 4. Reste
- Credentials réels par compagnie = configuration tenant (écran admin intégrations — backlog UI) ; specs d'auth déjà implémentées (OAuth2 Maersk, clés API autres).
- Webhooks entrants compagnies (push vs poll) — extension future du même pipeline.
- Connecteurs aériens : mêmes contrats, entités AWB (phase ultérieure planifiée).

---

*Fin de l'Étape 19. En attente de validation avant l'Étape 20 — Intégration Odoo.*
