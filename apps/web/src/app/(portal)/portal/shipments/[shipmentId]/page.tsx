"use client";

import { useQuery } from "@tanstack/react-query";
import Link from "next/link";
import { use } from "react";
import { rawApi } from "@/lib/api";
import { PortalShell } from "@/components/PortalShell";

interface PortalShipmentDetail {
  reference: string;
  status: string;
  origin_locode: string;
  destination_locode: string;
  eta: string | null;
  ata: string | null;
  containers: { number: string; seal_number: string | null }[];
  events: { id: string; title: string; type: string; occurred_at: string }[];
}

export default function PortalShipmentPage({ params }: { params: Promise<{ shipmentId: string }> }) {
  const { shipmentId } = use(params);
  const { data } = useQuery({
    queryKey: ["portal", "shipment", shipmentId],
    queryFn: async () => {
      const { data: response } = await rawApi.GET(`/v1/portal/shipments/${shipmentId}`);
      return response as PortalShipmentDetail;
    },
  });

  return (
    <PortalShell>
      {!data ? (
        <p className="text-sm text-ink-3">Chargement…</p>
      ) : (
        <div className="flex flex-col gap-5">
          <div>
            <Link href="/portal" className="text-[13px] text-sea hover:underline">← Mes expéditions</Link>
            <h1 className="mono text-xl font-bold">{data.reference}</h1>
            <p className="mono text-[13px] text-ink-3">{data.origin_locode} → {data.destination_locode}</p>
          </div>

          <div className="flex flex-wrap items-center gap-4 rounded-xl bg-sea-soft p-5">
            <div>
              <div className="text-base font-bold">Statut : {data.status}</div>
              {data.containers.length > 0 && (
                <div className="mono text-xs text-ink-2">
                  Conteneur(s) : {data.containers.map((container) => container.number).join(", ")}
                </div>
              )}
            </div>
            <div className="ml-auto text-right">
              <div className="text-[10px] uppercase tracking-wider text-ink-3">
                {data.ata ? "Arrivée le" : "Arrivée estimée"}
              </div>
              <div className="text-lg font-bold">
                {data.ata
                  ? new Date(data.ata).toLocaleDateString("fr-FR")
                  : data.eta
                    ? new Date(data.eta).toLocaleDateString("fr-FR")
                    : "—"}
              </div>
            </div>
          </div>

          <div className="rounded-xl border border-line bg-surface p-5 shadow-sm">
            <h2 className="pb-3 text-[13px] font-bold">Historique</h2>
            <ul>
              {data.events.map((event, index) => (
                <li key={event.id} className={`relative ml-1.5 pl-6 ${index < data.events.length - 1 ? "border-l-2 border-line pb-4" : ""}`}>
                  <span className="absolute -left-[7px] top-0.5 size-3 rounded-full border-[3px] border-sea bg-surface" />
                  <div className="text-[13px] font-semibold">{event.title}</div>
                  <div className="text-[11px] text-ink-3">
                    {new Date(event.occurred_at).toLocaleDateString("fr-FR", { day: "2-digit", month: "long", hour: "2-digit", minute: "2-digit" })}
                  </div>
                </li>
              ))}
            </ul>
          </div>
        </div>
      )}
    </PortalShell>
  );
}
