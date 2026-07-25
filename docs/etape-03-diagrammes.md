# Étape 3 — Diagrammes (C4, ERD, Flux métier)

**Projet :** SILARIS — Plateforme de Gestion de Transit International (TMS)
**Version :** 1.0
**Statut :** En attente de validation
**Prérequis :** Étapes 1–2 validées
**Format :** Mermaid (rendu natif GitHub, VS Code, GitLab)

---

## 1. C4 — Niveau 1 : Diagramme de contexte

Qui utilise SILARIS et avec quels systèmes il communique.

```mermaid
C4Context
    title SILARIS - Diagramme de contexte (C4 niveau 1)

    Person(agent, "Agent Transit / Exploitation", "Gère les dossiers, documents, opérations")
    Person(commercial, "Commercial", "CRM, cotations, devis")
    Person(direction, "Direction / Comptable", "KPIs, rapports, supervision facturation")
    Person(chauffeur, "Chauffeur", "Missions, preuve de livraison")
    Person(client, "Client", "Portail : dossiers, documents, factures")
    Person(invite, "Destinataire / Invité", "Suivi public par numéro")

    System(silaris, "SILARIS", "Plateforme TMS : dossiers, tracking, documents, cotations, facturation opérationnelle")

    System_Ext(odoo, "Odoo ERP", "Comptabilité, paiements, taxes (source de vérité comptable)")
    System_Ext(carriers, "APIs Compagnies Maritimes", "MSC, CMA CGM, Maersk, Hapag-Lloyd, COSCO, Evergreen, ONE, OOCL, Yang Ming")
    System_Ext(email, "Service Email", "SMTP / API transactionnelle")
    System_Ext(sms, "Passerelle SMS / WhatsApp", "Twilio (SMS + WhatsApp Business API)")
    System_Ext(sso, "Microsoft 365 / Google Workspace", "SSO OIDC (option)")

    Rel(agent, silaris, "Utilise", "HTTPS")
    Rel(commercial, silaris, "Utilise", "HTTPS")
    Rel(direction, silaris, "Utilise", "HTTPS")
    Rel(chauffeur, silaris, "Utilise (mobile)", "HTTPS")
    Rel(client, silaris, "Portail client", "HTTPS")
    Rel(invite, silaris, "Page publique de suivi", "HTTPS")

    Rel(silaris, odoo, "Sync clients, factures ← statuts paiement, taxes", "REST/JSON-RPC + Webhooks")
    Rel(silaris, carriers, "Interroge tracking conteneurs/BL", "REST, polling planifié")
    Rel(silaris, email, "Envoie notifications et documents", "SMTP/API")
    Rel(silaris, sms, "Envoie SMS / WhatsApp", "API")
    Rel(sso, silaris, "Authentifie (option)", "OIDC")
```

---

## 2. C4 — Niveau 2 : Diagramme de conteneurs

```mermaid
C4Container
    title SILARIS - Diagramme de conteneurs (C4 niveau 2)

    Person(users, "Utilisateurs internes", "Agents, commerciaux, direction")
    Person(clients, "Clients / Invités", "Portail + suivi public")

    System_Boundary(silaris, "SILARIS") {
        Container(web, "Application Web", "Next.js 15, React, TypeScript", "App interne (SPA), portail client, page publique de suivi (SSR)")
        Container(api, "API Backend", "Laravel 11, PHP 8.3", "API REST /api/v1 - 18 modules DDD, RBAC, workflow")
        Container(workers, "Workers", "Laravel Horizon", "6 groupes de files : notifications, tracking, odoo, documents, reports, default")
        Container(scheduler, "Scheduler", "Laravel Scheduler", "Polling tracking, rapports planifiés, purges, relances")
        ContainerDb(pg, "Base de données", "PostgreSQL 16", "Données métier, multi-tenant (tenant_id + RLS), audit append-only")
        ContainerDb(redis, "Redis 7", "Cache / Files", "Cache applicatif, files Horizon, rate limiting, verrous")
        ContainerDb(s3, "Stockage Objet", "S3 / MinIO", "Documents (bucket privé, URLs signées, versioning)")
        ContainerDb(meili, "Meilisearch", "Moteur de recherche", "Index par entité, filtre tenant_id imposé")
    }

    System_Ext(odoo, "Odoo ERP", "Comptabilité")
    System_Ext(carriers, "APIs Compagnies", "9 compagnies maritimes")
    System_Ext(channels, "Canaux externes", "Email, SMS, WhatsApp")

    Rel(users, web, "Utilise", "HTTPS")
    Rel(clients, web, "Utilise", "HTTPS")
    Rel(web, api, "Appelle", "JSON /api/v1, Sanctum, SSE")
    Rel(api, pg, "Lit/écrit", "SQL (RLS)")
    Rel(api, redis, "Cache, files, verrous")
    Rel(api, s3, "URLs signées")
    Rel(api, meili, "Recherche (filtre tenant)")
    Rel(workers, pg, "Lit/écrit", "SQL (RLS)")
    Rel(workers, redis, "Consomme les files")
    Rel(workers, odoo, "Synchronise", "REST/JSON-RPC")
    Rel(workers, carriers, "Interroge tracking", "REST")
    Rel(workers, channels, "Envoie notifications")
    Rel(scheduler, redis, "Planifie les jobs")
    Rel(odoo, api, "Webhooks statuts paiement", "HTTPS")
```

---

## 3. C4 — Niveau 3 : Composants du module Shipment (représentatif)

Structure identique pour tous les modules métier.

```mermaid
C4Component
    title Module Shipment - Composants (C4 niveau 3)

    Container_Boundary(shipment, "Module Shipment") {
        Component(controller, "ShipmentController", "Interface/Http", "Endpoints REST, validation FormRequest, API Resources")
        Component(commands, "Command Handlers", "Application", "CreateShipment, AdvanceWorkflowStep, CloseShipment...")
        Component(queries, "Query Handlers", "Application", "GetTimeline, SearchShipments, GetShipmentDetails (read models)")
        Component(aggregate, "Shipment (Agrégat)", "Domain", "Règles métier : transitions, clôture contrôlée, priorités")
        Component(workflow, "WorkflowEngine", "Domain Service", "Interprète le workflow configurable du tenant")
        Component(events, "Domain Events", "Domain", "ShipmentCreated, StepCompleted, DelayDetected, ShipmentClosed")
        Component(repo_port, "ShipmentRepository", "Domain Port", "Interface de persistance")
        Component(repo_impl, "EloquentShipmentRepository", "Infrastructure", "Implémentation PostgreSQL")
        Component(listeners, "Listeners", "Interface", "Écoute TrackingEventReceived (module Tracking), InvoicePaid (Billing)")
    }

    Container_Ext(other_tracking, "Module Tracking", "Émet TrackingEventReceived")
    Container_Ext(other_notif, "Module Notifications", "Écoute DelayDetected, StepCompleted")
    Container_Ext(other_audit, "Module Audit", "Écoute tous les événements")
    ContainerDb_Ext(db, "PostgreSQL", "Tables shipments, workflow_*")

    Rel(controller, commands, "Dispatch commande")
    Rel(controller, queries, "Dispatch query")
    Rel(commands, aggregate, "Charge, applique règles")
    Rel(aggregate, workflow, "Valide transitions")
    Rel(aggregate, events, "Émet")
    Rel(commands, repo_port, "Persiste via")
    Rel(repo_impl, repo_port, "Implémente")
    Rel(repo_impl, db, "SQL")
    Rel(queries, db, "Read models directs")
    Rel(other_tracking, listeners, "TrackingEventReceived")
    Rel(events, other_notif, "DelayDetected...")
    Rel(events, other_audit, "Tous événements")
```

---

## 4. C4 — Niveau 3 : Composants CarrierConnect (connecteurs)

```mermaid
flowchart TB
    subgraph Scheduler["Scheduler + File tracking"]
        JOB["Job RefreshTracking<br/>(par conteneur/BL actif)"]
    end

    subgraph CarrierConnect["Module CarrierConnect (Infrastructure)"]
        REG["CarrierRegistry<br/>résolution par code SCAC"]
        PORT["« Port » CarrierTrackingProvider<br/>trackContainer / trackBL / schedule / capabilities"]
        MSC["MscConnector"]
        CMA["CmaCgmConnector"]
        MAE["MaerskConnector"]
        OTHERS["HapagLloyd / COSCO / Evergreen<br/>ONE / OOCL / YangMing"]
        MANUAL["ManualTrackingAdapter<br/>(fallback saisie manuelle)"]
        CB["CircuitBreaker + RateLimiter<br/>par compagnie"]
        NORM["Normalizer<br/>statuts propriétaires → DCSA"]
        LOG["ExchangeLogger<br/>requête/réponse/latence"]
    end

    subgraph Tracking["Module Tracking (Domain)"]
        DEDUP["Déduplication<br/>(hash événement, idempotence)"]
        TE[("tracking_events")]
        DE["Événement domaine<br/>TrackingEventReceived"]
    end

    subgraph Effets["Réactions"]
        SHIP["Shipment : timeline, recalcul ETA,<br/>détection retard"]
        NOTIF["Notifications : départ, arrivée,<br/>retard selon préférences"]
    end

    APIS["APIs Compagnies externes"]

    JOB --> REG --> PORT
    PORT --> MSC & CMA & MAE & OTHERS & MANUAL
    MSC & CMA & MAE & OTHERS --> CB --> APIS
    APIS --> LOG --> NORM --> DEDUP --> TE --> DE
    DE --> SHIP --> NOTIF
```

---

## 5. ERD — Modèle de données (vue d'ensemble)

ERD de conception : entités et relations principales (~50 tables cœur). Dictionnaire de données complet, index et contraintes à l'Étape 6.

### 5.1 Tenancy, Identity, Référentiels

```mermaid
erDiagram
    TENANTS ||--o{ COMPANIES : "possède"
    COMPANIES ||--o{ BRANCHES : "possède"
    TENANTS ||--o{ USERS : "emploie"
    USERS }o--o{ BRANCHES : "affecté à (user_branches)"
    USERS }o--o{ ROLES : "a (user_roles)"
    ROLES }o--o{ PERMISSIONS : "contient (role_permissions)"
    TENANTS ||--o{ ROLES : "définit (rôles custom)"
    TENANTS ||--o{ WORKFLOW_DEFINITIONS : "configure"
    WORKFLOW_DEFINITIONS ||--o{ WORKFLOW_STEPS : "contient"

    TENANTS {
        uuid id PK
        string name
        string slug UK "sous-domaine"
        string plan
        jsonb settings
        timestamp created_at
    }
    COMPANIES {
        uuid id PK
        uuid tenant_id FK
        string legal_name
        string currency_code FK "devise de référence"
        jsonb invoice_settings "numérotation, mentions"
    }
    BRANCHES {
        uuid id PK
        uuid company_id FK
        string name
        string code UK "utilisé dans réf. dossier"
        string timezone
    }
    USERS {
        uuid id PK
        uuid tenant_id FK
        string email UK
        string password_hash "argon2id"
        string mfa_secret "chiffré, nullable"
        string locale
        boolean is_active
    }
    ROLES {
        uuid id PK
        uuid tenant_id FK "null = rôle système"
        string name
    }
    PERMISSIONS {
        string key PK "ex: shipments.create"
        string module
    }
    WORKFLOW_DEFINITIONS {
        uuid id PK
        uuid tenant_id FK
        string transport_mode "sea_fcl|sea_lcl|air|road|multimodal"
        string direction "import|export"
        boolean is_default
    }
    WORKFLOW_STEPS {
        uuid id PK
        uuid workflow_definition_id FK
        string key
        int position
        jsonb transitions "étapes suivantes autorisées"
        jsonb conditions "docs requis, approbation..."
    }
```

Référentiels globaux (sans tenant_id) : `countries`, `ports` (UN/LOCODE), `airports` (IATA), `currencies` + `exchange_rates` (datés), `incoterms`, `carriers` (maritimes, SCAC), `airlines` (préfixe AWB), `goods_types` (dont classes IMO/DGR).

### 5.2 CRM

```mermaid
erDiagram
    PARTIES ||--o{ PARTY_CONTACTS : "a"
    PARTIES ||--o{ PARTY_ADDRESSES : "a"
    PARTIES ||--o{ OPPORTUNITIES : "concerne"
    PARTIES ||--o{ COMPLAINTS : "dépose"
    COMPLAINTS }o--|| SHIPMENTS : "liée à"
    PARTIES ||--o{ PORTAL_ACCOUNTS : "accède via"

    PARTIES {
        uuid id PK
        uuid tenant_id FK
        string type "client|prospect|supplier"
        string supplier_kind "ocean_carrier|airline|trucker|customs_agent|... (si supplier)"
        string name
        string tax_id
        string currency_code
        string payment_terms
        decimal credit_limit "plafond encours"
        jsonb notification_prefs "défauts client"
        string odoo_id "mapping sync"
    }
    PARTY_CONTACTS {
        uuid id PK
        uuid party_id FK
        string name
        string email
        string phone
        string role
    }
    OPPORTUNITIES {
        uuid id PK
        uuid party_id FK
        string stage
        decimal estimated_value
        int probability
        uuid owner_id FK "commercial"
    }
    COMPLAINTS {
        uuid id PK
        uuid party_id FK
        uuid shipment_id FK
        string severity
        string status
        uuid assignee_id FK
        timestamp sla_due_at
    }
```

### 5.3 Dossiers (Shipment) — cœur du système

```mermaid
erDiagram
    SHIPMENTS ||--o{ SHIPMENT_EVENTS : "timeline"
    SHIPMENTS ||--o{ SHIPMENT_TASKS : "tâches"
    SHIPMENTS ||--o{ SHIPMENT_COMMENTS : "communication"
    SHIPMENTS ||--o{ SHIPMENT_DOCUMENTS : "checklist docs"
    SHIPMENTS ||--o{ TRANSPORT_SEGMENTS : "multimodal"
    SHIPMENTS ||--o{ CARGO_ITEMS : "marchandises"
    SHIPMENTS }o--|| PARTIES : "client"
    SHIPMENTS }o--|| BRANCHES : "agence"
    SHIPMENTS }o--|| USERS : "agent"
    SHIPMENTS }o--o| QUOTES : "issu de"

    SHIPMENTS {
        uuid id PK
        uuid tenant_id FK
        string reference UK "AGENCE-ANNEE-SEQ"
        uuid client_id FK
        uuid branch_id FK
        uuid agent_id FK "agent transit"
        uuid supervisor_id FK "responsable"
        string direction "import|export"
        string mode "sea_fcl|sea_lcl|air|road|multimodal"
        string status "clé étape workflow courante"
        uuid workflow_definition_id FK
        string incoterm FK
        string origin_locode
        string destination_locode
        string priority "low|normal|high|critical"
        timestamptz etd
        timestamptz eta
        timestamptz atd
        timestamptz ata
        decimal estimated_cost
        decimal estimated_revenue
        timestamp closed_at
    }
    SHIPMENT_EVENTS {
        uuid id PK
        uuid shipment_id FK
        string type "status_change|tracking|document|comment|system"
        jsonb payload
        uuid actor_id FK "null si système"
        timestamptz occurred_at
    }
    TRANSPORT_SEGMENTS {
        uuid id PK
        uuid shipment_id FK
        int position
        string mode "sea|air|road"
        string origin_locode
        string destination_locode
        timestamptz etd
        timestamptz eta
    }
    CARGO_ITEMS {
        uuid id PK
        uuid shipment_id FK
        uuid goods_type_id FK
        string description
        int packages_count
        decimal gross_weight_kg
        decimal volume_m3
        string un_number "si DGR"
    }
    SHIPMENT_TASKS {
        uuid id PK
        uuid shipment_id FK
        string title
        uuid assignee_id FK
        timestamptz due_at
        string status
    }
```

### 5.4 Maritime

```mermaid
erDiagram
    BOOKINGS }o--|| SHIPMENTS : "pour"
    BOOKINGS }o--|| PARTIES : "compagnie (carrier)"
    BOOKINGS }o--o| VOYAGES : "sur"
    VESSELS ||--o{ VOYAGES : "effectue"
    VOYAGES ||--o{ PORT_CALLS : "escales"
    CONTAINERS ||--o{ CONTAINER_ASSIGNMENTS : "affectations"
    CONTAINER_ASSIGNMENTS }o--|| SHIPMENTS : "au dossier"
    BILLS_OF_LADING }o--|| SHIPMENTS : "du dossier"
    BILLS_OF_LADING ||--o{ BILLS_OF_LADING : "MBL → HBL (parent_id)"
    CONSOLIDATIONS ||--o{ CONSOLIDATION_ITEMS : "regroupe"
    CONSOLIDATION_ITEMS }o--|| SHIPMENTS : "dossier LCL"
    CONSOLIDATIONS }o--|| CONTAINERS : "dans"

    BOOKINGS {
        uuid id PK
        uuid tenant_id FK
        uuid shipment_id FK
        uuid carrier_id FK
        string booking_number
        string status "requested|confirmed|rolled|cancelled"
        timestamptz vgm_cutoff
        timestamptz doc_cutoff
        timestamptz port_cutoff
    }
    CONTAINERS {
        uuid id PK
        uuid tenant_id FK
        string number "ISO 6346, check digit validé"
        string size_type "20GP|40GP|40HC|45HC|20RF|40RF|20OT|40FR..."
        decimal tare_kg
        decimal max_payload_kg
    }
    CONTAINER_ASSIGNMENTS {
        uuid id PK
        uuid container_id FK
        uuid shipment_id FK
        string seal_number
        decimal vgm_kg
        timestamptz free_time_ends_at "franchise surestaries"
    }
    VESSELS {
        uuid id PK
        string name
        string imo_number UK
        string mmsi
        string flag
    }
    VOYAGES {
        uuid id PK
        uuid vessel_id FK
        string voyage_number
    }
    PORT_CALLS {
        uuid id PK
        uuid voyage_id FK
        string port_locode
        int position
        timestamptz eta
        timestamptz etd
        timestamptz ata
        timestamptz atd
    }
    BILLS_OF_LADING {
        uuid id PK
        uuid tenant_id FK
        uuid shipment_id FK
        uuid parent_id FK "HBL → son MBL"
        string type "master|house"
        string number UK
        string release_type "original|telex|seaway"
        string status "draft|verified|issued|surrendered"
        jsonb parties "shipper, consignee, notify"
    }
    CONSOLIDATIONS {
        uuid id PK
        uuid tenant_id FK
        uuid container_id FK
        uuid master_bl_id FK
        string status "open|closed|deconsolidated"
    }
```

### 5.5 Aérien & Routier

```mermaid
erDiagram
    AIR_WAYBILLS }o--|| SHIPMENTS : "du dossier"
    AIR_WAYBILLS ||--o{ AIR_WAYBILLS : "MAWB → HAWB (parent_id)"
    AIR_WAYBILLS }o--|| AIRLINES : "compagnie"
    AIR_WAYBILLS ||--o{ FLIGHT_LEGS : "segments"
    TRUCKS ||--o{ MISSIONS : "affecté"
    DRIVERS ||--o{ MISSIONS : "conduit"
    MISSIONS }o--|| SHIPMENTS : "pour dossier"
    MISSIONS ||--o{ MISSION_STOPS : "points de passage"
    MISSIONS ||--o| PROOF_OF_DELIVERIES : "POD"

    AIR_WAYBILLS {
        uuid id PK
        uuid tenant_id FK
        uuid shipment_id FK
        uuid parent_id FK "HAWB → MAWB"
        string type "master|house"
        string number "11 chiffres, mod 7 validé"
        uuid airline_id FK
        decimal gross_weight_kg
        decimal chargeable_weight_kg "max(brut, vol/6000)"
        decimal volume_m3
        int packages_count
    }
    FLIGHT_LEGS {
        uuid id PK
        uuid awb_id FK
        string flight_number
        string origin_iata
        string destination_iata
        timestamptz departure_at
        timestamptz arrival_at
        int position
    }
    TRUCKS {
        uuid id PK
        uuid tenant_id FK
        string plate_number
        string type
        date inspection_due
        date insurance_due
    }
    DRIVERS {
        uuid id PK
        uuid tenant_id FK
        uuid user_id FK "compte rôle Chauffeur"
        string license_categories
        date license_expiry
    }
    MISSIONS {
        uuid id PK
        uuid tenant_id FK
        uuid shipment_id FK
        uuid truck_id FK
        uuid driver_id FK
        string status "planned|in_progress|delivered|failed"
        timestamptz window_start
        timestamptz window_end
    }
    PROOF_OF_DELIVERIES {
        uuid id PK
        uuid mission_id FK
        string recipient_name
        text signature_data
        jsonb photo_document_ids
        point geo_location
        timestamptz delivered_at
    }
```

### 5.6 Tracking, Documents, Cotation, Facturation, Intégrations

```mermaid
erDiagram
    TRACKING_SUBSCRIPTIONS ||--o{ TRACKING_EVENTS : "reçoit"
    TRACKING_EVENTS }o--|| SHIPMENTS : "alimente"
    DOCUMENTS ||--o{ DOCUMENT_VERSIONS : "versionné"
    DOCUMENTS }o--|| SHIPMENTS : "rattaché à"
    QUOTES ||--o{ QUOTE_LINES : "lignes"
    QUOTES }o--|| PARTIES : "client"
    TARIFFS ||--o{ TARIFF_LINES : "grille"
    INVOICES ||--o{ INVOICE_LINES : "lignes"
    INVOICES }o--|| SHIPMENTS : "facture le dossier"
    INVOICES ||--o{ ODOO_SYNC_LOGS : "tracée"
    NOTIFICATIONS }o--|| USERS : "destinataire interne"

    TRACKING_SUBSCRIPTIONS {
        uuid id PK
        uuid tenant_id FK
        string subject_type "container|bl|awb"
        string subject_number
        uuid shipment_id FK
        string carrier_scac
        string status "active|completed|error"
        timestamptz last_polled_at
    }
    TRACKING_EVENTS {
        uuid id PK
        uuid subscription_id FK
        string dcsa_event_code "normalisé"
        string raw_status "statut propriétaire"
        string location_locode
        timestamptz occurred_at
        string event_hash UK "déduplication"
        jsonb raw_payload
    }
    DOCUMENTS {
        uuid id PK
        uuid tenant_id FK
        uuid shipment_id FK
        string type "bl|hbl|mbl|awb|invoice|packing_list|certificate|insurance|customs|photo|other"
        string visibility "internal|client|confidential"
        string status "missing|received|validated"
        boolean is_archived
    }
    DOCUMENT_VERSIONS {
        uuid id PK
        uuid document_id FK
        int version
        string s3_key
        string mime_type
        bigint size_bytes
        string checksum_sha256
        uuid uploaded_by FK
        string av_scan_status
    }
    QUOTES {
        uuid id PK
        uuid tenant_id FK
        string number UK
        uuid party_id FK
        string status "draft|sent|accepted|rejected|expired"
        date valid_until
        int revision
        string currency_code
        decimal total_amount
        string odoo_id
    }
    QUOTE_LINES {
        uuid id PK
        uuid quote_id FK
        string service_code "freight|insurance|handling|customs|transport|other"
        string description
        decimal quantity
        string unit "container|kg|m3|flat|percent"
        decimal unit_price
        string currency_code
        decimal buy_price "coût estimé → marge"
    }
    TARIFFS {
        uuid id PK
        uuid tenant_id FK
        string name
        string mode
        string origin_locode
        string destination_locode
        string side "buy|sell"
        date valid_from
        date valid_to
    }
    INVOICES {
        uuid id PK
        uuid tenant_id FK
        uuid company_id FK "société émettrice"
        string type "proforma|invoice|credit_note"
        string number UK "séquence sans trou par société+type"
        uuid party_id FK
        uuid shipment_id FK
        uuid original_invoice_id FK "avoir → facture"
        string status "draft|validated|synced|paid|partial|unpaid"
        string currency_code
        decimal total_excl_tax
        decimal total_incl_tax
        string odoo_id
        string payment_status "rapatrié d'Odoo"
    }
    ODOO_SYNC_LOGS {
        uuid id PK
        uuid tenant_id FK
        string entity_type
        uuid entity_id
        string direction "push|pull"
        string status "pending|success|failed|conflict"
        jsonb payload
        text error
        int attempts
    }
    NOTIFICATIONS {
        uuid id PK
        uuid tenant_id FK
        string channel "email|sms|whatsapp|in_app"
        string event_type
        string recipient
        string status "queued|sent|delivered|failed"
        uuid shipment_id FK
    }
```

Tables transverses non détaillées ici (Étape 6) : `audit_logs` (append-only), `outbox_events`, `webhook_endpoints` + `webhook_deliveries`, `api_keys`, `notification_preferences` (matrice canal × événement), `notification_templates`, `saved_views`, `dashboard_widgets`, `exchange_rates`, `sequences` (numérotation sans trou), `portal_accounts`, `carrier_status_mappings`.

---

## 6. Flux métier — diagrammes de séquence

### 6.1 Machine à états — workflow dossier (défaut)

```mermaid
stateDiagram-v2
    [*] --> Creation
    Creation --> Booking : devis accepté / création directe
    Booking --> Depart : booking confirmé + cut-offs OK + docs requis
    Booking --> Booking : rollover (cut-off manqué)
    Depart --> Transit : ATD confirmé (tracking ou manuel)
    Transit --> Arrivee : ATA confirmé
    Transit --> Transit : escales, transbordements, retards
    Arrivee --> Dedouanement : docs douane complets
    Dedouanement --> Livraison : mainlevée douane
    Livraison --> Cloture : POD confirmé + facture émise
    Cloture --> [*]

    note right of Transit : Événements tracking DCSA<br/>recalcul ETA, détection retard
    note right of Cloture : Conditions vérifiées :<br/>livraison + facturation + docs archivés.<br/>Réouverture = permission dédiée
```

Chaque transition = configurable par tenant (étapes, conditions, approbations). Diagramme ci-dessus = workflow par défaut livré.

### 6.2 Séquence — Export maritime FCL (devis → dossier → booking)

```mermaid
sequenceDiagram
    autonumber
    actor C as Commercial
    actor CL as Client (portail)
    actor A as Agent Transit
    participant API as API SILARIS
    participant PR as Module Pricing
    participant SH as Module Shipment
    participant OC as Module Ocean
    participant NO as Notifications
    participant OD as File Odoo

    C->>API: Créer devis (client, trajet, conteneurs)
    API->>PR: Calcul (grilles, w/m, minimums, surcharges)
    PR-->>API: Lignes valorisées + marge estimée
    API-->>C: Devis brouillon
    C->>API: Envoyer devis
    API->>NO: Email devis PDF au client
    CL->>API: Accepte le devis (portail)
    API->>SH: CreateShipment (depuis devis)
    SH-->>API: Dossier créé (réf. AGENCE-2026-00123)
    SH->>NO: Événement ShipmentCreated → notifs internes
    A->>API: Créer booking (compagnie, navire/voyage)
    API->>OC: Booking requested
    OC-->>A: Cut-offs (VGM, doc, port) enregistrés + alertes planifiées
    A->>API: Affecter conteneur (n° ISO 6346 validé) + scellé
    A->>API: Booking confirmé → avancer workflow
    SH->>SH: Transition Creation→Booking (conditions OK)
    Note over OD: La facturation viendra plus tard<br/>(séquence 6.4)
```

### 6.3 Séquence — Tracking automatique + détection retard

```mermaid
sequenceDiagram
    autonumber
    participant SC as Scheduler
    participant Q as File tracking
    participant W as Worker
    participant REG as CarrierRegistry
    participant CON as MaerskConnector
    participant EXT as API Maersk
    participant TR as Module Tracking
    participant SH as Module Shipment
    participant NO as Notifications
    actor CL as Client

    SC->>Q: RefreshTracking(subscription) [fréquence tenant]
    Q->>W: Job consommé
    W->>REG: resolve("MAEU")
    REG-->>W: MaerskConnector
    W->>CON: trackContainer("MSKU1234567")
    CON->>EXT: GET /track (circuit breaker + rate limit)
    EXT-->>CON: Événements bruts propriétaires
    CON->>CON: ExchangeLogger (requête/réponse/latence)
    CON->>TR: Normalizer → événements DCSA
    TR->>TR: Déduplication (event_hash)
    TR->>SH: TrackingEventReceived (nouveaux uniquement)
    SH->>SH: Timeline + màj ETA
    alt Nouvelle ETA > ancienne + seuil
        SH->>SH: DelayDetected
        SH->>NO: Notification retard
        NO->>CL: Email/WhatsApp selon préférences client
    end
    alt API compagnie en panne
        CON--xW: Échecs répétés
        W->>W: Circuit ouvert → pause compagnie, retry backoff
    end
```

### 6.4 Séquence — Facturation + synchronisation Odoo

```mermaid
sequenceDiagram
    autonumber
    actor A as Agent / Comptable
    participant API as API SILARIS
    participant BI as Module Billing
    participant OB as Outbox (même transaction)
    participant Q as File odoo
    participant W as Worker OdooSync
    participant TRA as Translator
    participant OD as Odoo ERP

    A->>API: Valider facture (dossier X)
    API->>BI: ValidateInvoice
    BI->>BI: N° séquentiel sans trou (par société+type)
    BI->>OB: outbox: invoice.validated (transaction unique)
    BI-->>A: Facture validée (PDF dispo, portail client)
    OB->>Q: Publication job sync
    Q->>W: PushInvoice(invoice_id, idempotency_key)
    W->>TRA: SilarisInvoice → payload account.move
    W->>OD: create/update (JSON-RPC, mapping EntityMap)
    alt Succès
        OD-->>W: odoo_id
        W->>BI: status=synced + odoo_id persisté + sync_log OK
    else Odoo indisponible
        W->>W: Retry backoff → dead letter si épuisé
        Note over W: Mode dégradé : plateforme OK,<br/>file s'accumule, écran état Comptable
    end
    OD->>API: Webhook paiement enregistré
    API->>BI: payment_status = paid/partial
    BI->>API: Notification "facture payée" (si activée)
```

### 6.5 Séquence — Suivi public (invité)

```mermaid
sequenceDiagram
    autonumber
    actor V as Visiteur
    participant WEB as Next.js (SSR /track)
    participant API as API publique
    participant RL as Rate Limiter

    V->>WEB: Saisit "MSKU1234567" (ou réf dossier/BL/AWB)
    WEB->>API: GET /api/v1/public/tracking?q=...
    API->>RL: Vérif quota IP (strict)
    RL-->>API: OK
    API->>API: Résolution multi-type :<br/>conteneur → BL → dossier → AWB
    API-->>WEB: Timeline événements + statut + ETA<br/>(AUCUN montant, AUCUN document, AUCUN nom tiers)
    WEB-->>V: Page de suivi rendue (SSR)
```

---

## 7. Décisions prises à cette étape

1. **ERD de conception validant ~50 tables cœur** ; entités polymorphes évitées sauf `documents` et `tracking_subscriptions` (subject typé contrôlé).
2. **`parties` unifie clients/prospects/fournisseurs** (type + supplier_kind) — évite 3 tables quasi identiques, simplifie la sync Odoo (res.partner unique côté Odoo).
3. **MBL/HBL et MAWB/HAWB en auto-référence** (`parent_id`) — modélise 1 master → n house naturellement.
4. **`transport_segments`** porte le multimodal — un dossier a 1..n segments ordonnés, chaque segment son mode et son tracking.
5. **Timeline = `shipment_events`** unique (statuts, tracking, docs, commentaires, système) — source unique pour l'affichage chronologique.
6. **Numérotation sans trou** : table `sequences` verrouillée (SELECT FOR UPDATE) par société+type — exigence légale factures.
7. **Franchise surestaries** portée par `container_assignments.free_time_ends_at` → alertes planifiées.
8. Diagrammes en **Mermaid dans le repo** (versionnés, diffables) plutôt qu'images statiques.

---

## 8. Tâches restantes

- Étape 4 : maquettes UI/UX.
- Étape 6 : dictionnaire de données complet, index, contraintes, vues, stratégie d'archivage (raffinement de cet ERD).
- Diagrammes complémentaires produits au fil des étapes concernées (auth Étape 13, déploiement Étape 22).

---

*Fin de l'Étape 3. En attente de validation avant l'Étape 4 — Maquettes UI/UX.*
