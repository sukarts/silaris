# Étape 25 — Documentation Utilisateur

**Projet :** SILARIS · **Statut :** Livré · **Prérequis :** Étape 24 validée

## Livrables — `docs/utilisateur/` (7 guides)

**Mises à jour post-livraison intégrées** (audit du 25/07) : 11 rôles (dont resp. financier/commercial, magasinier), cycle colis LCL + étiquettes enrichies, remise contre règlement, OTP de retrait, client personne physique/morale, sens « transit » (transbordement).

| Guide | Public | Points clés |
|---|---|---|
| 00-prise-en-main | Tous | Connexion, MFA (+ codes de récupération), navigation, lecture des statuts/pastilles, tableau de bord |
| 01-agent-transit | Exploitation | Cycle de vie dossier, workflow guidé (« documents manquants » = protection), tracking auto vs manuel, versioning documentaire, surestaries, POD, clôture contrôlée |
| 02-commercial | Ventes | CRM + conversion prospect, simulateur de cotation (règles métier expliquées en langage commercial), cycle devis, devis accepté → dossier en 1 clic |
| 03-comptable | Finance | Principe « la compta vit dans Odoo », validation = numéro légal + immuabilité, avoirs, statuts de paiement rapatriés, supervision sync + dead letters |
| 04-administrateur | Admin tenant | Utilisateurs/rôles (8 système + personnalisés), scope agences, sociétés (numérotation factures), éditeur de workflows, intégrations, audit infalsifiable |
| 05-portail-client | Clients finaux | Vocabulaire non technique, suivi (dont **colis LCL par QR**), documents, factures, acceptation devis, **code de retrait OTP**, page publique |
| 06-magasinier | Entrepôt LCL | Réception, étiquettes (destination en gros, colisage, poids/CBM), empotage/dépotage scannés, **remise à double contrôle** (facture soldée + OTP client) |

Principes : rédigé pour l'utilisateur (tâches, pas fonctionnalités) ; ne documente que l'existant ; explique les garde-fous comme des protections (« pas une panne ») ; le guide portail est distribuable tel quel aux clients du transitaire.

---
*Fin de l'Étape 25. En attente de validation avant l'Étape 26 — Guide de déploiement.*
