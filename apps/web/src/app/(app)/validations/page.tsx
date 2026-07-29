"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import Link from "next/link";
import { useState } from "react";
import { problemMessage, rawApi } from "@/lib/api";
import { buttonPrimary, buttonSecondary, inputClass } from "@/components/Field";
import { useCan } from "@/stores/auth";

interface StepRequest {
  id: string;
  shipment_id: string;
  reference: string;
  client_name: string | null;
  from_step: string;
  to_step: string;
  requested_by: string;
  requested_at: string;
}

interface WaiverRequest {
  id: string;
  reference: string;
  client_name: string | null;
  direction: string;
  mode: string;
  origin_locode: string;
  destination_locode: string;
  quote_waiver_reason: string;
  quote_waiver_requested_at: string;
  requested_by: string;
}

const STEP_LABEL: Record<string, string> = {
  creation: "Création", booking: "Booking", departure: "Départ", transit: "Transit",
  arrival: "Arrivée", customs: "Dédouanement", delivery: "Livraison", closure: "Clôture",
};

const DIRECTION_LABEL: Record<string, string> = { import: "Import", export: "Export", transit: "Transit" };

function since(date: string): string {
  const days = Math.floor((Date.now() - new Date(date).getTime()) / 86_400_000);
  if (days === 0) return "aujourd'hui";
  if (days === 1) return "hier";

  return `il y a ${days} jours`;
}

/** Motif de refus — exigé pour que le demandeur sache quoi corriger. */
function RejectionPrompt({ onCancel, onConfirm, pending }: {
  onCancel: () => void;
  onConfirm: (note: string) => void;
  pending: boolean;
}) {
  const [note, setNote] = useState("");

  return (
    <form
      onSubmit={(event) => { event.preventDefault(); onConfirm(note); }}
      className="mt-2 flex flex-wrap items-center gap-2"
    >
      <input
        required
        autoFocus
        value={note}
        onChange={(event) => setNote(event.target.value)}
        placeholder="Motif du refus — le demandeur doit savoir quoi corriger"
        className={`${inputClass} min-w-[18rem] flex-1`}
      />
      <button type="submit" disabled={pending} className="rounded-lg bg-crit px-3 py-2 text-xs font-semibold text-white disabled:opacity-50">
        Confirmer le refus
      </button>
      <button type="button" onClick={onCancel} className={buttonSecondary}>Annuler</button>
    </form>
  );
}

function StepRequestsQueue() {
  const queryClient = useQueryClient();
  const [rejecting, setRejecting] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const { data, isLoading } = useQuery({
    queryKey: ["step-requests"],
    queryFn: async () => {
      const { data: response } = await rawApi.GET("/v1/shipments/step-requests");
      return (response as { data: StepRequest[] }).data;
    },
  });

  const decide = useMutation({
    mutationFn: async ({ id, decision, note }: { id: string; decision: string; note?: string }) => {
      const { error: problem } = await rawApi.POST(`/v1/shipments/step-requests/${id}/decide`, {
        body: { decision, ...(note ? { note } : {}) },
      });
      if (problem) throw problem;
    },
    onSuccess: () => {
      setRejecting(null);
      setError(null);
      queryClient.invalidateQueries({ queryKey: ["step-requests"] });
    },
    onError: (problem) => setError(problemMessage(problem)),
  });

  const rows = data ?? [];

  return (
    <div className="flex flex-col gap-3">
      {error && <p className="rounded-lg bg-crit-soft px-4 py-2.5 text-[13px] text-crit">{error}</p>}
      {isLoading && <p className="py-8 text-center text-[13px] text-ink-3">Chargement…</p>}
      {!isLoading && rows.length === 0 && (
        <p className="rounded-xl border border-line bg-surface py-10 text-center text-[13px] text-ink-3 shadow-sm">
          Aucun passage d&apos;étape en attente.
        </p>
      )}

      {rows.map((row) => (
        <div key={row.id} className="rounded-xl border border-line bg-surface p-4 shadow-sm">
          <div className="flex flex-wrap items-center gap-3">
            <Link href={`/shipments/${row.shipment_id}`} className="mono text-[13px] font-bold text-sea hover:underline">
              {row.reference}
            </Link>
            <span className="text-[13px] text-ink-2">{row.client_name ?? "—"}</span>
            <span className="rounded-full bg-paper px-2.5 py-0.5 text-[11px] font-semibold text-ink-2">
              {STEP_LABEL[row.from_step] ?? row.from_step} → {STEP_LABEL[row.to_step] ?? row.to_step}
            </span>
            <span className="text-[11px] text-ink-3">
              proposé par {row.requested_by}, {since(row.requested_at)}
            </span>

            {rejecting !== row.id && (
              <div className="ml-auto flex gap-2">
                <button
                  onClick={() => decide.mutate({ id: row.id, decision: "approved" })}
                  disabled={decide.isPending}
                  className={buttonPrimary}
                >
                  Valider
                </button>
                <button onClick={() => setRejecting(row.id)} className={buttonSecondary}>Refuser</button>
              </div>
            )}
          </div>

          {rejecting === row.id && (
            <RejectionPrompt
              pending={decide.isPending}
              onCancel={() => setRejecting(null)}
              onConfirm={(note) => decide.mutate({ id: row.id, decision: "rejected", note })}
            />
          )}
        </div>
      ))}
    </div>
  );
}

function WaiversQueue() {
  const queryClient = useQueryClient();
  const [rejecting, setRejecting] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const { data, isLoading } = useQuery({
    queryKey: ["waivers"],
    queryFn: async () => {
      const { data: response } = await rawApi.GET("/v1/shipments/waivers");
      return (response as { data: WaiverRequest[] }).data;
    },
  });

  const decide = useMutation({
    mutationFn: async ({ id, decision, note }: { id: string; decision: string; note?: string }) => {
      const { error: problem } = await rawApi.POST(`/v1/shipments/${id}/waiver/decide`, {
        body: { decision, ...(note ? { note } : {}) },
      });
      if (problem) throw problem;
    },
    onSuccess: () => {
      setRejecting(null);
      setError(null);
      queryClient.invalidateQueries({ queryKey: ["waivers"] });
    },
    onError: (problem) => setError(problemMessage(problem)),
  });

  const rows = data ?? [];

  return (
    <div className="flex flex-col gap-3">
      {error && <p className="rounded-lg bg-crit-soft px-4 py-2.5 text-[13px] text-crit">{error}</p>}
      {isLoading && <p className="py-8 text-center text-[13px] text-ink-3">Chargement…</p>}
      {!isLoading && rows.length === 0 && (
        <p className="rounded-xl border border-line bg-surface py-10 text-center text-[13px] text-ink-3 shadow-sm">
          Aucune ouverture sans cotation en attente.
        </p>
      )}

      {rows.map((row) => (
        <div key={row.id} className="rounded-xl border border-line bg-surface p-4 shadow-sm">
          <div className="flex flex-wrap items-center gap-3">
            <Link href={`/shipments/${row.id}`} className="mono text-[13px] font-bold text-sea hover:underline">
              {row.reference}
            </Link>
            <span className="text-[13px] text-ink-2">{row.client_name ?? "—"}</span>
            <span className="mono text-[11px] text-ink-3">
              {DIRECTION_LABEL[row.direction] ?? row.direction} · {row.origin_locode} → {row.destination_locode}
            </span>
            <span className="text-[11px] text-ink-3">
              demandé par {row.requested_by}, {since(row.quote_waiver_requested_at)}
            </span>

            {rejecting !== row.id && (
              <div className="ml-auto flex gap-2">
                <button
                  onClick={() => decide.mutate({ id: row.id, decision: "approved" })}
                  disabled={decide.isPending}
                  className={buttonPrimary}
                >
                  Autoriser
                </button>
                <button onClick={() => setRejecting(row.id)} className={buttonSecondary}>Refuser</button>
              </div>
            )}
          </div>

          {/* Le motif invoqué est ce sur quoi la direction se prononce. */}
          <p className="mt-2 rounded-lg bg-paper px-3 py-2 text-[12px] text-ink-2">
            {row.quote_waiver_reason}
          </p>

          {rejecting === row.id && (
            <RejectionPrompt
              pending={decide.isPending}
              onCancel={() => setRejecting(null)}
              onConfirm={(note) => decide.mutate({ id: row.id, decision: "rejected", note })}
            />
          )}
        </div>
      ))}
    </div>
  );
}

export default function ValidationsPage() {
  const canApproveStep = useCan("shipments.approve_step");
  const canApproveWaiver = useCan("derogations.open_shipment_without_quote");
  const [tab, setTab] = useState<"steps" | "waivers">(canApproveStep ? "steps" : "waivers");

  const tabs = [
    { id: "steps" as const, label: "Passages d'étape", allowed: canApproveStep },
    { id: "waivers" as const, label: "Ouvertures sans cotation", allowed: canApproveWaiver },
  ].filter((entry) => entry.allowed);

  return (
    <div className="flex flex-col gap-4">
      <div>
        <h1 className="text-xl font-bold">Validations</h1>
        <p className="text-[13px] text-ink-3">Ce qui attend votre décision — un dossier n&apos;avance pas sans elle.</p>
      </div>

      {tabs.length > 1 && (
        <div className="flex gap-2">
          {tabs.map((entry) => (
            <button
              key={entry.id}
              onClick={() => setTab(entry.id)}
              className={`rounded-full border px-3.5 py-1 text-xs font-semibold ${
                tab === entry.id ? "border-ink bg-ink text-paper" : "border-line-strong text-ink-2 hover:bg-surface"
              }`}
            >
              {entry.label}
            </button>
          ))}
        </div>
      )}

      {tab === "steps" && canApproveStep && <StepRequestsQueue />}
      {tab === "waivers" && canApproveWaiver && <WaiversQueue />}
    </div>
  );
}
