"use client";

import { useState } from "react";
import { rawApi } from "@/lib/api";
import { buttonPrimary } from "@/components/Field";

interface TrackingResult {
  reference: string;
  status: string;
  mode: string;
  origin_locode: string;
  destination_locode: string;
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
      setError(p.status === 404 ? "Aucune expédition trouvée pour ce numéro." : "Numéro invalide ou service indisponible.");
      return;
    }
    setResult(data as TrackingResult);
  }

  return (
    <main className="mx-auto w-full max-w-2xl px-5 py-12">
      <div className="text-center">
        <div className="text-lg font-bold tracking-[0.18em]">
          SILA<span className="text-accent">RIS</span>
        </div>
        <p className="pb-7 text-[13px] text-ink-3">Suivi d'expédition</p>
      </div>

      <form onSubmit={track} className="flex gap-2">
        <input
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          placeholder="N° de dossier, BL, AWB ou conteneur"
          className="mono w-full rounded-lg border border-line-strong bg-surface px-4 py-3 text-sm uppercase focus:outline-2 focus:outline-accent"
        />
        <button disabled={loading || query.trim().length < 6} className={buttonPrimary}>
          {loading ? "…" : "Suivre"}
        </button>
      </form>
      <p className="pt-2 text-center text-[11px] text-ink-3">
        Exemple : TAL-2026-00128, MEDUJ2260417 ou MSKU8842016
      </p>

      {error && <p className="mt-6 rounded-lg bg-crit-soft px-4 py-3 text-center text-[13px] text-crit">{error}</p>}

      {result && (
        <div className="mt-8 flex flex-col gap-4">
          <div className="flex flex-wrap items-center gap-4 rounded-xl bg-sea-soft p-5">
            <div>
              <div className="text-base font-bold">
                {result.mode.startsWith("sea") ? "🚢" : result.mode === "air" ? "✈" : "🚛"}{" "}
                {STATUS_LABEL[result.status] ?? result.status}
              </div>
              <div className="mono text-xs text-ink-2">
                {result.reference} · {result.origin_locode} → {result.destination_locode}
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

          <div className="rounded-xl border border-line bg-surface p-5 shadow-sm">
            <ul>
              {result.events.map((event, index) => (
                <li key={index} className={`relative ml-1.5 pl-6 ${index < result.events.length - 1 ? "border-l-2 border-line pb-4" : ""}`}>
                  <span className={`absolute -left-[7px] top-0.5 size-3 rounded-full border-[3px] bg-surface ${index === 0 ? "border-accent" : "border-sea"}`} />
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
