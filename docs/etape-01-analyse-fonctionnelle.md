# Étape 1 — Analyse Fonctionnelle

**Projet :** SILARIS — Plateforme de Gestion de Transit International (TMS)
**Version :** 1.0
**Date :** Juillet 2026
**Statut :** En attente de validation

---

## 1. Vision produit

SILARIS est une plateforme SaaS (ou installable en privé) destinée aux **transitaires, commissionnaires en douane et logisticiens**. Elle centralise la gestion complète des opérations de transit international — maritime, aérien, routier et multimodal, import et export — depuis la cotation jusqu'à la livraison finale, en remplaçant les traitements manuels (Excel, emails, dossiers papier).

**Ce que SILARIS est :** un système opérationnel de gestion de transit (dossiers, tracking, documents, cotations, facturation opérationnelle, portail client).

**Ce que SILARIS n'est pas :** un ERP comptable. Aucune écriture comptable, aucun grand livre, aucune TVA, aucun bilan. La comptabilité est déléguée à **Odoo** via une couche d'intégration API REST + Webhooks.

### Proposition de valeur

| Pour | Bénéfice |
|---|---|
| Le transitaire (exploitant) | Un dossier unique, un workflow clair, zéro ressaisie, tracking automatique |
| La direction | Visibilité temps réel : KPIs, retards, CA opérationnel, performance |
| Le commercial | CRM intégré, cotations rapides, conversion devis → dossier en 1 clic |
| Le client final | Portail self-service, suivi public, notifications proactives |
| L'éditeur (nous) | Multi-tenant, white-label ready, connecteurs extensibles, revenus récurrents |

---

## 2. Périmètre

### 2.1 Inclus (dans le produit)

1. Administration (référentiels, utilisateurs, rôles, sociétés, agences)
2. CRM (clients, prospects, fournisseurs, contacts, opportunités, réclamations)
3. Gestion des dossiers de transit avec moteur de workflow configurable
4. Module Maritime (FCL, LCL, MBL/HBL, bookings, consolidation, conteneurs, navires, voyages)
5. Module Aérien (MAWB/HAWB, vols, compagnies)
6. Module Routier (camions, remorques, chauffeurs, missions, POD)
7. Tracking automatique via connecteurs compagnies maritimes (9 compagnies au lancement)
8. Gestion documentaire (versioning, permissions, archivage, OCR prévu)
9. Moteur de cotation paramétrable
10. Facturation opérationnelle (devis, proforma, facture, avoir) — **sans écritures comptables**
11. Intégration Odoo (synchronisation bidirectionnelle)
12. Portail client sécurisé + page de suivi publique
13. Notifications multicanales (Email, SMS, WhatsApp Business, in-app)
14. Tableaux de bord par rôle + rapports (PDF, Excel)
15. API REST publique documentée (`/api/v1`)
16. Centre de communication par dossier (commentaires, emails, pièces jointes)
17. Tâches et approbations (assignation, échéances)
18. Système d'événements et webhooks sortants

### 2.2 Exclus (hors périmètre — géré par Odoo)

- Comptabilité générale, grand livre, journal, balance
- TVA et déclarations fiscales
- Bilan et états financiers
- Paie
- Gestion de trésorerie

### 2.3 Prévu pour plus tard (architecture prête, implémentation différée)

- OCR sur documents (structure prévue dès V1)
- Intégration GPS temps réel (module routier)
- Connecteurs compagnies aériennes (Air France KLM, Emirates, Turkish, Qatar, Ethiopian)
- Application mobile (PWA puis native)
- Fonctionnalités IA (prévision de retards, assistant, génération de réponses)

---

## 3. Acteurs et rôles

### 3.1 Rôles système (RBAC)

| # | Rôle | Description | Portée |
|---|---|---|---|
| 1 | **Super Administrateur** | Éditeur du logiciel. Gestion des tenants, licences, configuration plateforme | Plateforme (cross-tenant) |
| 2 | **Administrateur** | Admin d'un tenant : utilisateurs, rôles, agences, paramètres, référentiels | Tenant |
| 3 | **Directeur** | Vision globale, tous KPIs, validation, rapports, lecture complète | Tenant / Société |
| 4 | **Responsable transit / exploitation** | Supervise les dossiers, affecte les agents, valide les étapes critiques | Agence(s) |
| 4b | **Responsable commercial** | Grilles tarifaires, supervision devis/CRM, rapports | Tenant |
| 4c | **Responsable financier** | Validation factures, avoirs, marges, supervision Odoo | Tenant |
| 4d | **Réceptionnaire / Magasinier** | Réception entrepôt, consolidation/déconsolidation LCL, remise colis, événements manuels | Agence |
| 5 | **Agent Transit** | Crée et gère les dossiers, documents, tracking, opérations quotidiennes | Ses dossiers / son agence |
| 6 | **Commercial** | CRM, prospects, cotations, devis, opportunités | Ses clients / son agence |
| 7 | **Comptable** | Consultation facturation, export et synchronisation vers Odoo. Aucune écriture | Tenant (lecture + sync) |
| 8 | **Chauffeur** | Missions de livraison, POD (signature, photos), statuts | Ses missions |
| 9 | **Client** | Portail client : ses dossiers, documents, factures, devis, messagerie | Ses données uniquement |
| 10 | **Invité** | Page de suivi publique par numéro (dossier, BL, conteneur, AWB) | Donnée de tracking limitée |

### 3.2 Principes d'autorisation

- **Moindre privilège** : chaque rôle a uniquement les droits nécessaires.
- **Permissions granulaires par module** : lire, créer, modifier, supprimer, exporter, importer, valider, clôturer, archiver, synchroniser-Odoo.
- **Rôles personnalisables** : l'Administrateur tenant peut créer des rôles sur mesure à partir des permissions atomiques.
- **Cloisonnement strict** : tenant → société → agence. Un utilisateur ne voit que le périmètre qui lui est affecté.
- **Cas LCL critique** : plusieurs clients partagent un conteneur ; chaque client ne voit **que ses marchandises et son HBL**, jamais ceux des autres.

---

## 4. Modèle organisationnel (multi-tenant)

```
Plateforme (éditeur)
└── Tenant (client de l'éditeur : un transitaire)
    └── Société (entité juridique — multi-sociétés)
        └── Agence (site opérationnel — multi-agences)
            └── Utilisateurs, dossiers, données opérationnelles
```

**Règles :**
- Isolation totale des données entre tenants (aucune fuite possible, garantie au niveau architecture — détail à l'Étape 2).
- Un utilisateur appartient à un tenant, peut être rattaché à une ou plusieurs agences.
- Les référentiels globaux (pays, ports, aéroports, incoterms, devises) sont fournis par la plateforme et partagés en lecture ; chaque tenant peut ajouter ses propres entrées.
- Les paramètres (numérotation, workflow, grilles tarifaires, modèles de documents) sont configurables par tenant, avec surcharge possible par société et agence.

---

## 5. Exigences fonctionnelles par module

Convention : `RF-<MODULE>-<n°>`. Priorité **MoSCoW** : M (Must), S (Should), C (Could).

### 5.1 Administration (ADM)

| Réf | Exigence | Priorité |
|---|---|---|
| RF-ADM-01 | CRUD utilisateurs : invitation par email, activation/désactivation, réinitialisation MFA | M |
| RF-ADM-02 | CRUD rôles et permissions granulaires par module | M |
| RF-ADM-03 | CRUD sociétés et agences (logo, coordonnées, entête documents) | M |
| RF-ADM-04 | Référentiels : devises (avec taux de change datés), langues, pays, ports (UN/LOCODE), aéroports (IATA/ICAO), incoterms (2020), types de marchandises (dont IMO/DGR), compagnies maritimes (code SCAC), compagnies aériennes (préfixe AWB), transporteurs routiers | M |
| RF-ADM-05 | Paramètres tenant : formats de numérotation (dossiers, devis, factures), fuseaux horaires, unités (kg/lb, m³/ft³) | M |
| RF-ADM-06 | Configuration des workflows de dossier (étapes, transitions, conditions, approbations) | M |
| RF-ADM-07 | Configuration des modèles de notifications et documents (entêtes, mentions) | S |
| RF-ADM-08 | Journal d'audit consultable et filtrable (utilisateur, date, action, ancienne/nouvelle valeur, IP) | M |
| RF-ADM-09 | Gestion des clés API et webhooks sortants du tenant | S |

### 5.2 CRM (CRM)

| Réf | Exigence | Priorité |
|---|---|---|
| RF-CRM-01 | CRUD clients : **personne physique ou morale** (nature portée par la fiche, impacte mentions de facturation et sync Odoo `is_company`), identité, adresses multiples, contacts multiples, conditions commerciales (devise, conditions de paiement, plafond d'encours), préférences de notification | M |
| RF-CRM-02 | CRUD prospects avec conversion prospect → client (conservation historique) | M |
| RF-CRM-03 | CRUD fournisseurs typés : compagnie maritime, compagnie aérienne, transporteur routier, agent douane, manutentionnaire, assureur, agent portuaire, correspondant étranger | M |
| RF-CRM-04 | Fiche 360° : dossiers, devis, factures, réclamations, communications, documents | M |
| RF-CRM-05 | Opportunités commerciales : pipeline par étapes, valeur estimée, probabilité, relances | S |
| RF-CRM-06 | Réclamations : ticket lié à un dossier, gravité, responsable, SLA, résolution, coût | S |
| RF-CRM-07 | Segmentation et tags clients (importateur, exportateur, industrie, volume) | C |
| RF-CRM-08 | Détection de doublons à la création (nom, email, n° d'identification fiscale) | S |

### 5.3 Gestion des dossiers (DOS)

| Réf | Exigence | Priorité |
|---|---|---|
| RF-DOS-01 | Création dossier : référence auto (format paramétrable, ex. `{AGENCE}-{ANNÉE}-{SEQ}`), client, agent, responsable, sens (import/export), mode (maritime FCL/LCL, aérien, routier, multimodal), incoterm, origine, destination, priorité, ETA/ETD/ATA/ATD | M |
| RF-DOS-02 | Moteur de workflow configurable : étapes, transitions autorisées, conditions de passage (ex. documents obligatoires présents), actions automatiques (notification, tâche), approbations requises | M |
| RF-DOS-03 | Workflow par défaut : Création → Booking → Départ → Transit → Arrivée → Dédouanement → Livraison → Clôture | M |
| RF-DOS-04 | Timeline du dossier : tous événements horodatés (statuts, tracking, documents, communications, modifications) | M |
| RF-DOS-05 | Checklist documentaire par type de dossier : documents requis, statut (manquant/reçu/validé), alerte document manquant | M |
| RF-DOS-06 | Tâches liées au dossier : assignation, échéance, rappel, statut | S |
| RF-DOS-07 | Centre de communication par dossier : commentaires internes, emails entrants/sortants liés, pièces jointes | S |
| RF-DOS-08 | Dossier multimodal : segments de transport chaînés (ex. pré-acheminement routier + maritime + post-acheminement routier), chaque segment avec son propre tracking. **Sens « transit »** pour les dossiers de transbordement/réexpédition (marchandise traversant le pays sans import ni export — régimes de transit douanier type TRIE) | M |
| RF-DOS-09 | Coûts et revenus estimés vs réels par dossier (marge opérationnelle) — sans écriture comptable | S |
| RF-DOS-10 | Clôture contrôlée : conditions vérifiées (livraison confirmée, facturation émise, documents archivés) ; réouverture avec permission dédiée | M |
| RF-DOS-11 | Recherche et filtres avancés : multi-critères, vues sauvegardées, export | M |

### 5.4 Module Maritime (MAR)

| Réf | Exigence | Priorité |
|---|---|---|
| RF-MAR-01 | Booking : demande, confirmation compagnie, n° booking, cut-off dates (VGM, documentaire, portuaire) | M |
| RF-MAR-02 | FCL : conteneurs (n° ISO 6346 avec validation du chiffre de contrôle, taille/type 20'/40'/40'HC/Reefer/OT/FR, tare, payload, scellé), affectation au dossier | M |
| RF-MAR-03 | LCL : Master BL ↔ House BL (1 MBL → n HBL), consolidation (groupage de plusieurs HBL dans un conteneur), déconsolidation à l'arrivée | M |
| RF-MAR-04 | BL : émission House BL (draft → vérifié → émis), types (original, telex release, seaway bill), mentions (shipper, consignee, notify, description marchandises, poids, volume) | M |
| RF-MAR-05 | Navires (nom, IMO, MMSI, pavillon) et voyages (n° voyage, rotation, escales avec ETA/ETD par port) | M |
| RF-MAR-06 | Suivi des étapes portuaires : empty pickup, gate-in, chargé à bord, transbordement, déchargé, gate-out, restitution vide, détention/surestaries (alertes franchise) | S |
| RF-MAR-07 | Historique complet de chaque conteneur (tous mouvements, tous dossiers) | M |

### 5.5 Tracking maritime — connecteurs (TRK)

| Réf | Exigence | Priorité |
|---|---|---|
| RF-TRK-01 | Architecture de connecteurs à contrat unique : chaque compagnie = 1 connecteur implémentant la même interface (auth, statut conteneur, statut BL, ETA/ETD/ATA, escales) | M |
| RF-TRK-02 | Connecteurs V1 : MSC, CMA CGM, Maersk, Hapag-Lloyd, COSCO, Evergreen, ONE, OOCL, Yang Ming | M |
| RF-TRK-03 | Rafraîchissement automatique planifié (fréquence paramétrable par tenant) + rafraîchissement manuel à la demande | M |
| RF-TRK-04 | Normalisation des événements : mapping des statuts propriétaires de chaque compagnie vers un référentiel d'événements unifié (basé DCSA) | M |
| RF-TRK-05 | Mise à jour automatique du dossier : nouvel événement → timeline + recalcul ETA + détection de retard → notifications | M |
| RF-TRK-06 | Journalisation complète des échanges API (requête, réponse, latence, erreurs) + gestion des quotas et retry avec backoff | M |
| RF-TRK-07 | Ajout d'une nouvelle compagnie sans modification du cœur applicatif (plugin) | M |
| RF-TRK-08 | Fallback : saisie manuelle des événements de tracking quand pas d'API disponible | M |

### 5.6 Module Aérien (AER)

| Réf | Exigence | Priorité |
|---|---|---|
| RF-AER-01 | MAWB : n° 11 chiffres (préfixe compagnie 3 + série 8, validation modulo 7), compagnie, vols (n°, date, origine, destination, segments multiples) | M |
| RF-AER-02 | HAWB liés au MAWB (consolidation aérienne) | M |
| RF-AER-03 | Poids brut, poids taxable (chargeable weight = max(brut, volumétrique) ; volumétrique = volume/6000 en cm³/kg), volume, nombre de colis, dimensions | M |
| RF-AER-04 | Tracking aérien : saisie manuelle V1, architecture connecteur prête pour APIs compagnies (mêmes contrats que maritime) | M |
| RF-AER-05 | Marchandises dangereuses (DGR) : classe, UN number, déclaration | S |

### 5.7 Module Routier (ROU)

| Réf | Exigence | Priorité |
|---|---|---|
| RF-ROU-01 | Flotte : camions (immatriculation, type, capacité, échéances contrôle technique/assurance), remorques | M |
| RF-ROU-02 | Chauffeurs : identité, permis (catégories, expiration), téléphone, affectation véhicule | M |
| RF-ROU-03 | Missions : ordre de transport lié au dossier, chauffeur + véhicule, points de passage, fenêtres horaires | M |
| RF-ROU-04 | Livraisons : statuts (planifiée, en cours, livrée, échec), motif d'échec, replanification | M |
| RF-ROU-05 | Preuve de livraison (POD) : signature électronique sur écran, photos, nom du réceptionnaire, horodatage, géolocalisation du point de livraison | M |
| RF-ROU-06 | Interface chauffeur mobile-first (web responsive V1) : ses missions du jour, navigation, POD | S |
| RF-ROU-07 | Architecture prête pour intégration GPS/télématique future | S |

### 5.8 Gestion documentaire (DOC)

| Réf | Exigence | Priorité |
|---|---|---|
| RF-DOC-01 | Types : BL, HBL, MBL, AWB, facture commerciale, packing list, certificat d'origine, assurance, documents douaniers, photos, autres (extensible) | M |
| RF-DOC-02 | Upload (drag & drop, multi-fichiers), formats contrôlés, scan antivirus, taille max paramétrable | M |
| RF-DOC-03 | Versioning : nouvelle version remplace sans écraser, historique consultable, restauration | M |
| RF-DOC-04 | Permissions par document : interne seul / visible client / confidentiel | M |
| RF-DOC-05 | Accès sécurisé exclusivement par URL temporaires signées ; journalisation des téléchargements | M |
| RF-DOC-06 | Recherche par type, dossier, client, date, nom ; recherche plein texte (contenu OCR quand disponible) | S |
| RF-DOC-07 | Archivage automatique à la clôture du dossier ; règles de rétention paramétrables | S |
| RF-DOC-08 | Structure prête pour OCR (extraction automatique différée) | C |

### 5.9 Cotations (COT)

| Réf | Exigence | Priorité |
|---|---|---|
| RF-COT-01 | Moteur de calcul paramétrable : grilles tarifaires par prestation (fret, assurance, manutention, douane, transport, prestations diverses), par mode, par trajet (origine-destination), par unité (conteneur, kg, m³, forfait, %) | M |
| RF-COT-02 | Règles de calcul : minimum de perception, tranches de poids/volume, ratio poids/volume (1 m³ = 1000 kg maritime, 1 m³ = 167 kg aérien), majorations (BAF, CAF, surcharge fuel), devise par ligne avec conversion | M |
| RF-COT-03 | Grilles d'achat (coûts fournisseurs) et grilles de vente (prix client) → marge estimée visible | S |
| RF-COT-04 | Cycle de vie du devis : brouillon → envoyé → accepté / refusé / expiré ; validité datée ; révisions versionnées | M |
| RF-COT-05 | Conversion devis accepté → dossier en 1 clic (reprise de toutes les données) | M |
| RF-COT-06 | Envoi du devis PDF par email depuis la plateforme ; visible sur portail client | S |

### 5.10 Facturation (FAC)

| Réf | Exigence | Priorité |
|---|---|---|
| RF-FAC-01 | Génération : devis, proforma, facture, avoir — numérotation séquentielle paramétrable et infalsifiable par type et par société | M |
| RF-FAC-02 | Facture liée au dossier : lignes de prestations (libellé, quantité, prix unitaire, devise, taux de taxe référencé — le taux vient du référentiel synchronisé Odoo) | M |
| RF-FAC-03 | **Interdiction absolue d'écritures comptables.** La facture est un document opérationnel ; Odoo fait foi comptablement | M |
| RF-FAC-04 | Statuts : brouillon → validée → synchronisée Odoo → (statut de paiement rapatrié depuis Odoo : impayée, partielle, payée) | M |
| RF-FAC-05 | PDF conforme (mentions légales paramétrables par société), envoi email, dispo portail client | M |
| RF-FAC-06 | Avoir lié à une facture d'origine, motif obligatoire | M |
| RF-FAC-07 | Alerte encours client dépassé à la création de dossier/devis (plafond défini au CRM) | S |

### 5.11 Intégration Odoo (ODO)

| Réf | Exigence | Priorité |
|---|---|---|
| RF-ODO-01 | Synchronisation : clients, fournisseurs, produits/services (catalogue de prestations), devis, factures, avoirs, statuts de paiement, taxes, devises | M |
| RF-ODO-02 | Sens : SILARIS → Odoo pour clients/fournisseurs/devis/factures ; Odoo → SILARIS pour statuts de paiement, taxes, devises. Mapping d'IDs persistant des deux côtés | M |
| RF-ODO-03 | File d'attente asynchrone : aucune synchro bloquante pour l'utilisateur ; retry automatique avec backoff ; file d'échecs (dead letter) avec rejeu manuel | M |
| RF-ODO-04 | Gestion des conflits : stratégie par entité (source de vérité déclarée), détection de modification concurrente, écran de résolution manuelle | M |
| RF-ODO-05 | Journal de synchronisation : chaque échange tracé (entité, sens, payload, résultat, erreur) consultable par le rôle Comptable | M |
| RF-ODO-06 | Configuration par tenant : URL instance Odoo, credentials (stockage chiffré), mapping des comptes/journaux côté Odoo, activation par entité | M |
| RF-ODO-07 | Mode dégradé : Odoo indisponible → la plateforme fonctionne normalement, la file s'accumule et se vide au retour | M |

### 5.12 Portail client (POR)

| Réf | Exigence | Priorité |
|---|---|---|
| RF-POR-01 | Espace sécurisé par client : ses dossiers, expéditions, conteneurs, colis (LCL : uniquement les siens) | M |
| RF-POR-02 | Téléchargement de ses documents (ceux marqués « visible client ») | M |
| RF-POR-03 | Consultation devis (avec acceptation en ligne) et factures (avec statut de paiement) | M |
| RF-POR-04 | Messagerie avec son gestionnaire de compte, liée au dossier | S |
| RF-POR-05 | Gestion de ses préférences de notification (canaux + événements) | M |
| RF-POR-06 | Multi-utilisateurs par client (le client gère ses propres accès en lecture) | C |
| RF-POR-07 | **Page publique de suivi** : saisie d'un n° de dossier, BL, HBL, MBL, AWB ou conteneur → statut, timeline d'événements, ETA. Sans authentification, données limitées (pas de montants, pas de documents), rate-limited et protégée anti-scraping | M |

### 5.13 Notifications (NOT)

| Réf | Exigence | Priorité |
|---|---|---|
| RF-NOT-01 | Canaux : email, SMS, WhatsApp Business API, notifications internes (in-app, temps réel) | M |
| RF-NOT-02 | Événements déclencheurs : départ, arrivée, douane, retard détecté, livraison, document manquant, facture disponible (extensible) | M |
| RF-NOT-03 | Préférences par destinataire : matrice canal × événement, opt-in/opt-out (client via portail, interne via profil) | M |
| RF-NOT-04 | Modèles multilingues paramétrables par tenant (variables dynamiques : n° dossier, ETA, port…) | M |
| RF-NOT-05 | Envoi asynchrone (file d'attente), statut de délivrance tracé (envoyé, délivré, échec), retry | M |
| RF-NOT-06 | Anti-spam : regroupement d'événements rapprochés, plages horaires d'envoi paramétrables | C |

### 5.14 Tableaux de bord et rapports (DAS/RAP)

| Réf | Exigence | Priorité |
|---|---|---|
| RF-DAS-01 | Dashboard par rôle : Direction (CA opérationnel, marge, volumes, satisfaction), Exploitation (dossiers actifs, retards, documents manquants, charge par agent), Commercial (pipeline, devis, taux de conversion), Comptable (facturation, statuts sync Odoo) | M |
| RF-DAS-02 | Widgets configurables : ajout, suppression, réorganisation drag & drop, sauvegarde par utilisateur | S |
| RF-DAS-03 | KPIs : dossiers actifs, import/export, conteneurs en transit, livraisons, retards, documents manquants, délais moyens par étape, performance agents, CA opérationnel, satisfaction client | M |
| RF-RAP-01 | Rapports journaliers, hebdomadaires, mensuels, annuels ; génération planifiée + à la demande | M |
| RF-RAP-02 | Export PDF et Excel ; envoi automatique par email aux destinataires configurés | M |

### 5.15 Recherche globale (SRC)

| Réf | Exigence | Priorité |
|---|---|---|
| RF-SRC-01 | Barre de recherche globale (raccourci clavier) : dossiers, clients, conteneurs, BL, AWB, factures, documents — résultats catégorisés, < 2 s | M |
| RF-SRC-02 | Indexation temps réel (Meilisearch), respect strict des permissions dans les résultats | M |

---

## 6. Règles métier clés (freight forwarding)

Règles indispensables à la correction fonctionnelle du produit :

1. **MBL/HBL** : un Master BL (contrat transitaire ↔ compagnie) porte 1..n House BL (contrat transitaire ↔ client). En FCL simple : souvent 1 MBL = 1 HBL. En LCL/consolidation : 1 MBL = n HBL de clients différents.
2. **Numéro de conteneur** : format ISO 6346 (4 lettres + 6 chiffres + 1 chiffre de contrôle). Validation obligatoire du check digit à la saisie.
3. **Numéro AWB** : 11 chiffres (préfixe compagnie 3 + numéro de série 7 + chiffre de contrôle = série mod 7).
4. **Poids taxable aérien** : `max(poids brut, volume en cm³ / 6000)`.
5. **Ratio maritime LCL** : facturation au ratio poids/volume, typiquement `max(tonnes, m³)` (w/m).
6. **Incoterms 2020** : déterminent qui paie quoi et où le risque est transféré → impact direct sur les lignes de cotation applicables (ex. EXW = tout à charge acheteur ; DDP = tout à charge vendeur y compris douane).
7. **Détention / surestaries** : franchise (jours libres) par compagnie et par port ; au-delà, frais journaliers → alertes obligatoires avant expiration de franchise.
8. **Cut-offs booking** : VGM cut-off, cut-off documentaire, cut-off portuaire → alertes; un booking non complété au cut-off = rollover (report navire suivant).
9. **Retard** : détecté quand nouvelle ETA > ETA précédente + seuil paramétrable, ou ATA > ETA + seuil → notification automatique.
10. **Clôture dossier** : impossible si livraison non confirmée ou facturation non émise (règles configurables par workflow).
11. **Séquence de numérotation facture** : continue, sans trou, par société et par type de document (exigence légale dans la plupart des juridictions).
12. **Devises** : chaque montant porte sa devise + taux de conversion daté vers la devise de référence de la société (source des taux : Odoo ou API, paramétrable).

---

## 7. Parcours utilisateurs principaux (user journeys)

### UJ-1 — Export maritime FCL (le flux cœur)
1. Commercial crée un devis (moteur de cotation) → envoi client → acceptation en ligne sur portail.
2. Conversion en dossier ; agent transit assigné ; workflow démarre.
3. Booking auprès de la compagnie ; conteneur affecté ; cut-offs suivis.
4. Documents collectés (checklist) : facture commerciale, packing list, VGM ; HBL draft → émis.
5. Départ navire : le connecteur compagnie remonte l'événement → timeline, notification client « Départ ».
6. Transit : escales et ETA mis à jour automatiquement ; retard détecté → notification.
7. Arrivée, dédouanement (documents douane), livraison (mission routière + POD).
8. Facturation émise → sync Odoo → statut de paiement rapatrié.
9. Clôture (conditions vérifiées) ; archivage documents.

### UJ-2 — Import LCL avec consolidation
1. n dossiers clients regroupés dans une consolidation (1 conteneur, 1 MBL, n HBL).
2. Chaque client suit uniquement sa marchandise sur son portail.
3. Arrivée : déconsolidation, livraisons individuelles, facturation par dossier.

### UJ-3 — Suivi public
1. Destinataire reçoit un n° HBL par email.
2. Page publique → saisie du numéro → timeline, statut, ETA. Aucun montant, aucun document.

### UJ-4 — Chauffeur
1. Ouvre son interface mobile → missions du jour.
2. Démarre la mission, arrive, fait signer sur l'écran, prend photos.
3. POD enregistré → dossier mis à jour → notification client « Livré ».

### UJ-5 — Comptable
1. Consulte les factures validées → vérifie la file de synchronisation Odoo.
2. Résout un conflit signalé (client modifié des deux côtés) via l'écran de résolution.
3. Les statuts de paiement remontent d'Odoo automatiquement.

---

## 8. Exigences non fonctionnelles (synthèse)

Détaillées dans le document sécurité déjà fourni ; rappel des cibles engageantes :

| Domaine | Exigence |
|---|---|
| Disponibilité | ≥ 99,9 %, fonctionnement 24/7 |
| Performance | ≥ 500 utilisateurs simultanés (dimensionnable), > 100 000 dossiers, millions d'événements tracking, recherches < 2 s |
| RPO / RTO | ≤ 15 min / ≤ 2 h |
| Sécurité | TLS partout, Argon2id, MFA, RBAC, audit complet, OWASP Top 10, URLs signées, rate limiting, secrets chiffrés |
| Conformité | RGPD (si données UE) : consentement, droit à l'effacement, registre des traitements, minimisation |
| i18n | Interface multilingue (FR/EN au lancement), formats dates/nombres/devises localisés, fuseaux horaires par agence |
| Auditabilité | Toute opération historisée (qui, quand, quoi, avant/après, IP, navigateur) |
| Extensibilité | Nouveaux connecteurs sans toucher au cœur ; webhooks sortants ; API publique versionnée |

---

## 9. Priorisation — alignement roadmap

| Phase | Contenu | Modules |
|---|---|---|
| **Phase 1 — Fondation** | Socle multi-tenant, auth/MFA/RBAC, Administration, CRM, Dossiers + workflow, Maritime, Documents, recherche globale, audit | ADM, CRM, DOS, MAR, DOC, SRC |
| **Phase 2 — Extension opérationnelle** | Aérien, Routier + POD, Portail client + page publique, Notifications, Cotations, Facturation | AER, ROU, POR, NOT, COT, FAC |
| **Phase 3 — Automatisation** | Connecteurs maritimes (9 compagnies), tracking auto, API publique, webhooks | TRK |
| **Phase 4 — Intégration & intelligence** | Odoo, dashboards avancés, rapports planifiés, PWA, IA (différé) | ODO, DAS, RAP |

*Note : l'architecture (Étape 2) est conçue dès le départ pour toutes les phases ; seule l'implémentation est phasée.*

---

## 10. Glossaire

| Terme | Définition |
|---|---|
| ATA / ATD | Actual Time of Arrival / Departure — date réelle |
| AWB / MAWB / HAWB | Air Waybill / Master (compagnie) / House (transitaire) |
| BL / MBL / HBL | Bill of Lading / Master / House |
| BAF / CAF | Bunker / Currency Adjustment Factor (surcharges) |
| Consolidation | Groupage de marchandises de plusieurs clients dans un conteneur/une expédition |
| Cut-off | Date limite (documentaire, VGM, portuaire) avant départ |
| DCSA | Digital Container Shipping Association — standards d'événements tracking |
| Demurrage / Detention | Surestaries (conteneur au port) / détention (conteneur hors port) au-delà de la franchise |
| ETA / ETD | Estimated Time of Arrival / Departure |
| FCL / LCL | Full Container Load / Less than Container Load |
| Incoterms | Règles ICC définissant répartition des coûts et transfert de risque |
| POD | Proof of Delivery — preuve de livraison |
| SCAC | Standard Carrier Alpha Code — code compagnie |
| Telex release | Libération électronique du BL sans original papier |
| UN/LOCODE | Code ONU des lieux (ports) |
| VGM | Verified Gross Mass — pesée certifiée du conteneur (SOLAS) |
| w/m | Weight or Measure — facturation au poids ou volume, le plus élevé |

---

## 11. Décisions prises à cette étape

1. **Nom provisoire retenu : SILARIS** (le repo s'appelle SILARIS — à confirmer lequel est le nom produit).
2. **Multi-tenant natif dès V1** : hiérarchie Plateforme → Tenant → Société → Agence. Choix structurant, non rattrapable après coup.
3. **10 rôles standard + rôles personnalisables** à partir de permissions atomiques par module.
4. **Workflow de dossier configurable** dès V1 (recommandation intégrée), avec un workflow par défaut livré prêt à l'emploi.
5. **Tracking normalisé sur le référentiel d'événements DCSA** : chaque connecteur mappe les statuts propriétaires vers ce standard.
6. **Facturation strictement opérationnelle** : documents + statuts uniquement ; Odoo est la seule source de vérité comptable ; taux de taxes rapatriés d'Odoo.
7. **Odoo en mode asynchrone résilient** : files d'attente, retry, dead letter, mode dégradé si Odoo indisponible.
8. **Page publique de suivi limitée** : timeline et ETA uniquement, jamais de montants ni documents, rate-limited.
9. **Recommandations du fichier annexe intégrées** : tâches/approbations, centre de communication par dossier, module réclamations, événements + webhooks sortants.
10. **Connecteurs aériens différés** (architecture identique au maritime, implémentation phase ultérieure) ; OCR et GPS : structures prévues, implémentation différée.
11. **Validation métier stricte à la saisie** : check digit ISO 6346 (conteneurs), modulo 7 (AWB), poids taxable aérien automatique.
12. **i18n FR/EN au lancement**, extensible.

---

## 12. Points ouverts (à trancher — n'empêchent pas l'Étape 2)

| # | Question | Impact | Proposition par défaut |
|---|---|---|---|
| Q1 | Nom définitif : SILARIS ou SILARIS ? | Branding, namespaces code | SILARIS (nom du repo) |
| Q2 | Marchés cibles initiaux (Afrique de l'Ouest ? Europe ? Global ?) | Langues, devises par défaut, réglementations douanières | FR/EN, multi-devises, douane générique |
| Q3 | Stratégie tenant DB : base partagée avec isolation par tenant_id + RLS PostgreSQL, ou base par tenant ? | Architecture Étape 2 | Base partagée + RLS (coût/scalabilité SaaS) — argumenté à l'Étape 2 |
| Q4 | Sanctum (tokens) vs JWT ? | Auth Étape 13 | Sanctum (natif Laravel, révocation simple) — argumenté à l'Étape 2 |
| Q5 | Meilisearch vs Elasticsearch ? | Infra | Meilisearch (léger, rapide, suffisant) |
| Q6 | Fournisseurs SMS/WhatsApp préférés ? | Connecteurs notifications | Twilio (SMS + WhatsApp Business API) |
| Q7 | Version Odoo cible (16/17/18) ? Instance(s) unique(s) par tenant ? | Connecteur Odoo | Odoo 17+, une instance par tenant |

---

*Fin de l'Étape 1. En attente de validation avant l'Étape 2 — Architecture logicielle.*
