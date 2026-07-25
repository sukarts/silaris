# Étape 17 — Écrans CRUD (passe 1)

**Projet :** SILARIS · **Statut :** Passe 1 exécutée et vérifiée (navigateur) · **Prérequis :** Étape 16 validée

---

## 1. Livrables passe 1

### Backend
- `GET /v1/shipments/{id}` enrichi : bloc `workflow` (étapes ordonnées + étape courante + **transitions autorisées**) — l'UI propose exactement ce que le moteur permet.

### Frontend
| Écran | Contenu |
|---|---|
| **Détail dossier** `/shipments/[id]` | Breadcrumb, référence + StatusPill + point priorité, **boutons de transition dynamiques** (issus du moteur), **WorkflowStepper** (fait/courant/à venir), grille infos (client, agent, agence, incoterm, ETD/ETA/ATD/ATA/ETA initiale), table marchandises, notes, **timeline** avec source étiquetée (interne/API compagnie) et nœuds colorés par type |
| **Création dossier** `/shipments/new` | Selects alimentés par l'API (clients, sociétés→agences en cascade, incoterms), UN/LOCODE uppercase, gardes RBAC, redirection vers le détail créé |
| **CRM** `/crm` | Filtres par type (chips), recherche, création inline (client/prospect), badges typés, **conversion prospect→client** en 1 clic |
| **Cotations** `/quotes` + `/quotes/new` | Liste avec statuts + **marge estimée par devis** ; **simulateur** : mode/trajet/conteneurs/poids/volume/valeur → lignes calculées (PU, vente, achat, marge, badge « minimum »), totaux par devise |
| **Facturation** `/billing` | Liste complète (type, statut, statut de paiement Odoo, HT/TTC, échéance), **validation en 1 clic** (numéro légal attribué) pour les porteurs de `invoices.validate` |
| Liste dossiers | Lien vers détail + bouton « Nouveau dossier » (RBAC) |

Composants ajoutés : `WorkflowStepper`, `Field`/`inputClass`/boutons partagés.

## 2. Vérifié en réel (navigateur)
- Détail TAL-2026-00128 : stepper à « Transit », bouton **« → Arrivée »** (seule transition autorisée).
- Clic → statut passe à « Arrivée », le bouton devient **« → Dédouanement »** — chaîne UI → CommandBus → agrégat → moteur → DB → refetch démontrée.
- Timeline avec sources API compagnie/interne.
- Toutes les nouvelles routes : 200.

## 3. Reste (passe 2 — intégrée aux étapes suivantes)
- Écrans Bookings/Conteneurs/BL dédiés (données déjà visibles côté API ; UI à l'Étape 19 avec le tracking).
- Aérien, Routier (missions/POD UI), Documents (upload UI), Admin (users/roles/workflows UI).
- Formulaire devis complet (persistance après simulation) + PDF.
- Recherche globale ⌘K (Meilisearch — Étape 19+).

---

*Fin de l'Étape 17 (passe 1). En attente de validation avant l'Étape 18 — Portail client.*
