import { defineConfig } from "@playwright/test";

/**
 * Configuration dédiée aux captures de la présentation commerciale.
 * Séparée du run de non-régression, qui ignore ce spec.
 */
export default defineConfig({
  testDir: "./e2e",
  testMatch: "**/capture.spec.ts",
  timeout: 240_000,
  retries: 0,
  workers: 1,
  use: {
    baseURL: process.env.E2E_BASE_URL ?? "http://localhost:3000",
    actionTimeout: 20_000,
    navigationTimeout: 20_000,
  },
});
