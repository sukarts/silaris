import { test } from "@playwright/test";
import path from "node:path";

/**
 * Captures d'écran pour la présentation commerciale.
 *
 * Ce n'est pas un test de non-régression : le fichier produit les visuels de
 * chaque module à partir du jeu de démonstration. Lancé à la demande, il est
 * exclu du run E2E de la CI (voir testIgnore dans playwright.config.ts).
 */
const ADMIN = { email: "admin@demo.silaris.app", password: "password" };
const OUT = process.env.CAPTURE_DIR ?? path.resolve("captures");

/** Écrans authentifiés, capturés dans l'ordre du menu. */
const SCREENS: { route: string; name: string; setup?: (page: import("@playwright/test").Page) => Promise<void> }[] = [
  { route: "/dashboard", name: "01-tableau-de-bord" },
  { route: "/shipments", name: "02-dossiers" },
  { route: "/bookings", name: "04-bookings" },
  { route: "/containers", name: "05-conteneurs" },
  { route: "/air", name: "06-aerien" },
  { route: "/road", name: "07-routier-missions" },
  {
    route: "/road",
    name: "08-routier-flotte",
    setup: async (page) => await page.getByRole("button", { name: "Flotte" }).click(),
  },
  {
    route: "/road",
    name: "09-routier-balises",
    setup: async (page) => await page.getByRole("button", { name: "Balises" }).click(),
  },
  { route: "/crm", name: "10-crm" },
  { route: "/quotes", name: "11-cotations" },
  { route: "/billing", name: "12-facturation" },
  { route: "/documents", name: "13-documents" },
  { route: "/admin", name: "14-administration" },
  { route: "/settings", name: "15-parametres-societe" },
  {
    route: "/settings",
    name: "16-parametres-agences",
    setup: async (page) => await page.getByRole("button", { name: "Agences" }).click(),
  },
  { route: "/profile", name: "17-profil-mfa" },
];

test.use({ viewport: { width: 1500, height: 940 }, deviceScaleFactor: 2 });

test("captures des modules authentifiés", async ({ page }) => {
  test.setTimeout(240_000);

  await page.goto("/login");
  await page.getByRole("textbox").first().fill(ADMIN.email);
  await page.locator('input[type="password"]').fill(ADMIN.password);
  await page.getByRole("button", { name: "Se connecter" }).click();
  await page.waitForURL(/\/dashboard/);

  for (const screen of SCREENS) {
    await page.goto(screen.route);
    // Les listes se peuplent après la requête : on laisse le réseau retomber
    // avant d'agir sur l'écran, sinon l'onglet visé n'est pas encore monté.
    await page.waitForLoadState("networkidle").catch(() => undefined);
    await screen.setup?.(page);
    await page.waitForTimeout(800);
    await page.screenshot({ path: path.join(OUT, `${screen.name}.png`) });
  }

  // Détail d'un dossier : stepper workflow, timeline, documents.
  await page.goto("/shipments");
  await page.getByRole("link", { name: /TAL-2026-00128/ }).click();
  await page.waitForLoadState("networkidle").catch(() => undefined);
  await page.waitForTimeout(700);
  await page.screenshot({ path: path.join(OUT, "03-dossier-detail.png") });
});

test("captures des écrans publics et du portail client", async ({ page }) => {
  test.setTimeout(120_000);

  // Suivi public : sans authentification, par numéro de conteneur.
  await page.goto("/track");
  await page.getByRole("textbox").fill("MSKU8842016");
  await page.getByRole("button", { name: "Suivre" }).click();
  await page.waitForTimeout(1500);
  await page.screenshot({ path: path.join(OUT, "18-suivi-public.png") });

  await page.goto("/login");
  await page.waitForTimeout(500);
  await page.screenshot({ path: path.join(OUT, "20-connexion.png") });

  await page.goto("/portal/login");
  await page.waitForTimeout(500);
  await page.screenshot({ path: path.join(OUT, "19-portail-connexion.png") });
});
