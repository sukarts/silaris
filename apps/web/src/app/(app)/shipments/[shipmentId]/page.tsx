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
  size_type: string | null;
  seal_number: string | null;
  tracking_status: string | null;
  last_polled_at: string | null;
  last_snapshot: string | null;
}

/**
 * Relevé transporteur. Les agrégateurs ne renvoient pas d'historique mais une
 * photo du voyage : c'est elle qui porte le navire, les escales et l'ETA.
 */
interface CarrierSnapshot {
  container_status?: string;
  last_location?: string;
  next_location?: string;
  current_vessel_name?: string;
  current_voyage_number?: string;
  loading_port?: string;
  discharging_port?: string;
  eta_next_destination?: string;
  eta_final_destination?: string;
}

function parseSnapshot(raw: string | null): CarrierSnapshot | null {
  if (!raw) return null;
  try {
    return JSON.parse(raw) as CarrierSnapshot;
  } catch {
    return null;
  }
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
  // À l'import, le dossier démarre souvent avec le seul connaissement : la
  // compagnie en déduit les conteneurs.
  const [watch, setWatch] = useState({ number: "", subject_type: "bl", carrier_scac: "" });
  const [watchInfo, setWatchInfo] = useState<string | null>(null);

  const refreshTracking = useMutation({
    mutationFn: async () => {
      const { data: response, error: problem } = await rawApi.POST(`/v1/shipments/${shipmentId}/tracking/refresh`);
      if (problem) throw problem;
      return response as { subscriptions: number; pending_carrier: number; polled: number; new_events: number; errors: string[] };
    },
    onSuccess: (result) => {
      const suffix = result.errors.length > 0 ? ` — ${result.errors.join(" ")}` : "";
      setRefreshInfo(
        result.subscriptions === 0 && result.pending_carrier === 0
          ? "Aucun conteneur ni connaissement suivi sur ce dossier."
          : result.subscriptions === 0
            ? `En attente de la compagnie${suffix}`
            : `${result.new_events} nouvel(s) événement(s)${suffix}`,
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

  const { data: carriers } = useQuery({
    queryKey: ["carriers"],
    queryFn: async () => {
      const { data } = await rawApi.GET("/v1/referentials/carriers", { params: { query: { per_page: 50 } } });
      return (data as { data: { scac: string; name: string }[] }).data;
    },
  });

  const subscribe = useMutation({
    mutationFn: async () => {
      const { data: response, error: problem } = await rawApi.POST(`/v1/shipments/${shipmentId}/tracking/subscribe`, {
        body: { ...watch, carrier_scac: watch.carrier_scac || null },
      });
      if (problem) throw problem;
      return response as {
        carrier_known: boolean; new_events?: number;
        containers?: string[]; containers_busy?: string[]; message?: string;
      };
    },
    onSuccess: (result) => {
      const found = result.containers?.length
        ? ` — ${result.containers.length} conteneur(s) rattaché(s) : ${result.containers.join(", ")}`
        : "";
      const busy = result.containers_busy?.length
        ? ` — ${result.containers_busy.join(", ")} déjà affecté(s) à un autre dossier ouvert.`
        : "";
      setWatchInfo(result.message ?? `${result.new_events ?? 0} événement(s) reçu(s)${found}${busy}`);
      setWatch({ number: "", subject_type: watch.subject_type, carrier_scac: watch.carrier_scac });
      queryClient.invalidateQueries({ queryKey: ["shipment", shipmentId] });
    },
    onError: (problem) => setWatchInfo(problemMessage(problem)),
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

            {canUpdate && (
              <form
                onSubmit={(event) => { event.preventDefault(); subscribe.mutate(); }}
                className="flex flex-wrap items-end gap-2 border-b border-line px-4 py-3"
              >
                <div className="min-w-[9rem] flex-1">
                  <label className="text-[10px] uppercase tracking-wider text-ink-3">Numéro à suivre</label>
                  <input
                    required
                    value={watch.number}
                    onChange={(e) => setWatch({ ...watch, number: e.target.value.toUpperCase() })}
                    placeholder="BL ou conteneur"
                    className={`${inputClass} mono w-full`}
                  />
                </div>
                <select value={watch.subject_type} onChange={(e) => setWatch({ ...watch, subject_type: e.target.value })} className={inputClass}>
                  <option value="bl">Connaissement</option>
                  <option value="container">Conteneur</option>
                </select>
                <select value={watch.carrier_scac} onChange={(e) => setWatch({ ...watch, carrier_scac: e.target.value })} className={inputClass}>
                  <option value="">Compagnie…</option>
                  {carriers?.map((carrier) => <option key={carrier.scac} value={carrier.scac}>{carrier.name}</option>)}
                </select>
                <button type="submit" disabled={subscribe.isPending} className="rounded-lg border border-line-strong px-3 py-2 text-xs font-semibold text-ink-2 hover:bg-paper disabled:opacity-50">
                  {subscribe.isPending ? "Interrogation…" : "Suivre"}
                </button>
                {watchInfo && <p className="w-full text-xs text-ink-3">{watchInfo}</p>}
              </form>
            )}
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
                  {containers.map((container) => {
                    const snapshot = parseSnapshot(container.last_snapshot);
                    return (
                    <tr key={container.id} className="border-b border-line last:border-0 align-top">
                      <td className="px-4 py-2">
                        <span className="mono font-semibold">{container.number}</span>
                        {snapshot && (
                          <span className="mt-0.5 block text-[11px] font-normal text-ink-3">
                            {[
                              snapshot.container_status,
                              snapshot.current_vessel_name && `${snapshot.current_vessel_name}${snapshot.current_voyage_number ? ` ${snapshot.current_voyage_number}` : ""}`,
                              snapshot.last_location && snapshot.next_location
                                ? `${snapshot.last_location} → ${snapshot.next_location}`
                                : snapshot.last_location,
                              snapshot.eta_final_destination && `ETA ${new Date(snapshot.eta_final_destination).toLocaleDateString("fr-FR")}`,
                            ].filter(Boolean).join(" · ")}
                          </span>
                        )}
                      </td>
                      <td className="px-4 py-2 text-ink-2">{container.size_type ?? "—"}</td>
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
                    );
                  })}
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
