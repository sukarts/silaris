# Étape 4 — Maquettes UI/UX

**Projet :** SILARIS — Plateforme de Gestion de Transit International (TMS)
**Version :** 1.0
**Statut :** En attente de validation
**Prérequis :** Étapes 1–3 validées
**Maquette interactive :** [maquettes/silaris-maquettes.html](maquettes/silaris-maquettes.html) — 5 écrans, thèmes clair/sombre, navigable

---

## 1. Principes UX

1. **Densité maîtrisée** : logiciel d'exploitation utilisé 8 h/jour — l'information dense doit rester scannable. Tableaux compacts, hiérarchie typographique nette, jamais de décor gratuit.
2. **L'état se lit d'un coup d'œil** : statuts en pastilles colorées avec point, priorités en carrés colorés, compteurs de documents (6/6), alertes hiérarchisées par gravité. La couleur sémantique (vert/ambre/rouge) est distincte de l'accent orange.
3. **Le dossier est le centre de gravité** : tout converge vers la fiche dossier (stepper workflow + tabs + timeline). Une seule timeline chronologique mélange tracking API, actions internes et documents — source de chaque événement étiquetée (`MSC API` / `interne`).
4. **Zéro impasse** : chaque alerte, chaque KPI, chaque ligne de tableau est cliquable vers l'objet concerné.
5. **Trois interfaces distinctes** pour trois publics : app interne (dense, sidebar), portail client (épuré, rassurant, vocabulaire non-technicien : « Arrivée estimée » et non « ETA »), page publique (une seule action, aucune donnée sensible).
6. **Mobile** : app interne responsive ≥ tablette ; interface chauffeur et portail client mobile-first ; page publique 100 % mobile.
7. **Accessibilité** : contrastes AA minimum, focus visible partout, navigation clavier complète (⌘K recherche globale), `aria-*` sur tabs/steppers, `prefers-reduced-motion` respecté, jamais la couleur seule comme porteur d'information (pastilles = couleur + libellé).

---

## 2. Design tokens

### 2.1 Couleurs

Identité ancrée dans l'univers du fret : marine profond (coque/encre), orange conteneur (accent), teal maritime (liens/données secondaires).

| Token | Clair | Sombre | Usage |
|---|---|---|---|
| `--paper` | `#F6F7F9` | `#101820` | Fond de page (gris froid biaisé bleu, jamais gris pur) |
| `--surface` | `#FFFFFF` | `#18232E` | Cartes, panneaux, tableaux |
| `--ink` | `#16222E` | `#E8EDF1` | Texte principal |
| `--ink-2` / `--ink-3` | `#4A5A68` / `#8595A3` | `#A9B7C2` / `#6E7E8C` | Texte secondaire / tertiaire |
| `--line` | `#E3E8ED` | `#243240` | Bordures, séparateurs |
| `--accent` | `#E8642C` | `#F0763F` | **Orange conteneur** — CTA primaire, étape courante, badge. Usage parcimonieux |
| `--sea` | `#1D5F73` | `#4FA3BC` | Teal maritime — liens, références, données secondaires graphiques |
| `--ok` | `#2E7D4F` | `#54B37F` | Succès, livré, complet |
| `--warn` | `#B97F10` | `#D9A23C` | Attention, douane, docs partiels |
| `--crit` | `#C0392B` | `#E06A5B` | Retard, surestaries, bloquant |
| `--nav-bg` | `#16222E` | `#0C1319` | Sidebar (marine sombre dans les deux thèmes — repère constant) |

Règle : chaque sémantique a sa variante `-soft` (fond de pastille). Implémentation : CSS variables sur `:root`, redéfinies sous `prefers-color-scheme: dark` et surchargées par `[data-theme]` (toggle utilisateur > préférence OS).

### 2.2 Typographie

| Rôle | Police (maquette et produit final) | Détail |
|---|---|---|
| UI / titres | **Instrument Sans** (variable 400–700) | Embarquée en data URI dans la maquette ; auto-hébergée woff2 dans le produit |
| Données, références, dates | **IBM Plex Mono** (400/600) | `tabular-nums` sur toutes colonnes chiffrées |

Fallbacks : Avenir Next / Segoe UI / system-ui (sans) ; SF Mono / ui-monospace (mono).

Échelle : 11 (labels uppercase, letter-spacing .08em) · 12–13 (corps, tableaux) · 14 (base) · 20 (titre page) · 26 (KPI). Toutes les colonnes chiffrées en mono `tabular-nums`.

### 2.3 Espacements, formes, élévation

- Grille d'espacement 4 px (4/8/12/16/24).
- Rayons : 6–7 px (boutons, inputs), 10 px (cartes), 20 px (pastilles). Pas de `rounded-lg` uniforme.
- Ombres discrètes (`--shadow`), une seule élévation — la hiérarchie vient des bordures et du fond, pas des ombres.
- Transitions 150–180 ms, désactivées si `prefers-reduced-motion`.

---

## 3. Architecture de l'information

### 3.1 App interne — navigation latérale

```
SILARIS
├── Tableau de bord
├── OPÉRATIONS
│   ├── Dossiers (badge compteur actifs)
│   ├── Bookings
│   ├── Conteneurs
│   ├── Aérien
│   └── Routier
├── COMMERCIAL
│   ├── CRM
│   ├── Cotations
│   └── Facturation
└── RESSOURCES
    ├── Documents
    ├── Rapports
    └── Administration
```

- Sidebar fixe 216 px, fond marine, item actif = fond éclairci + barre accent 2 px. Réduite en icônes < 1280 px, drawer < 900 px.
- **Topbar** : recherche globale (⌘K, palette de commandes), sélecteur tenant·société·agence, cloche notifications (SSE), avatar/menu.
- Navigation filtrée par permissions RBAC : un commercial ne voit pas Administration.

### 3.2 Portail client — navigation horizontale

Mes dossiers · Documents · Factures · Devis · Préférences. Hero teal avec gestionnaire de compte + bouton contact. Aucune terminologie interne.

### 3.3 Page publique

Une seule fonction : champ numéro → statut + route + timeline. Marque du tenant (white-label), lien vers portail. Rate-limited, aucune donnée sensible (pas de montants, noms tiers, documents).

---

## 4. Inventaire des écrans (43)

| # | Écran | Sect. | Priorité |
|---|---|---|---|
| 1 | Login (+ MFA, reset, invitation) | Auth | P1 |
| 2 | **Tableau de bord** (par rôle, widgets configurables) | App | P1 · maquetté |
| 3 | **Liste dossiers** (filtres, chips, vues sauvegardées, bulk) | App | P1 · maquetté |
| 4 | **Détail dossier** (stepper, 8 tabs : aperçu, conteneurs, BL, documents, cotation/facturation, tâches, communication, audit) | App | P1 · maquetté |
| 5 | Création dossier (wizard : client → mode/trajet → marchandises → docs requis) | App | P1 |
| 6–8 | Bookings (liste, détail cut-offs, création) | App | P1 |
| 9–10 | Conteneurs (liste + historique) | App | P1 |
| 11–12 | Consolidations LCL (composition MBL/HBL) | App | P1 |
| 13–15 | Aérien (liste AWB, détail MAWB/HAWB, création) | App | P2 |
| 16–19 | Routier (missions, planning, flotte, chauffeurs) | App | P2 |
| 20 | Interface chauffeur mobile (missions du jour, POD signature+photos) | App | P2 |
| 21–23 | CRM (liste parties, fiche 360°, opportunités kanban) | App | P1 |
| 24 | Réclamations | App | P2 |
| 25–27 | Cotations (liste, éditeur lignes+marge, grilles tarifaires) | App | P2 |
| 28–29 | Facturation (liste + détail, statut sync Odoo) | App | P2 |
| 30 | Moniteur sync Odoo (files, conflits, journal) | App | P4 |
| 31–32 | Documents (bibliothèque, visionneuse+versions) | App | P1 |
| 33 | Rapports (galerie, planification) | App | P4 |
| 34–38 | Administration (utilisateurs, rôles/permissions matrice, sociétés/agences, référentiels, éditeur workflow, notifications templates, audit) | App | P1 |
| 39 | **Portail client — accueil** | Portail | P2 · maquetté |
| 40 | Portail — détail expédition (timeline simplifiée) | Portail | P2 |
| 41 | Portail — documents / factures / devis (acceptation en ligne) | Portail | P2 |
| 42 | **Suivi public** | Public | P2 · maquetté |
| 43 | Emails transactionnels (gabarits notification) | Transverse | P2 |

Priorités = phases roadmap. Les 5 écrans maquettés fixent le langage visuel ; les autres suivent le même système.

---

## 5. Composants clés (design system → package `ui/`)

| Composant | Spécification |
|---|---|
| **StatusPill** | Pastille arrondie, point coloré + libellé. Variantes : transit (teal), ok (vert), warn (ambre), crit (rouge), muted (gris) |
| **PriorityDot** | Carré 8 px : rouge (haute), ambre (urgente), gris (normale) + tooltip |
| **WorkflowStepper** | Étapes du workflow tenant : fait (vert plein), courante (accent + halo), à venir (contour). Scroll horizontal mobile |
| **Timeline** | Rail vertical, nœuds colorés par nature, source étiquetée (API/interne), horodatage UTC + local |
| **DataTable** | Tri, sélection multiple, colonnes configurables, ligne cliquable, pagination curseur, densité compacte/confortable, export |
| **FilterBar** | Chips actives supprimables, ajout de filtre, vues enregistrées |
| **KpiCard** | Label uppercase, valeur tabular-nums, delta coloré avec flèche |
| **DocChecklist** | Ligne par document : type, statut, version, taille, auteur, date ; actions upload/télécharger |
| **GlobalSearch** | Palette ⌘K : résultats groupés par type (dossiers, clients, conteneurs, factures), navigation clavier |
| **AlertItem** | Icône gravité + titre gras + contexte + ancienneté ; fond sémantique soft |
| **RouteViz** | Origine → destination, ligne pointillée maritime, codes UN/LOCODE |
| **EtaBadge** | ETA avec ancienne valeur barrée si recalculée |
| **TenantSwitcher** | Sélecteur société·agence dans la topbar |
| **EmptyState / Skeleton** | États vides avec action ; chargement squelette (jamais de spinner plein écran) |

Base technique : Tailwind + primitives Radix UI (accessibilité native), tokens ci-dessus en CSS variables.

---

## 6. Patterns d'interaction

- **Édition** : panneaux latéraux (sheet) pour édition rapide sans perdre le contexte liste ; pages pleines pour création complexe (wizard dossier).
- **Optimistic UI** : changements de statut appliqués immédiatement, rollback si erreur (TanStack Query).
- **Temps réel** : timeline et notifications rafraîchies par SSE ; indicateur « Sync MSC il y a 40 min » sur les données tracking.
- **Drag & drop** : upload documents, réorganisation widgets dashboard, kanban opportunités.
- **Raccourcis** : ⌘K recherche, N nouveau dossier (contextuel), G+D dossiers…
- **Confirmations** : destructives = modale avec saisie de confirmation ; le reste = undo toast 5 s.
- **Erreurs formulaires** : validation Zod inline au blur, résumé en tête au submit, messages actionnables.

---

## 7. Décisions prises

1. **Identité** : marine `#16222E` + orange conteneur `#E8642C` + teal maritime — ancrée métier, pas de palette générique.
2. **Sidebar marine constante** dans les deux thèmes — repère visuel stable, identité forte.
3. **Timeline unifiée** avec étiquette de source (API compagnie vs interne) — confiance dans la donnée.
4. **Vocabulaire différencié** : ETA/ETD/cut-off en interne ; « Arrivée estimée » côté client.
5. **Webfonts produit** : Instrument Sans + IBM Plex Mono, auto-hébergées (pas de CDN — RGPD + perf).
6. **Radix UI** comme base des composants (accessibilité) plutôt que bibliothèque lourde type MUI.
7. 5 écrans maquettés haute fidélité fixent le système ; 38 écrans restants spécifiés dans l'inventaire, construits avec les mêmes composants.

---

## 8. Tâches restantes

- Maquettes complémentaires produites au fil des étapes de développement (wizard création dossier, éditeur workflow, moniteur Odoo).
- Webfonts à intégrer au setup frontend (Étape 15).
- Étape 5 : structure des dossiers du monorepo.

---

*Fin de l'Étape 4. En attente de validation avant l'Étape 5 — Structure des dossiers.*
