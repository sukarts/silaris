"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import Link from "next/link";
import { use, useState } from "react";
import { problemMessage, rawApi } from "@/lib/api";
import { Field, buttonPrimary, inputClass } from "@/components/Field";
import { StatusPill } from "@/components/StatusPill";
import { WorkflowStepper } from "@/components/WorkflowStepper";
import { useCan } from "@/stores/auth";

interface ShipmentDetail {
  data: {
    id: string;
    reference: string;
    direction: string;
    mode: string;
    status: string;
    priority: string;
    incoterm_code: string;
    origin_locode: string;
    destination_locode: string;
    schedule: { etd: string | null; eta: string | null; atd: string | null; ata: string | null; eta_initial: string | null };
    client?: { id: string; code: string; name: string };
    agent?: { id: string; name: string };
    branch?: { id: string; code: string; name: string };
    cargo_items?: { id: string; description: string; packages_count: number | null; gross_weight_kg: string | null; volume_m3: string | null; hs_code: string | null }[];
    notes: string | null;
    closed_at: string | null;
  };
  workflow: {
    steps: { key: string; label: string; position: number }[];
    current: string;
    allowed_transitions: string[];
  };
  containers?: AssignedContainer[];
}

/** Conteneur affecté au dossier, avec l'état de son abonnement au suivi. */
interface AssignedContainer {
  id: string;
  number: string;
  size_type: string;
  seal_number: string | null;
  tracking_status: string | null;
  last_polled_at: string | null;
}

interface TimelineEvent {
  id: string;
  type: string;
  title: string;
  source: string;
  occurred_at: string;
}

const SOURCE_LABEL: Record<string, string> = {
  internal: "interne",
  carrier_api: "API compagnie",
  odoo: "Odoo",
  portal: "portail",
  system: "système",
};

function formatDate(value: string | null, withTime = false): string {
  if (!value) return "—";
  return new Date(value).toLocaleDateString("fr-FR", {
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
    ...(withTime ? { hour: "2-digit", minute: "2-digit" } : {}),
  });
}

export default function ShipmentDetailPage({ params }: { params: Promise<{ shipmentId: string }> }) {
  const { shipmentId } = use(params);
  const queryClient = useQueryClient();
  const canAdvance = useCan("shipments.advance");
  const canUpdate = useCan("shipments.update");
  const [error, setError] = useState<string | null>(null);
  const [refreshInfo, setRefreshInfo] = useState<string | null>(null);

  const refreshTracking = useMutation({
    mutationFn: async () => {
      const { data: response, error: problem } = await rawApi.POST(`/v1/shipments/${shipmentId}/tracking/refresh`);
      if (problem) throw problem;
      return response as { subscriptions: number; polled: number; new_events: number; errors: string[] };
    },
    onSuccess: (result) => {
      setRefreshInfo(
        result.subscriptions === 0
          ? "Aucun abonnement de tracking actif sur ce dossier."
          : `${result.new_events} nouvel(s) événement(s)${result.errors.length ? ` — ${result.errors.join(" ")}` : ""}`,
      );
      queryClient.invalidateQueries({ queryKey: ["shipment", shipmentId] });
      queryClient.invalidateQueries({ queryKey: ["timeline", shipmentId] });
    },
    onError: (problem) => setRefreshInfo(problemMessage(problem)),
  });

  const { data } = useQuery({
    queryKey: ["shipment", shipmentId],
    queryFn: async () => {
      const { data: response, error: problem } = await rawApi.GET(`/v1/shipments/${shipmentId}`);
      if (problem) throw problem;
      return response as ShipmentDetail;
    },
  });

  const { data: timeline } = useQuery({
    queryKey: ["shipment", shipmentId, "timeline"],
    queryFn: async () => {
      const { data: response } = await rawApi.GET(`/v1/shipments/${shipmentId}/timeline`);
      return (response as { data: TimelineEvent[] }).data;
    },
  });

  const advance = useMutation({
    mutationFn: async (nextStep: string) => {
      const { error: problem } = await rawApi.POST(`/v1/shipments/${shipmentId}/advance`, {
        body: { next_step: nextStep },
      });
      if (problem) throw problem;
    },
    onSuccess: () => {
      setError(null);
      queryClient.invalidateQueries({ queryKey: ["shipment", shipmentId] });
    },
    onError: (problem) => setError(problemMessage(problem)),
  });

  if (!data) return <p className="text-sm text-ink-3">Chargement…</p>;

  const shipment = data.data;
  const containers = data.containers ?? [];
  const stepLabel = (key: string) => data.workflow.steps.find((step) => step.key === key)?.label ?? key;

  return (
    <div className="flex flex-col gap-5">
      <div className="flex flex-wrap items-start gap-4">
        <div>
          <div className="text-[13px] text-ink-3">
            <Link href="/shipments" className="text-sea hover:underline">Dossiers</Link> / {shipment.reference}
          </div>
          <h1 className="mono flex items-center gap-3 text-xl font-bold">
            {shipment.reference}
            <StatusPill status={shipment.status} />
            {shipment.priority === "high" || shipment.priority === "critical" ? (
              <span className="size-2 rounded-sm bg-crit" title={`Priorité ${shipment.priority}`} />
            ) : null}
          </h1>
          <p className="text-[13px] text-ink-3">
            {shipment.client?.name} · {shipment.direction === "import" ? "Import" : "Export"} · {shipment.incoterm_code} ·{" "}
            <span className="mono">{shipment.origin_locode} → {shipment.destination_locode}</span>
          </p>
        </div>
        {canAdvance && !shipment.closed_at && data.workflow.allowed_transitions.length > 0 && (
          <div className="ml-auto flex gap-2">
            {data.workflow.allowed_transitions.map((transition) => (
              <button
                key={transition}
                onClick={() => advance.mutate(transition)}
                disabled={advance.isPending}
                className={buttonPrimary}
              >
                → {stepLabel(transition)}
              </button>
            ))}
          </div>
        )}
      </div>

      {error && <p className="rounded-lg bg-crit-soft px-4 py-2.5 text-[13px] text-crit">{error}</p>}

      <div className="rounded-xl border border-line bg-surface px-4 py-3 shadow-sm">
        <WorkflowStepper steps={data.workflow.steps} current={data.workflow.current} />
      </div>

      <div className="grid gap-3.5 xl:grid-cols-[1.5fr_1fr]">
        <div className="flex flex-col gap-3.5">
          <div className="rounded-xl border border-line bg-surface shadow-sm">
            <div className="border-b border-line px-4 py-3 text-[13px] font-bold">Informations</div>
            <div className="grid grid-cols-2 gap-x-5 gap-y-3 p-4 md:grid-cols-3">
              {[
                ["Client", shipment.client?.name],
                ["Agent", shipment.agent?.name],
                ["Agence", shipment.branch ? `${shipment.branch.name} (${shipment.branch.code})` : "—"],
                ["Incoterm", shipment.incoterm_code],
                ["ETD", formatDate(shipment.schedule.etd)],
                ["ETA", formatDate(shipment.schedule.eta)],
                ["ATD", formatDate(shipment.schedule.atd)],
                ["ATA", formatDate(shipment.schedule.ata)],
                ["ETA initiale", formatDate(shipment.schedule.eta_initial)],
              ].map(([label, value]) => (
                <div key={label as string}>
                  <div className="text-[10px] uppercase tracking-wider text-ink-3">{label}</div>
                  <div className="mt-0.5 text-[13px] font-semibold">{value ?? "—"}</div>
                </div>
              ))}
            </div>
          </div>

          {shipment.cargo_items && shipment.cargo_items.length > 0 && (
            <div className="rounded-xl border border-line bg-surface shadow-sm">
              <div className="border-b border-line px-4 py-3 text-[13px] font-bold">Marchandises</div>
              <table className="w-full text-[13px]">
                <thead>
                  <tr className="border-b border-line text-left text-[10px] uppercase tracking-wider text-ink-3">
                    <th className="px-4 py-2">Description</th>
                    <th className="px-4 py-2">Colis</th>
                    <th className="px-4 py-2">Poids (kg)</th>
                    <th className="px-4 py-2">Volume (m³)</th>
                    <th className="px-4 py-2">HS</th>
                  </tr>
                </thead>
                <tbody>
                  {shipment.cargo_items.map((item) => (
                    <tr key={item.id} className="border-b border-line last:border-0">
                      <td className="px-4 py-2">{item.description}</td>
                      <td className="mono px-4 py-2">{item.packages_count ?? "—"}</td>
                      <td className="mono px-4 py-2">{item.gross_weight_kg ?? "—"}</td>
                      <td className="mono px-4 py-2">{item.volume_m3 ?? "—"}</td>
                      <td className="mono px-4 py-2">{item.hs_code ?? "—"}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          <div className="rounded-xl border border-line bg-surface shadow-sm">
            <div className="flex items-center border-b border-line px-4 py-3">
              <span className="text-[13px] font-bold">Conteneurs</span>
              <Link href="/containers" className="ml-auto text-xs font-semibold text-sea hover:underline">
                Affecter un conteneur →
              </Link>
            </div>
            {containers.length === 0 ? (
              <p className="px-4 py-3 text-[13px] text-ink-3">
                Aucun conteneur affecté — le suivi transporteur démarre dès qu'un conteneur rejoint le dossier.
              </p>
            ) : (
              <table className="w-full text-[13px]">
                <thead>
                  <tr className="border-b border-line text-left text-[10px] uppercase tracking-wider text-ink-3">
                    <th className="px-4 py-2">Numéro</th>
                    <th className="px-4 py-2">Type</th>
                    <th className="px-4 py-2">Scellé</th>
                    <th className="px-4 py-2">Suivi</th>
                  </tr>
                </thead>
                <tbody>
                  {containers.map((container) => (
                    <tr key={container.id} className="border-b border-line last:border-0">
                      <td className="mono px-4 py-2 font-semibold">{container.number}</td>
                      <td className="px-4 py-2 text-ink-2">{container.size_type}</td>
                      <td className="mono px-4 py-2 text-ink-2">{container.seal_number ?? "—"}</td>
                      <td className="px-4 py-2">
                        {container.tracking_status === "active" ? (
                          <span className="rounded-full bg-ok-soft px-2 py-0.5 text-[11px] font-semibold text-ok">
                            Actif{container.last_polled_at ? ` · ${new Date(container.last_polled_at).toLocaleDateString("fr-FR")}` : " · jamais interrogé"}
                          </span>
                        ) : (
                          <span className="text-[11px] text-ink-3">Hors suivi</span>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>

          {shipment.notes && (
            <div className="rounded-xl border border-line bg-surface p-4 text-[13px] shadow-sm">
              <div className="pb-1 text-[10px] uppercase tracking-wider text-ink-3">Notes</div>
              {shipment.notes}
            </div>
          )}
        </div>

        <div className="rounded-xl border border-line bg-surface shadow-sm">
          <div className="flex items-center border-b border-line px-4 py-3">
            <span className="text-[13px] font-bold">Timeline</span>
            {canUpdate && (
              <button
                onClick={() => refreshTracking.mutate()}
                disabled={refreshTracking.isPending}
                title="Interroge immédiatement les compagnies (hors cadence quotidienne)"
                className="ml-auto text-xs font-semibold text-sea hover:underline disabled:opacity-50"
              >
                {refreshTracking.isPending ? "Actualisation…" : "Actualiser le suivi"}
              </button>
            )}
          </div>
          {refreshInfo && <p className="border-b border-line px-4 py-2 text-xs text-ink-3">{refreshInfo}</p>}
          <ul className="p-4">
            {timeline?.map((event, index) => (
              <li key={event.id} className={`relative pl-6 ${index < timeline.length - 1 ? "border-l-2 border-line pb-4" : ""} ml-1.5`}>
                <span
                  className={`absolute -left-[7px] top-0.5 size-3 rounded-full border-[3px] bg-surface ${
                    event.type === "tracking" ? "border-sea" : event.type === "status_change" ? "border-ok" : "border-line-strong"
                  }`}
                />
                <div className="text-[13px] font-semibold leading-tight">
                  {event.title}
                  <span className="ml-1.5 rounded border border-line bg-paper px-1.5 py-px text-[10px] font-normal text-ink-3">
                    {SOURCE_LABEL[event.source] ?? event.source}
                  </span>
                </div>
                <div className="text-[11px] text-ink-3">{formatDate(event.occurred_at, true)}</div>
              </li>
            ))}
            {timeline?.length === 0 && <p className="text-xs text-ink-3">Aucun événement.</p>}
          </ul>
        </div>
      </div>
    </div>
  );
}
