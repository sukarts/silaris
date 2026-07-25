# Étape 26 — Guide de Déploiement

**Statut :** Livré · [docs/exploitation/guide-deploiement.md](exploitation/guide-deploiement.md)

Couvre : prérequis (services managés recommandés), génération/gestion des secrets (dont criticité APP_KEY), **setup PostgreSQL avec séparation propriétaire/rôle applicatif** (condition de validité de la RLS), déploiement K8s pas à pas (secrets → kustomize → initContainer migrate → seeds référentiels → premier tenant), politique de rollback (migrations additives, expand/contract), **mode installation privée** Compose (promesse Étape 1 tenue), checklist go-live 10 points dont test de restauration obligatoire.

Décisions : jamais de DemoTenantSeeder en prod (double protection : consigne + garde dans le code) ; le déploiement échoue si la migration échoue ; onboarding tenant = procédure super-admin (UI plateforme au backlog).

---
*En attente de validation avant l'Étape 27 — Guide d'exploitation.*
