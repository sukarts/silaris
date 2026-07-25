# Étape 21 — Tests

**Projet :** SILARIS · **Statut :** Exécuté — suites vertes · **Prérequis :** Étape 20 validée

---

## 1. Pyramide livrée

| Couche | Outil | Volume | Durée | Couvre |
|---|---|---|---|---|
| **Unitaires Domain** | Pest (pur, sans Laravel) | 18 tests | 0,15 s | Agrégat Shipment (transitions, clôture contrôlée/idempotente, détection retard vs seuil, reconstitution sans événements), Money (parsing décimal exact, devises), **QuoteCalculator** (marge, poids taxable aérien, w/m, minimum de perception, % assurance, taille absente) |
| **Architecture** | Pest Arch | 6 tests / 46 assertions | 3 s | **Les ADR deviennent exécutables** : Domain sans framework, Domain sans Eloquent, Domains métier étanches entre eux, ACL non contaminantes, strict_types partout, zéro dd/dump |
| **Feature API** | Pest + RefreshDatabase (base `silaris_test`) | 7 tests | ~8 s | Flux dossier complet (référence séquencée, outbox, garde documentaire 422+error_code, transition illégale), RBAC 4 rôles (403/401), facturation (numéro légal, échéance +30 j, immuabilité, avoir lié), Odoo (3 tests Étape 20), suivi public (données limitées, 404, 422), **cloisonnement portail** (client A ne voit pas client B, tokens croisés 403) |
| **E2E navigateur** | Playwright (Chromium) | 3 specs | 10 s | Login → dashboard KPIs → liste dossiers → détail (timeline) ; suivi public par conteneur sans auth ; identifiants invalides |

**Total backend : 34 tests / 127 assertions / 15,5 s** — exécutable d'une commande (`php artisan test`), prêt CI.

## 2. Infrastructure de test
- Base PostgreSQL dédiée `silaris_test` (RefreshDatabase — jamais la base dev).
- `tests/Fixtures.php` : `seedCore()` (tenant + org + rôles système réels via PermissionSeeder + 4 utilisateurs typés + workflow 4 étapes + client + compte portail), `tokenFor()`/`portalTokenFor()`, `freshAuth()`.
- `phpunit.xml` : mémoire 512 M (php-parser des tests arch), suite Architecture déclarée.
- Playwright : config `E2E_BASE_URL`, screenshots on failure, retries 1 — `webServer` CI à câbler Étape 23.

## 3. Pièges neutralisés (consignés pour l'équipe)
1. Guard Sanctum mis en cache entre requêtes d'un même test → `freshAuth()` obligatoire entre deux identités.
2. `withToken()` persiste l'en-tête → `withoutToken()` pour tester le non-authentifié.
3. Tests arch : 128 M insuffisants pour parser 130+ fichiers.
4. Locator Playwright ambigu (sidebar vs lien « Tous les dossiers ») → `exact: true`.

## 4. Reste
- Couverture Application handlers supplémentaires (CloseShipment facts, ingestion tracking) — la CI (Étape 23) imposera un seuil.
- E2E portail client + parcours création dossier (après stabilisation UI passe 2).

---

*Fin de l'Étape 21. En attente de validation avant l'Étape 22 — Docker.*
