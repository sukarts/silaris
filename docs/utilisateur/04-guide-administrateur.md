# Guide Administrateur

## Utilisateurs
« Administration → Utilisateurs » : création (email, nom, rôles, agences) → mot de passe provisoire à changer à la première connexion. Désactivation = accès coupé immédiatement, historique conservé (jamais de suppression). « Réinitialiser MFA » si un collaborateur perd son téléphone — il ré-enrôle à la connexion suivante.

**Agences** : un utilisateur ne voit que les dossiers de ses agences ; direction/admin voient tout.

## Rôles et permissions
11 rôles système fournis — non modifiables : Super Admin, Admin, Directeur, **Responsable transit/exploitation**, Agent transit, **Responsable commercial**, Commercial, **Responsable financier**, Comptable, **Réceptionnaire/Magasinier** (réception entrepôt, consolidations LCL, remise colis), Chauffeur. Besoin spécifique → **rôle personnalisé** : cochez des permissions atomiques (`module.action`) dans le catalogue. Un changement de rôle est effectif en ≤ 1 minute.

Principe : moindre privilège. Exemples réels : la validation de facture est réservée exploitation/admin ; le chauffeur ne voit que ses missions et POD.

## Organisation
Sociétés (entités juridiques : devise, mentions et **format de numérotation des factures**) et agences (code court utilisé dans les références dossier, fuseau horaire). Créez l'agence avant les utilisateurs qui s'y rattachent.

## Workflows
« Administration → Workflows » : étapes, transitions autorisées, **documents requis par étape**, conditions de clôture — par mode et sens. Les dossiers en cours conservent le workflow de leur création ; les changements valent pour les nouveaux.

## Intégrations
- **Compagnies maritimes** : saisissez les identifiants API par compagnie (stockés chiffrés) — le tracking automatique démarre seul. Sans identifiants : saisie manuelle.
- **Odoo** : voir guide comptable.

## Audit
« Administration → Journal d'audit » : qui, quoi, quand, avant/après, IP — infalsifiable (protection au niveau base de données). Filtres par utilisateur, entité, période.
