"use client";

import { useQuery } from "@tanstack/react-query";
import Link from "next/link";
import { rawApi } from "@/lib/api";
import { StatusPill } from "@/components/StatusPill";
import { useAuth } from "@/stores/auth";

interface DashboardPayload {
  kpis: {
    active_shipments: number;
    import: number;
    export: number;
    containers_active: number;
    delays: number;
    missing_documents: number;
    revenue_month: number;
  };
  volumes: { month: string; import: number; export: number }[];
  alerts: { severity: "critical" | "warning" | "info"; title: string; context: string; shipment_id: string }[];
  recent_shipments: {
    id: string;
    reference: string;
    mode: string;
    status: string;
    client_name: string;
    origin_locode: string;
    destination_locode: string;
    eta: string | null;
    is_delayed: boolean;
    agent_name: string;
  }[];
}

const MODE_LABEL: Record<string, string> = {
  sea_fcl: "🚢 FCL",
  sea_lcl: "🚢 LCL",
  air: "✈ Aérien",
  road: "🚛 Routier",
  multimodal: "⛓ Multi",
};

const MONTH_LABEL = ["Jan", "Fév", "Mar", "Avr", "Mai", "Juin", "Juil", "Août", "Sep", "Oct", "Nov", "Déc"];

function formatMoney(value: number): string {
  return new Intl.NumberFormat("fr-FR", { notation: value >= 1_000_000 ? "compact" : "standard", maximumFractionDigits: 1 }).format(value);
}

export default function DashboardPage() {
  const user = useAuth((state) => state.user);
  const { data, isLoading } = useQuery({
    queryKey: ["dashboard"],
    refetchInterval: 60_000,
    queryFn: async () => {
      const { data: response } = await rawApi.GET("/v1/dashboard");
      return response as DashboardPayload;
    },
  });

  const kpis = data?.kpis;
  const maxVolume = Math.max(1, ...(data?.volumes.map((v) => v.import + v.export) ?? [1]));

  const cards = [
    { label: "Dossiers actifs", value: kpis?.active_shipments, sub: `${kpis?.import ?? 0} import · ${kpis?.export ?? 0} export`, tone: "" },
    { label: "Conteneurs actifs", value: kpis?.containers_active, sub: "affectations en cours", tone: "" },
    { label: "Retards détectés", value: kpis?.delays, sub: "vs ETA initiale", tone: kpis?.delays ? "text-crit" : "" },
    { label: "Docs manquants", value: kpis?.missing_documents, sub: "dossiers ouverts", tone: kpis?.missing_documents ? "text-warn" : "" },
    { label: "CA opérationnel — mois", value: kpis ? formatMoney(kpis.revenue_month) : undefined, sub: "factures validées · XOF", tone: "" },
  ];

  return (
    <div className="flex flex-col gap-5">
      <div>
        <h1 className="text-xl font-bold">Bonjour {user?.first_name}</h1>
        <p className="text-[13px] text-ink-3">
          {isLoading ? "Chargement…" : `${data?.alerts.length ?? 0} alerte(s) à traiter`}
        </p>
      </div>

      <div className="grid grid-cols-2 gap-3.5 md:grid-cols-3 xl:grid-cols-5">
        {cards.map((card) => (
          <div key={card.label} className="rounded-xl border border-line bg-surface px-4 py-3.5 shadow-sm">
            <div className="text-[11px] uppercase tracking-wider text-ink-3">{card.label}</div>
            <div className={`mono mt-1 text-2xl font-bold ${card.tone}`}>{card.value ?? "…"}</div>
            <div className="mt-0.5 text-xs text-ink-3">{card.sub}</div>
          </div>
        ))}
      </div>

      <div className="grid gap-3.5 xl:grid-cols-[1.6fr_1fr]">
        {/* Volumes */}
        <div className="rounded-xl border border-line bg-surface shadow-sm">
          <div className="flex items-center border-b border-line px-4 py-3">
            <h2 className="text-[13px] font-bold">Volumes import / export — 6 derniers mois</h2>
          </div>
          <div className="p-4">
            <div className="flex h-40 items-end gap-3" role="img" aria-label="Histogramme des volumes par mois">
              {data?.volumes.map((volume) => {
                const total = volume.import + volume.export;
                const monthIndex = Number(volume.month.slice(5)) - 1;
                return (
                  <div key={volume.month} className="flex h-full flex-1 flex-col justify-end gap-0.5">
                    {volume.import > 0 && (
                      <div className="rounded-t-sm bg-accent" style={{ height: `${(volume.import / maxVolume) * 100}%` }} title={`Import: ${volume.import}`} />
                    )}
                    {volume.export > 0 && (
                      <div className={`bg-sea ${volume.import === 0 ? "rounded-t-sm" : ""}`} style={{ height: `${(volume.export / maxVolume) * 100}%` }} title={`Export: ${volume.export}`} />
                    )}
                    {total === 0 && <div className="h-px bg-line" />}
                    <span className="pt-1 text-center text-[10px] text-ink-3">{MONTH_LABEL[monthIndex]}</span>
                  </div>
                );
              })}
            </div>
            <div className="flex gap-4 pt-3 text-[11px] text-ink-2">
              <span className="flex items-center gap-1.5"><i className="size-2 rounded-sm bg-accent" /> Import</span>
              <span className="flex items-center gap-1.5"><i className="size-2 rounded-sm bg-sea" /> Export</span>
            </div>
          </div>
        </div>

        {/* Alertes */}
        <div className="rounded-xl border border-line bg-surface shadow-sm">
          <div className="flex items-center border-b border-line px-4 py-3">
            <h2 className="text-[13px] font-bold">Alertes</h2>
          </div>
          <div className="flex flex-col gap-2 p-4">
            {data?.alerts.length === 0 && <p className="py-4 text-center text-xs text-ink-3">Aucune alerte — tout est sous contrôle ✓</p>}
            {data?.alerts.map((alert, index) => (
              <div
                key={index}
                className={`flex items-start gap-2.5 rounded-lg px-3 py-2.5 text-[13px] ${
                  alert.severity === "critical" ? "bg-crit-soft" : alert.severity === "warning" ? "bg-warn-soft" : "bg-sea-soft"
                }`}
              >
                <span>{alert.severity === "critical" ? "⛔" : "⚠"}</span>
                <div>
                  <div className="text-xs font-bold">{alert.title}</div>
                  <div className="mono text-[11px] text-ink-2">{alert.context}</div>
                </div>
              </div>
            ))}
          </div>
        </div>
      </div>

      {/* Dossiers récents */}
      <div className="rounded-xl border border-line bg-surface shadow-sm">
        <div className="flex items-center border-b border-line px-4 py-3">
          <h2 className="text-[13px] font-bold">Dossiers récents</h2>
          <Link href="/shipments" className="ml-auto text-xs font-semibold text-sea hover:underline">
            Tous les dossiers →
          </Link>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full border-collapse text-[13px]">
            <thead>
              <tr className="border-b border-line text-left text-[10px] uppercase tracking-wider text-ink-3">
                <th className="px-3 py-2">Référence</th>
                <th className="px-3 py-2">Client</th>
                <th className="px-3 py-2">Mode</th>
                <th className="px-3 py-2">Trajet</th>
                <th className="px-3 py-2">Statut</th>
                <th className="px-3 py-2">ETA</th>
                <th className="px-3 py-2">Agent</th>
              </tr>
            </thead>
            <tbody>
              {data?.recent_shipments.map((shipment) => (
                <tr key={shipment.id} className="border-b border-line last:border-0 hover:bg-sea/5">
                  <td className="mono px-3 py-2 font-semibold text-sea">{shipment.reference}</td>
                  <td className="px-3 py-2">{shipment.client_name}</td>
                  <td className="px-3 py-2 text-ink-2">{MODE_LABEL[shipment.mode] ?? shipment.mode}</td>
                  <td className="mono px-3 py-2 text-ink-2">{shipment.origin_locode} → {shipment.destination_locode}</td>
                  <td className="px-3 py-2">
                    {shipment.is_delayed ? (
                      <span className="inline-flex items-center gap-1.5 rounded-full bg-crit-soft px-2.5 py-0.5 text-[11px] font-semibold text-crit">
                        <span className="size-1.5 rounded-full bg-current" /> Retard
                      </span>
                    ) : (
                      <StatusPill status={shipment.status} />
                    )}
                  </td>
                  <td className="mono px-3 py-2">
                    {shipment.eta ? new Date(shipment.eta).toLocaleDateString("fr-FR", { day: "2-digit", month: "2-digit" }) : "—"}
                  </td>
                  <td className="px-3 py-2 text-ink-2">{shipment.agent_name}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
