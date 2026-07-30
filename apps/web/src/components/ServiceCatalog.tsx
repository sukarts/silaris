"use client";

import { useQuery } from "@tanstack/react-query";
import { rawApi } from "@/lib/api";

export interface CatalogItem {
  code: string;
  label: string;
  family: "customs" | "other";
  scope: "general" | "vehicle";
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
        <option key={item.code} value={item.label}>
          {item.scope === "vehicle" ? "Véhicule" : item.family === "customs" ? "Débours douane" : "Débours divers"}
        </option>
      ))}
    </datalist>
  );
}
