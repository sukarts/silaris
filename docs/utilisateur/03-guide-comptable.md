# Guide Comptable

Principe fondateur : **SILARIS ne fait pas de comptabilité**. Il produit les documents (devis, proformas, factures, avoirs) et se synchronise avec **Odoo**, seule source de vérité comptable.

## Factures
« Commercial → Facturation ». Une facture naît en **brouillon** (modifiable). **Valider** :
- attribue le numéro légal définitif (séquence continue par société — sans trou),
- calcule TVA et TTC, pose l'échéance selon les conditions du client,
- **fige la facture** (toute modification est refusée, y compris techniquement en base),
- l'envoie vers Odoo automatiquement (statut « Sync Odoo »).

Erreur sur une facture validée → **Avoir** (motif obligatoire, lié à la facture d'origine). Jamais de suppression.

## Statuts de paiement
La colonne Paiement (Impayée / Partielle / Payée) est **rapatriée d'Odoo** — elle ne se modifie pas dans SILARIS. Encaissez dans Odoo ; SILARIS s'aligne (synchronisation horaire).

Ce statut a un effet opérationnel direct : **l'entrepôt ne peut pas remettre les colis LCL d'un dossier dont la facture n'est pas soldée** (dérogation possible uniquement par un responsable habilité, tracée). Encaisser vite = livrer vite.

## Superviser la synchronisation
Écran « Odoo » (droit dédié) : état de la connexion, volumes 7 jours, **échecs définitifs (dead letters)** avec le message d'erreur exact, 20 derniers échanges. Odoo indisponible → rien n'est perdu : les envois s'accumulent et repartent automatiquement au retour ; le statut passe « Échec sync » seulement sur erreur métier Odoo (à corriger côté Odoo puis rejouer).

## Configuration (avec l'administrateur)
« Odoo → Configuration » : URL, base, utilisateur dédié, clé API (stockée chiffrée). La connexion est testée avant enregistrement. Taxes : gérées dans Odoo, tirées automatiquement dans SILARIS.
