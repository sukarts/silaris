import { defineConfig } from "@playwright/test";

export default defineConfig({
  testDir: "./e2e",
  timeout: 30_000,
  retries: 1,
  use: {
    baseURL: process.env.E2E_BASE_URL ?? "http://localhost:56352",
    screenshot: "only-on-failure",
  },
  // En CI : webServer démarrera API + front ; en local on réutilise les serveurs dev.
});
