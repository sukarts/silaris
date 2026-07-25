"use client";

import { useQuery } from "@tanstack/react-query";
import Link from "next/link";
import { rawApi } from "@/lib/api";
import { PortalShell } from "@/components/PortalShell";

interface PortalShipment {
  id: string;
  reference: string;
  mode: string;
  status: string;
  origin_locode: string;
  destination_locode: string;
  eta: string | null;
  ata: string | null;
  is_delayed: boolean;
  closed_at: string | null;
}

const STATUS_LABEL: Record<string, string> = {
  creation: "En préparation",
  booking: "En préparation",
  departure: "Départ",
  transit: "En transit",
  arrival: "Arrivée",
  customs: "Dédouanement",
  delivery: "En livraison",
  closure: "Terminé",
};

const MODE_LABEL: Record<string, string> = { sea_fcl: "🚢", sea_lcl: "🚢", air: "✈", road: "🚛", multimodal: "⛓" };

export default function PortalHomePage() {
  const { data } = useQuery({
    queryKey: ["portal", "shipments"],
    queryFn: async () => {
      const { data: response } = await rawApi.GET("/v1/portal/shipments");
      return (response as { data: PortalShipment[] }).data;
    },
  });

  const active = data?.filter((shipment) => !shipment.closed_at) ?? [];

  return (
    <PortalShell>
      <div className="flex flex-col gap-5">
        <div className="rounded-xl bg-gradient-to-r from-sea to-ink p-6 text-white">
          <h1 className="text-lg font-bold">Bienvenue</h1>
          <p className="text-[13px] opacity-85">{active.length} expédition(s) en cours</p>
        </div>

        <div className="rounded-xl border border-line bg-surface shadow-sm">
          <div className="border-b border-line px-4 py-3 text-[13px] font-bold">Mes expéditions</div>
          <div className="overflow-x-auto">
            <table className="w-full text-[13px]">
              <thead>
                <tr className="border-b border-line text-left text-[10px] uppercase tracking-wider text-ink-3">
                  <th className="px-3 py-2.5">Référence</th>
                  <th className="px-3 py-2.5">Trajet</th>
                  <th className="px-3 py-2.5">Statut</th>
                  <th className="px-3 py-2.5">Arrivée estimée</th>
                  <th className="px-3 py-2.5" />
                </tr>
              </thead>
              <tbody>
                {data?.map((shipment) => (
                  <tr key={shipment.id} className="border-b border-line last:border-0 hover:bg-sea/5">
                    <td className="mono px-3 py-2.5 font-semibold text-sea">
                      {MODE_LABEL[shipment.mode]} {shipment.reference}
                    </td>
                    <td className="mono px-3 py-2.5 text-ink-2">{shipment.origin_locode} → {shipment.destination_locode}</td>
                    <td className="px-3 py-2.5">
                      {shipment.is_delayed ? (
                        <span className="rounded-full bg-warn-soft px-2.5 py-0.5 text-[11px] font-semibold text-warn">Retard signalé</span>
                      ) : (
                        <span className="rounded-full bg-sea-soft px-2.5 py-0.5 text-[11px] font-semibold text-sea">
                          {STATUS_LABEL[shipment.status] ?? shipment.status}
                        </span>
                      )}
                    </td>
                    <td className="mono px-3 py-2.5">
                      {shipment.ata
                        ? `${new Date(shipment.ata).toLocaleDateString("fr-FR")} ✓`
                        : shipment.eta
                          ? new Date(shipment.eta).toLocaleDateString("fr-FR")
                          : "—"}
                    </td>
                    <td className="px-3 py-2.5">
                      <Link href={`/portal/shipments/${shipment.id}`} className="text-xs font-semibold text-sea hover:underline">
                        Suivre →
                      </Link>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </PortalShell>
  );
}
