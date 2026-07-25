# Étape 16 — Tableau de Bord

**Projet :** SILARIS · **Statut :** Exécuté et vérifié (navigateur) · **Prérequis :** Étape 15 validée

---

## 1. Livrables

### Backend — `GET /v1/dashboard` (module Reporting, permission `dashboard.read`)
Agrégat unique (1 seul aller-retour) :
- **KPIs** : dossiers actifs (+ répartition import/export), conteneurs actifs, retards (vs ETA initiale), documents manquants, **CA opérationnel du mois** (factures validées/synced, hors proforma).
- **Volumes 6 mois** : dossiers créés par mois et par sens.
- **Alertes fusionnées et priorisées** (max 8) : franchises surestaries ≤ 3 j (critique si dépassée) via `v_demurrage_alerts`, **cut-offs sous 48 h** (VGM/documentaire/portuaire, le plus proche affiché) depuis les bookings actifs, retards via `v_active_delays` (critique si ≥ 5 j).
- **Dossiers récents** : tri par uuid v7 (= temporel), 6 derniers.
- **Scope agences appliqué** — même règle que la liste des dossiers.

### Frontend — page dashboard
- 5 cartes KPI (valeurs `tabular-nums`, tonalités sémantiques : retards rouges, docs ambre).
- **Histogramme volumes** import/export en CSS pur (accent orange / teal, conforme maquette), tooltips natifs, légende.
- Panneau alertes : fonds sémantiques (critique/avertissement), contexte référence dossier en mono.
- Table dossiers récents : StatusPill (badge Retard prioritaire), lien vers /shipments.
- Rafraîchissement automatique 60 s (TanStack Query `refetchInterval`).

## 2. Vérifications
- Endpoint curl : KPIs corrects (5 actifs = 3 imports + 2 exports, 2 conteneurs, 1 retard, CA 5 570 000 XOF), volumes Juin/Juil, alerte retard TAL-2026-00128.
- Navigateur : rendu complet conforme à la maquette Étape 4 (thème sombre), données 100 % réelles.

## 3. Incident corrigé
`next build` (Étape 15) exécuté pendant que le serveur dev tournait → artefacts `.next` corrompus (MODULE_NOT_FOUND, pages blanches). Purge `.next` + redémarrage dev. **Règle retenue : jamais de build prod sur le répertoire d'un dev server actif** (le CI utilisera un environnement séparé).

## 4. Reste
- Widgets configurables par utilisateur (drag & drop, table `dashboard_widgets` prête) — Étape 17+.
- Dashboards par rôle (variantes commercial/comptable) — s'appuieront sur le même endpoint filtré par permissions.

---

*Fin de l'Étape 16. En attente de validation avant l'Étape 17 — Écrans CRUD.*
