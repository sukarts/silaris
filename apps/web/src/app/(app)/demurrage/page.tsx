"use client";

import { useQuery } from "@tanstack/react-query";
import Link from "next/link";
import { useState } from "react";
import { rawApi } from "@/lib/api";

interface DemurrageRow {
  assignment_id: string;
  kind: "demurrage" | "detention";
  container_number: string;
  size_type: string | null;
  shipment_id: string;
  reference: string;
  direction: string;
  client_name: string | null;
  free_time_days: number | null;
  free_time_ends_at: string;
  days_remaining: number;
  severity: "overdue" | "critical" | "warning";
}

// Surestarie = conteneur au terminal ; détention = conteneur chez le client.
// Deux immobilisations distinctes, deux factures.
const KIND: Record<string, { label: string; badge: string }> = {
  demurrage: { label: "Surestaries", badge: "bg-sea-soft text-sea" },
  detention: { label: "Détention", badge: "bg-warn-soft text-warn" },
};

const SEVERITY: Record<string, { badge: string; label: (days: number) => string }> = {
  overdue: {
    badge: "bg-crit-soft text-crit",
    label: (days) => `${Math.abs(days)} j de dépassement`,
  },
  critical: {
    badge: "bg-warn-soft text-warn",
    label: (days) => (days === 0 ? "Expire aujourd'hui" : `${days} j restant${days > 1 ? "s" : ""}`),
  },
  warning: {
    badge: "bg-sea-soft text-sea",
    label: (days) => `${days} j restants`,
  },
};

export default function DemurragePage() {
  const [horizon, setHorizon] = useState("7");

  const { data, isLoading } = useQuery({
    queryKey: ["demurrage", horizon],
    queryFn: async () => {
      const { data: response } = await rawApi.GET("/v1/demurrage", {
        params: { query: { within_days: Number(horizon) } },
      });
      return response as {
        data: DemurrageRow[];
        summary: { overdue: number; critical: number; warning: number };
      };
    },
    // La franchise se joue à la journée : une donnée d'hier induit en erreur.
    refetchInterval: 5 * 60 * 1000,
  });

  const rows = data?.data ?? [];

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-start">
        <div>
          <h1 className="text-xl font-bold">Surestaries &amp; détention</h1>
          <p className="text-[13px] text-ink-3">
            Franchises qui expirent — surestaries au terminal, détention chez le client. Au-delà, la compagnie facture chaque jour entamé.
          </p>
        </div>
        <select
          value={horizon}
          onChange={(event) => setHorizon(event.target.value)}
          className="ml-auto rounded-lg border border-line-strong bg-surface px-3 py-1.5 text-[13px]"
        >
          <option value="3">3 jours</option>
          <option value="7">7 jours</option>
          <option value="15">15 jours</option>
          <option value="30">30 jours</option>
        </select>
      </div>

      <div className="grid gap-3 sm:grid-cols-3">
        {([
          ["overdue", "En dépassement", "text-crit"],
          ["critical", "Sous 3 jours", "text-warn"],
          ["warning", "À surveiller", "text-sea"],
        ] as const).map(([key, label, tone]) => (
          <div key={key} className="rounded-xl border border-line bg-surface p-4 shadow-sm">
            <div className="text-[10px] uppercase tracking-wider text-ink-3">{label}</div>
            <div className={`mt-1 text-2xl font-bold ${tone}`}>{data?.summary[key] ?? 0}</div>
          </div>
        ))}
      </div>

      <div className="overflow-x-auto rounded-xl border border-line bg-surface shadow-sm">
        <table className="w-full text-[13px]">
          <thead>
            <tr className="border-b border-line text-left text-[10px] uppercase tracking-wider text-ink-3">
              <th className="px-3 py-2.5">Conteneur</th>
              <th className="px-3 py-2.5">Type</th>
              <th className="px-3 py-2.5">Dossier</th>
              <th className="px-3 py-2.5">Client</th>
              <th className="px-3 py-2.5">Sens</th>
              <th className="px-3 py-2.5">Franchise</th>
              <th className="px-3 py-2.5">Échéance</th>
              <th className="px-3 py-2.5">État</th>
            </tr>
          </thead>
          <tbody>
            {isLoading && (
              <tr><td colSpan={8} className="px-3 py-8 text-center text-ink-3">Chargement…</td></tr>
            )}
            {!isLoading && rows.length === 0 && (
              <tr>
                <td colSpan={8} className="px-3 py-8 text-center text-ink-3">
                  Aucune franchise à échéance sur cette période.
                </td>
              </tr>
            )}
            {rows.map((row) => (
              <tr key={`${row.kind}-${row.assignment_id}`} className="border-b border-line last:border-0 hover:bg-sea/5">
                <td className="mono px-3 py-2.5 font-semibold">
                  {row.container_number}
                  {row.size_type && <span className="ml-1.5 text-[11px] font-normal text-ink-3">{row.size_type}</span>}
                </td>
                <td className="px-3 py-2.5">
                  <span className={`rounded-full px-2 py-0.5 text-[10px] font-semibold ${KIND[row.kind]?.badge ?? ""}`}>
                    {KIND[row.kind]?.label ?? row.kind}
                  </span>
                </td>
                <td className="px-3 py-2.5">
                  <Link href={`/shipments/${row.shipment_id}`} className="mono font-semibold text-sea hover:underline">
                    {row.reference}
                  </Link>
                </td>
                <td className="px-3 py-2.5 text-ink-2">{row.client_name ?? "—"}</td>
                <td className="px-3 py-2.5 text-ink-2">{row.direction === "export" ? "Export" : "Import"}</td>
                <td className="mono px-3 py-2.5 text-ink-2">{row.free_time_days ?? "—"} j</td>
                <td className="mono px-3 py-2.5">{new Date(row.free_time_ends_at).toLocaleDateString("fr-FR")}</td>
                <td className="px-3 py-2.5">
                  <span className={`rounded-full px-2.5 py-0.5 text-[11px] font-semibold ${SEVERITY[row.severity]!.badge}`}>
                    {SEVERITY[row.severity]!.label(row.days_remaining)}
                  </span>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
