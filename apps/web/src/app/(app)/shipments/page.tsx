"use client";

import { useQuery } from "@tanstack/react-query";
import Link from "next/link";
import { useCan } from "@/stores/auth";
import { buttonPrimary } from "@/components/Field";
import { rawApi } from "@/lib/api";
import { StatusPill } from "@/components/StatusPill";

interface ListedShipment {
  id: string;
  reference: string;
  mode: string;
  direction: string;
  status: string;
  priority: string;
  client: { name: string };
  agent: { name: string };
  origin_locode: string;
  destination_locode: string;
  eta: string | null;
  is_delayed: boolean;
  missing_documents: number;
}

const MODE_LABEL: Record<string, string> = {
  sea_fcl: "🚢 FCL",
  sea_lcl: "🚢 LCL",
  air: "✈ Aérien",
  road: "🚛 Routier",
  multimodal: "⛓ Multimodal",
};

export default function ShipmentsPage() {
  const canCreate = useCan("shipments.create");
  const { data, isLoading } = useQuery({
    queryKey: ["shipments", "list"],
    queryFn: async () => {
      const { data: response } = await rawApi.GET("/v1/shipments", {
        params: { query: { per_page: 50, sort: "-eta" } },
      });
      return response as { data: ListedShipment[] };
    },
  });

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-start">
        <div>
          <h1 className="text-xl font-bold">Dossiers</h1>
          <p className="text-[13px] text-ink-3">{data?.data.length ?? "…"} dossiers ouverts</p>
        </div>
        {canCreate && (
          <Link href="/shipments/new" className={`ml-auto ${buttonPrimary}`}>
            + Nouveau dossier
          </Link>
        )}
      </div>
      <div className="overflow-x-auto rounded-xl border border-line bg-surface shadow-sm">
        <table className="w-full border-collapse text-[13px]">
          <thead>
            <tr className="border-b border-line text-left text-[10px] uppercase tracking-wider text-ink-3">
              <th className="px-3 py-2.5">Référence</th>
              <th className="px-3 py-2.5">Client</th>
              <th className="px-3 py-2.5">Mode</th>
              <th className="px-3 py-2.5">Trajet</th>
              <th className="px-3 py-2.5">Statut</th>
              <th className="px-3 py-2.5">ETA</th>
              <th className="px-3 py-2.5">Docs</th>
              <th className="px-3 py-2.5">Agent</th>
            </tr>
          </thead>
          <tbody>
            {isLoading && (
              <tr>
                <td colSpan={8} className="px-3 py-8 text-center text-ink-3">
                  Chargement…
                </td>
              </tr>
            )}
            {data?.data.map((shipment) => (
              <tr key={shipment.id} className="border-b border-line last:border-0 hover:bg-sea/5">
                <td className="mono px-3 py-2.5 font-semibold"><Link href={`/shipments/${shipment.id}`} className="text-sea hover:underline">{shipment.reference}</Link></td>
                <td className="px-3 py-2.5">{shipment.client.name}</td>
                <td className="px-3 py-2.5 text-ink-2">{MODE_LABEL[shipment.mode] ?? shipment.mode}</td>
                <td className="mono px-3 py-2.5 text-ink-2">
                  {shipment.origin_locode} → {shipment.destination_locode}
                </td>
                <td className="px-3 py-2.5">
                  {shipment.is_delayed ? <StatusPill status="delay" /> : <StatusPill status={shipment.status} />}
                </td>
                <td className="mono px-3 py-2.5">
                  {shipment.eta ? new Date(shipment.eta).toLocaleDateString("fr-FR", { day: "2-digit", month: "2-digit" }) : "—"}
                </td>
                <td className="px-3 py-2.5">
                  {shipment.missing_documents > 0 ? (
                    <span className="text-xs font-semibold text-warn">{shipment.missing_documents} manquant(s)</span>
                  ) : (
                    <span className="text-xs text-ok">✓</span>
                  )}
                </td>
                <td className="px-3 py-2.5 text-ink-2">{shipment.agent.name}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
