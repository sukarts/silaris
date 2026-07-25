# Conventions

Imposées par la CI (Pint, Larastan, Pest Arch) — le reste est revue de code.

## PHP
- `declare(strict_types=1)` partout (auto via Pint). Preset `laravel` + imports alphabétiques.
- Larastan niveau 5, baseline gelée : **ne jamais ajouter à la baseline** — corriger ou annoter précisément.
- Nommage : `FooCommand`/`FooHandler` (même dossier) ; modèles Eloquent `XxxModel` ; exceptions métier héritent de `Shared\Domain\Exception\DomainException` avec `errorCode()` stable (`module.raison`).
- Migrations : `2026_MM_JJ_NNNNNN_<module>_<action>.php`, toujours réversibles ; SQL avancé (partitions, RLS, triggers) via `DB::unprepared` avec `down()` complet.
- Argent : `numeric` en base, `Money` (sous-unités entières) en domaine — jamais de float métier.
- Horodatage : `timestamptz`, UTC en base.

## TypeScript
- Strict, `noUncheckedIndexedAccess`. Composants PascalCase, hooks `useX`.
- Jamais de fetch hors `@silaris/api-client`. État serveur = TanStack Query ; état UI = Zustand.
- Texte UI en français ; vocabulaire client simplifié sur portail/public (jamais ETA/cut-off brut).

## API
- Erreurs : RFC 9457 uniquement (`problem()`), `error_code` stable pour le front.
- Routes kebab-case pluriel ; actions métier en `POST /resource/{id}/action` ; UUID contraints `whereUuid`.
- **Chaque route porte `->can('module.action')`.** Toute évolution d'API ⇒ `make openapi` (le CI diff le client généré).

## Tests
- Domain : purs (tests/Unit), pas de DB.
- Feature : `seedCore()` + `freshAuth()` entre identités ; base `silaris_test`.
- Toute règle d'architecture nouvelle → assertion dans `tests/Architecture/`.

## Git
- Branches `feat/…`, `fix/…` ; messages impératifs concis ; PR = CI verte obligatoire.
