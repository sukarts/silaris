"use client";

import { useQuery } from "@tanstack/react-query";
import { rawApi } from "@/lib/api";

export interface CatalogItem {
  code: string;
  label: string;
  family: "customs" | "other";
  scope: "general" | "vehicle";
  default_tc20: string | null;
  default_tc40: string | null;
  pricing_note: string | null;
}

/** Tarif standard proposé pour un poste (TC40 de préférence), ou null. */
export function suggestedAmount(item: CatalogItem): number | null {
  const raw = item.default_tc40 ?? item.default_tc20;
  return raw === null ? null : Number(raw);
}

/** Note de tarif lisible à afficher en aide (barème ou base de calcul). */
export function tariffHint(item: CatalogItem): string | null {
  const tc20 = item.default_tc20 === null ? null : Number(item.default_tc20);
  const tc40 = item.default_tc40 === null ? null : Number(item.default_tc40);
  if (tc20 !== null && tc40 !== null && tc20 !== tc40) {
    return `TC20 ${tc20.toLocaleString("fr-FR")} · TC40 ${tc40.toLocaleString("fr-FR")}`;
  }
  if (tc40 !== null) return `${tc40.toLocaleString("fr-FR")} F`;
  return item.pricing_note;
}

/**
 * Catalogue des prestations, proposé à la saisie des lignes.
 *
 * Il complète la saisie libre, il ne la remplace pas : une ligne hors catalogue
 * reste possible. Choisir un libellé connu renseigne son code et sa famille,
 * pour que les postes se recoupent d'un dossier à l'autre.
 */
export function useServiceCatalog() {
  const { data } = useQuery({
    queryKey: ["service-catalog"],
    staleTime: 60 * 60 * 1000, // Référentiel stable : inutile de le rappeler souvent.
    queryFn: async () => {
      const { data: response } = await rawApi.GET("/v1/service-catalog");
      return (response as { data: CatalogItem[] }).data;
    },
  });
  const items = data ?? [];

  return {
    items,
    /** Retrouve le poste dont le libellé correspond exactement à la saisie. */
    resolve: (label: string): CatalogItem | undefined =>
      items.find((item) => item.label.toLowerCase() === label.trim().toLowerCase()),
  };
}

/**
 * Liste de suggestions rattachée à un champ par `list="service-catalog"`.
 * Rendue une fois par écran ; les postes véhicule sont annotés pour ne pas se
 * confondre avec les prestations courantes.
 */
export function ServiceCatalogDatalist({ items }: { items: CatalogItem[] }) {
  return (
    <datalist id="service-catalog">
      {items.map((item) => (
        // Le texte de l'option est ce que le navigateur affiche et filtre ; il
        // porte donc le libellé de la prestation. La `value` reste le libellé
        // seul : c'est elle qui remplit le champ à la sélection, et que
        // `resolve()` retrouve pour poser code et famille.
        <option key={item.code} value={item.label}>
          {item.label} · {familyLabel(item)}{tariffHint(item) ? ` · ${tariffHint(item)}` : ""}
        </option>
      ))}
    </datalist>
  );
}

function familyLabel(item: CatalogItem): string {
  if (item.scope === "vehicle") return "Véhicule";
  return item.family === "customs" ? "Débours douane" : "Débours divers";
}
