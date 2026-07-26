"use client";

import { useState } from "react";
import { rawApi } from "@/lib/api";
import { buttonPrimary } from "@/components/Field";
import { TrackMap } from "@/components/TrackMap";

interface TrackingResult {
  reference: string;
  status: string;
  mode: string;
  origin_locode: string;
  destination_locode: string;
  origin_name: string | null;
  destination_name: string | null;
  vessel_name: string | null;
  tenant_name: string | null;
  logo_url: string | null;
  delivery: {
    status: string;
    latitude: number;
    longitude: number;
    updated_at: string;
    destination: { label: string; latitude: number; longitude: number } | null;
  } | null;
  eta: string | null;
  ata: string | null;
  events: { title: string; type: string; occurred_at: string }[];
}

const STATUS_LABEL: Record<string, string> = {
  creation: "En préparation",
  booking: "En préparation",
  departure: "Départ effectué",
  transit: "En transit",
  arrival: "Arrivé à destination",
  customs: "En dédouanement",
  delivery: "En cours de livraison",
  closure: "Livré",
};

export default function TrackPage() {
  const [query, setQuery] = useState("");
  const [result, setResult] = useState<TrackingResult | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  async function track(event: React.FormEvent) {
    event.preventDefault();
    setLoading(true);
    setError(null);
    setResult(null);
    const { data, error: problem } = await rawApi.GET("/v1/public/tracking", {
      params: { query: { q: query.trim() } },
    });
    setLoading(false);
    if (problem) {
      const p = problem as { status?: number };
      setError(
        p.status === 404
          ? "Aucune expédition trouvée pour ce numéro. Vérifiez la saisie, ou contactez votre transitaire si le dossier vient d'être ouvert."
          : "Numéro invalide ou service momentanément indisponible.",
      );
      return;
    }
    setResult(data as TrackingResult);
  }

  const modeIcon = (mode: string) => (mode.startsWith("sea") ? "🚢" : mode === "air" ? "✈" : "🚛");

  return (
    <main className="mx-auto w-full max-w-2xl px-5 py-12">
      <div className="text-center">
        {result?.logo_url ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img src={result.logo_url} alt={result.tenant_name ?? ""} className="mx-auto h-12 max-w-56 object-contain" />
        ) : (
          <div className="text-lg font-bold tracking-[0.18em]">
            {result?.tenant_name ?? "Suivi d'expédition"}
          </div>
        )}
        <p className="pb-7 pt-1 text-[13px] text-ink-3">
          {result?.logo_url && result?.tenant_name ? result.tenant_name : "Suivez votre expédition en temps réel"}
        </p>
      </div>

      <form onSubmit={track} className="flex gap-2">
        <input
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          placeholder="N° de dossier, BL, HBL, MBL, AWB ou conteneur"
          className="mono w-full rounded-lg border border-line-strong bg-surface px-4 py-3 text-sm uppercase focus:outline-2 focus:outline-accent"
        />
        <button disabled={loading || query.trim().length < 6} className={buttonPrimary}>
          {loading ? "…" : "Suivre"}
        </button>
      </form>
      <p className="pt-2 text-center text-[11px] text-ink-3">
        N° de dossier, BL, HBL, MBL, AWB ou conteneur
      </p>

      {error && <p className="mt-6 rounded-lg bg-crit-soft px-4 py-3 text-center text-[13px] text-crit">{error}</p>}

      {result && (
        <div className="mt-8 flex flex-col gap-4">
          <div className="rounded-xl bg-sea-soft p-5">
            <div className="flex flex-wrap items-center gap-4">
              <div>
                <div className="text-base font-bold">
                  {modeIcon(result.mode)} {STATUS_LABEL[result.status] ?? result.status}
                  {result.vessel_name ? ` — à bord du ${result.vessel_name}` : ""}
                </div>
                <div className="mono text-xs text-ink-2">
                  {result.reference}
                </div>
              </div>
              <div className="ml-auto text-right">
                <div className="text-[10px] uppercase tracking-wider text-ink-3">
                  {result.ata ? "Arrivée" : "Arrivée estimée"}
                </div>
                <div className="text-lg font-bold">
                  {result.ata
                    ? new Date(result.ata).toLocaleDateString("fr-FR")
                    : result.eta
                      ? new Date(result.eta).toLocaleDateString("fr-FR")
                      : "—"}
                </div>
              </div>
            </div>

            <div className="mt-4 flex items-center gap-3">
              <div className="text-right">
                <div className="text-sm font-bold">{result.origin_name ?? result.origin_locode}</div>
                <div className="mono text-[10px] text-ink-3">{result.origin_locode}</div>
              </div>
              <div className="relative flex-1 border-t-2 border-dashed border-sea/50">
                <span className="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 text-sm">
                  {modeIcon(result.mode)}
                </span>
              </div>
              <div>
                <div className="text-sm font-bold">{result.destination_name ?? result.destination_locode}</div>
                <div className="mono text-[10px] text-ink-3">{result.destination_locode}</div>
              </div>
            </div>
          </div>

          {result.delivery && (
            <div className="rounded-xl border border-line bg-surface p-4 shadow-sm">
              <div className="flex flex-wrap items-baseline gap-2 pb-3">
                <span className="text-[13px] font-bold">🚛 Livraison en cours</span>
                {result.delivery.destination && (
                  <span className="text-xs text-ink-3">vers {result.delivery.destination.label}</span>
                )}
                <span className="ml-auto text-[11px] text-ink-3">
                  position du {new Date(result.delivery.updated_at).toLocaleString("fr-FR", { day: "2-digit", month: "2-digit", hour: "2-digit", minute: "2-digit" })}
                </span>
              </div>
              <TrackMap
                vehicle={{ latitude: result.delivery.latitude, longitude: result.delivery.longitude, label: "Véhicule" }}
                stops={result.delivery.destination ? [{ ...result.delivery.destination, reached: false }] : []}
                height={260}
              />
              <p className="pt-2 text-[11px] text-ink-3">Position approximative, actualisée à chaque remontée du véhicule.</p>
            </div>
          )}

          <div className="rounded-xl border border-line bg-surface p-5 shadow-sm">
            <ul>
              {result.events.map((event, index) => (
                <li key={index} className={`relative ml-1.5 pl-6 ${index < result.events.length - 1 ? "border-l-2 border-line pb-4" : ""}`}>
                  <span className={`absolute -left-[7px] top-0.5 size-3 rounded-full border-[3px] bg-surface ${index === 0 ? "border-accent" : event.type === "status_change" ? "border-ok" : "border-sea"}`} />
                  <div className="text-[13px] font-semibold">{event.title}</div>
                  <div className="text-[11px] text-ink-3">
                    {new Date(event.occurred_at).toLocaleDateString("fr-FR", { day: "2-digit", month: "long", hour: "2-digit", minute: "2-digit" })}
                  </div>
                </li>
              ))}
            </ul>
          </div>
          <p className="text-center text-[11px] text-ink-3">
            Informations indicatives, mises à jour automatiquement depuis la compagnie.
          </p>
        </div>
      )}
    </main>
  );
}
