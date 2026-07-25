# Étape 24 — Documentation Développeur

**Projet :** SILARIS · **Statut :** Livré · **Prérequis :** Étape 23 validée

## Livrables
| Document | Contenu |
|---|---|
| [README.md](../README.md) | Vue d'ensemble produit, stack, quickstart (make up → fresh → api/web), comptes démo, index documentation, exigences qualité |
| [docs/dev/onboarding.md](dev/onboarding.md) | Machine vierge → env complet en ~20 min ; **tableau des pièges réellement rencontrés** pendant le développement (node 16/corepack, RLS hors contexte, cache guard Sanctum en test, build vs dev Next) |
| [docs/dev/architecture.md](dev/architecture.md) | Guide opérationnel : 4 couches, règles cassant la CI, multi-tenant 3 couches, écritures vs lectures, **recettes** (endpoint, module, connecteur compagnie, événement de domaine), règles frontend |
| [docs/dev/conventions.md](dev/conventions.md) | PHP (Pint/Larastan/nommage/migrations/Money), TS (client généré only), API (RFC 9457, can: obligatoire, make openapi), tests, git |
| [docs/dev/api.md](dev/api.md) | 4 espaces d'API, flux auth+MFA, format d'erreurs avec `error_code`, pagination curseur, rate limits, usage client TS |

Principe : documentation courte, exclusivement adossée au code réel — les pièges listés sont ceux rencontrés en construisant, pas des généralités. La conception profonde reste dans `docs/etape-*.md` (source de vérité), les guides dev y renvoient.

---
*Fin de l'Étape 24. En attente de validation avant l'Étape 25 — Documentation utilisateur.*
