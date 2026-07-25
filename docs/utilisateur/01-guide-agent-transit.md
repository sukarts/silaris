# Guide Agent Transit / Exploitation

Votre outil central : le **dossier**. Tout ce qui suit part de « Opérations → Dossiers ».

## Créer un dossier
« + Nouveau dossier » → client, société/agence, sens (import / export / **transit** pour un transbordement-réexpédition), mode (FCL, LCL, aérien, routier, multimodal), origine/destination (codes UN/LOCODE, ex. CNSHA → CIABJ), incoterm, priorité, ETD/ETA prévisionnelles. **La référence est générée automatiquement** (agence + année + séquence) — jamais de saisie manuelle.

Un dossier peut aussi naître automatiquement d'un devis accepté (voir guide commercial).

## Faire avancer le workflow
Le bandeau d'étapes (Création → Booking → Départ → Transit → Arrivée → Douane → Livraison → Clôture, selon la configuration de votre société) montre où en est le dossier. Les boutons orange en haut à droite proposent **uniquement les étapes autorisées**.

Si le passage est refusé « Documents requis manquants : … » : ajoutez les documents listés (onglet Documents) puis recommencez. C'est la protection normale — pas une panne.

## Suivi automatique (tracking)
Dès qu'un conteneur ou BL est rattaché à une compagnie connectée, SILARIS interroge son API régulièrement : la **timeline** du dossier s'enrichit seule (étiquette « API compagnie »), les ATD/ATA se posent automatiquement au départ/arrivée réels, et un retard détecté déclenche l'alerte + la notification client. Compagnie non connectée : saisissez les événements manuellement.

## Documents
Onglet Documents du dossier : glissez-déposez (25 Mo max). Chaque nouvel envoi du même document crée une **version** — rien n'est écrasé. Visibilité : *interne* (défaut), *client* (visible sur son portail), *confidentiel*. Les téléchargements passent par des liens sécurisés temporaires et sont journalisés.

## Conteneurs & surestaries
Fiche dossier → Conteneurs : n° (contrôlé automatiquement — un numéro invalide est refusé), scellé, VGM, jalons (entrée terminal, chargé, déchargé, sortie, restitution). La **franchise** génère des alertes tableau de bord 3 jours avant expiration : traitez-les en priorité, c'est de l'argent.

## Colis LCL (avec le magasinier)
À la réception entrepôt : dossier → « Enregistrer des colis » (nombre, description, poids unitaire) → chaque colis reçoit une référence unique (`TAL-2026-00126-C0001`…) → **imprimez la planche d'étiquettes PDF** (une par page, format 100×150 mm, QR code de suivi). À l'empotage, scannez chaque colis vers son conteneur : le client voit alors « Embarqué dans le conteneur MSKU… ». À destination : dépotage puis **remise** (nom du réceptionnaire). Chaque scan alimente la timeline du dossier. L'ordre est contrôlé : réception → empotage → dépotage → remise (impossible de sauter une étape).

**Remise contre règlement** : la remise d'un colis est **bloquée tant que la facture du dossier n'est pas soldée** (statut de paiement rapatrié d'Odoo). Le message indique la ou les factures en attente. Seul un responsable habilité peut accorder une dérogation exceptionnelle — elle est inscrite en toutes lettres dans la timeline du dossier avec son nom.

**Code de retrait (OTP)** : au moment de la remise, cliquez « Envoyer le code » — le client reçoit un code à 6 chiffres (email, bientôt SMS/WhatsApp), valable 30 minutes. Il vous le présente, vous le saisissez avec la remise. Sans code valide, pas de remise. Le code n'apparaît jamais sur votre écran (5 essais maximum, usage unique). Le client n'a pas reçu le code ? Vérifiez l'email de son contact ou de son compte portail dans le CRM.

## Livraison routière
Missions (Routier) : véhicule + chauffeur + fenêtre horaire. Le chauffeur clôture avec la **preuve de livraison** (nom du réceptionnaire, signature, photos, position) — le dossier passe alors livrable en clôture.

## Clôturer
Bouton de clôture actif seulement si les conditions sont réunies (livraison confirmée, facture émise…). Un dossier clôturé est en lecture seule ; la réouverture est un droit séparé.
