# Étape 18 — Portail Client

**Projet :** SILARIS · **Statut :** Exécuté et vérifié (navigateur + curl) · **Prérequis :** Étape 17 validée

---

## 1. Livrables

### Backend — routes `/v1/portal/*` (guard portail + tenant, jamais accessibles aux tokens internes)
| Endpoint | Comportement |
|---|---|
| `GET /portal/shipments` (+ `/{id}`) | Dossiers de la société du compte uniquement (`party_id`) ; détail = trajet, conteneurs+scellés, **événements client-visibles seulement** (status_change + tracking) — jamais marge, notes internes, commentaires internes |
| `GET /portal/documents` (+ download-url) | Documents `visibility=client` des dossiers de la société (ou rattachés à la société) ; téléchargement via **URL signée 10 min** |
| `GET /portal/invoices` | Factures validées+ (jamais les brouillons) avec statut de paiement Odoo |
| `GET /portal/quotes` + accept/reject | Devis envoyés, **acceptation/refus en ligne** ; la création du dossier reste une action interne |

### Frontend
| Écran | Contenu |
|---|---|
| `/portal/login` | Login dédié (guard `kind=portal` dans le store — un compte portail ne peut pas entrer dans l'app interne et réciproquement) |
| `/portal` | Hero teal, table expéditions avec vocabulaire client (« En préparation », « Retard signalé », « Arrivée estimée ») |
| `/portal/shipments/[id]` | Statut + conteneurs + date d'arrivée mise en avant + historique simplifié |
| `/portal/documents` | Liste + téléchargement (URL signée, nouvel onglet) |
| `/portal/invoices` | Numéro, dossier, échéance, TTC, statut de règlement |
| `/portal/quotes` | Devis détaillés (lignes) + boutons **Accepter / Refuser** |
| **`/track` (public)** | Saisie n° dossier/BL/AWB/conteneur → statut + trajet + arrivée + timeline. Aucune authentification, rate-limité côté API |

## 2. Vérifié en réel
- API : SICOA ne voit que **ses 3 dossiers** (ceux de Bernabé absents), 2 factures, 5 documents client ; token interne sur `/portal/*` → **403**.
- Navigateur : login portail → accueil (3 expéditions, badge « Retard signalé » sur 00128), navigation dédiée.
- `/track` avec `MSKU8842016` → résout le dossier, statut « Arrivé à destination » (**reflète la transition faite à l'Étape 17**), timeline complète, aucune donnée sensible.

## 3. Décisions
1. Vocabulaire strictement séparé : jamais de jargon interne côté client (statuts traduits, « Arrivée estimée » vs ETA).
2. Acceptation de devis portail ≠ création de dossier — l'engagement opérationnel reste une décision d'agence.
3. Store d'auth unique avec `kind` — une session portail et une session interne ne se mélangent jamais (redirections 401 différenciées).

## 4. Reste
- Préférences de notification portail (matrice canal×événement — UI à l'Étape des notifications).
- Messagerie dossier client↔gestionnaire (module communication — backlog).
- Multi-utilisateurs par client (backlog Étape 1 §POR-06).

---

*Fin de l'Étape 18. En attente de validation avant l'Étape 19 — Connecteurs compagnies maritimes.*
