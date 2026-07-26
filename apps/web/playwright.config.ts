import { defineConfig } from "@playwright/test";

export default defineConfig({
  testDir: "./e2e",
  // capture.spec.ts produit les visuels de la présentation commerciale : lancé
  // à la demande, il n'a rien à faire dans le run de non-régression.
  testIgnore: "**/capture.spec.ts",
  timeout: 30_000,
  retries: 1,
  // Le serveur API en CI est `php artisan serve` (mono-thread, sans opcache) :
  // le premier appel de chaque flux subit un cold-start. On élargit la fenêtre
  // d'assertion pour absorber cette latence sans rendre les tests permissifs
  // (le timeout global du test reste 30 s).
  expect: { timeout: 15_000 },
  use: {
    baseURL: process.env.E2E_BASE_URL ?? "http://localhost:56352",
    screenshot: "only-on-failure",
    actionTimeout: 15_000,
    navigationTimeout: 15_000,
  },
  // En CI : webServer démarrera API + front ; en local on réutilise les serveurs dev.
});
