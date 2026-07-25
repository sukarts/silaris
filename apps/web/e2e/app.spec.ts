import { expect, test } from "@playwright/test";

const ADMIN = { email: "admin@demo.silaris.app", password: "password" };

test("connexion, tableau de bord et liste des dossiers", async ({ page }) => {
  // Login
  await page.goto("/login");
  await page.getByRole("textbox").first().fill(ADMIN.email);
  await page.locator('input[type="password"]').fill(ADMIN.password);
  await page.getByRole("button", { name: "Se connecter" }).click();

  // Dashboard : KPIs réels chargés depuis l'API
  await expect(page).toHaveURL(/\/dashboard/);
  await expect(page.getByText("Dossiers actifs")).toBeVisible();
  await expect(page.getByText("CA opérationnel — mois")).toBeVisible();

  // Navigation dossiers : lignes présentes
  await page.getByRole("link", { name: "Dossiers", exact: true }).click();
  await expect(page).toHaveURL(/\/shipments/);
  await expect(page.getByRole("link", { name: /TAL-2026-\d+/ }).first()).toBeVisible();

  // Détail dossier : stepper workflow visible
  await page.getByRole("link", { name: /TAL-2026-00128/ }).click();
  await expect(page.getByText("Timeline")).toBeVisible();
  await expect(page.getByText("Informations")).toBeVisible();
});

test("suivi public par numéro de conteneur, sans authentification", async ({ page }) => {
  await page.goto("/track");
  await page.getByRole("textbox").fill("MSKU8842016");
  await page.getByRole("button", { name: "Suivre" }).click();

  await expect(page.getByText("TAL-2026-00128")).toBeVisible();
  await expect(page.getByText(/Arrivée/i).first()).toBeVisible();
});

test("mauvais identifiants → erreur affichée, pas de session", async ({ page }) => {
  await page.goto("/login");
  await page.getByRole("textbox").first().fill("admin@demo.silaris.app");
  await page.locator('input[type="password"]').fill("mauvais-mot-de-passe");
  await page.getByRole("button", { name: "Se connecter" }).click();

  await expect(page.getByText("Identifiants invalides.")).toBeVisible();
  await expect(page).toHaveURL(/\/login/);
});
