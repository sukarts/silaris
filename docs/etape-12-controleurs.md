# Étape 12 — Contrôleurs

**Projet :** SILARIS · **Statut :** Exécuté et vérifié (serveur + curl) · **Prérequis :** Étape 11 validée
**Volume :** 88 routes `/api/v1`, 18 contrôleurs, 12 modules exposés

---

## 1. Contrôleurs livrés

| Module | Contrôleurs | Points notables |
|---|---|---|
| Crm | Party, PartyContact, Opportunity, Complaint | Conversion prospect→client, référence réclamation séquencée, contacts imbriqués |
| Ocean | Booking, Container, BillOfLading | Confirm/roll booking, affectation conteneur + jalons, machine à états BL (draft→verified→issued→surrendered, immuable après émission) |
| Air | AirWaybill | Création MAWB+segments en une requête, normalisation du numéro, émission |
| Road | Fleet, Mission | Machine à états mission, POD transactionnel (signature+géoloc → statut delivered) |
| Pricing | Quote, Tariff | `POST /quotes/calculate` (simulation), send/accept/reject, **accept → création dossier via CreateShipmentCommand**, grille+lignes atomiques |
| Billing | Invoice | Brouillon seul modifiable, **validate = numéro légal séquencé** (format société `F-{YEAR}-{SEQ:4}`) + totaux TVA + échéance selon conditions client, avoir depuis facture validée |
| Documents | Document | Upload multipart (25 Mo max, extensions contrôlées), **versioning**, checksum SHA-256, scan AV pending, **URLs signées 10 min** (route publique validée par signature), journal des téléchargements |
| Referential | Referential | 8 référentiels lecture seule, recherche, Cache-Control 1 h |
| Identity | User, Role | Création utilisateur + rôles/agences, mot de passe provisoire (invitation email Étape 13+), reset MFA, rôles personnalisés tenant (jamais les rôles système), catalogue permissions groupé |
| Tenancy | Organization | Sociétés + agences |
| Audit | AuditLog | Lecture filtrée (entité, utilisateur, action, période) |

## 2. Conventions appliquées

- CRUD simple : validation inline `$request->validate()` + Eloquent direct — **décision assumée** : les handlers Command/Query sont réservés aux opérations à invariants (dossiers, validation facture, acceptation devis). Les machines à états locales (BL, mission, booking) utilisent des tables de transitions constantes + exceptions `DomainException` (→ 422 RFC 9457 automatique).
- Toutes les listes en pagination curseur ; filtres validés par Rule::in.
- Écritures sensibles multi-tables sous `DB::transaction`.

## 3. Tests exécutés (curl, serveur réel)

| Test | Résultat |
|---|---|
| Référentiel ports, recherche "abidjan" | ✓ CIABJ |
| Création client + conversion prospect CFAO → client | ✓ |
| `POST /quotes/calculate` | ✓ 4 lignes multi-devises (4 900 USD + 1 220 000 XOF) |
| Facture brouillon → validate | ✓ **F-2026-0316** (séquence continue), TVA 18 % calculée, échéance +30 j |
| PATCH facture validée | ✓ 404 (filtre draft) — trigger pg en 2e défense |
| Avoir depuis facture validée | ✓ draft lié à l'origine + motif |
| Upload document multipart | ✓ v1, checksum SHA-256 |
| Téléchargement via URL signée | ✓ contenu restitué ; **URL falsifiée → 403** |
| Re-upload même document | ✓ version 2 |
| Mission → in_progress → POD | ✓ mission delivered, géoloc enregistrée |

Incident : variables shell zsh non splittées dans les tests curl (pas dans le code livré) — tests réécrits.

## 4. Reste
- Autorisation par permission sur chaque route → Étape 14 (RBAC middleware + Policies).
- PDF devis/factures → génération à l'Étape 16+ (dompdf installé).
- Notifications sur transitions (quote.sent, mission.delivered…) → listeners Étape 15/13.

---

*Fin de l'Étape 12. En attente de validation avant l'Étape 13 — Authentification.*
