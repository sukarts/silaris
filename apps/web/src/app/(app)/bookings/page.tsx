"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useState } from "react";
import { problemMessage, rawApi } from "@/lib/api";
import { Field, buttonPrimary, buttonSecondary, inputClass } from "@/components/Field";
import { useCan } from "@/stores/auth";

interface Booking {
  id: string;
  status: string;
  booking_number: string | null;
  carrier_id: string;
  vgm_cutoff: string | null;
  doc_cutoff: string | null;
  port_cutoff: string | null;
  carrier?: { name?: string | null } | null;
  voyage?: {
    voyage_number?: string | null;
    etd?: string | null;
    eta?: string | null;
    pol_locode?: string | null;
    pod_locode?: string | null;
    vessel?: { name?: string | null } | null;
  } | null;
  shipment?: { id: string; reference: string } | null;
}

interface ShipmentOption {
  id: string;
  reference: string;
}

const STATUS_LABEL: Record<string, string> = {
  requested: "Demandé",
  confirmed: "Confirmé",
  rolled: "Rollé",
  cancelled: "Annulé",
};
const STATUS_TONE: Record<string, string> = {
  requested: "bg-warn-soft text-warn",
  confirmed: "bg-ok-soft text-ok",
  rolled: "bg-sea-soft text-sea",
  cancelled: "bg-crit-soft text-crit",
};

const emptyForm = { shipment_id: "", carrier_id: "", booking_number: "", vgm_cutoff: "", doc_cutoff: "", port_cutoff: "", notes: "" };

function formatDate(value: string | null | undefined) {
  return value ? new Date(value).toLocaleDateString("fr-FR", { day: "2-digit", month: "2-digit" }) : "—";
}

export default function BookingsPage() {
  const queryClient = useQueryClient();
  const canCreate = useCan("bookings.create");
  const canUpdate = useCan("bookings.update");
  const [statusFilter, setStatusFilter] = useState("");
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState(emptyForm);
  const [error, setError] = useState<string | null>(null);

  const { data, isLoading } = useQuery({
    queryKey: ["bookings", statusFilter],
    queryFn: async () => {
      const { data: response } = await rawApi.GET("/v1/bookings", {
        params: { query: { ...(statusFilter ? { status: statusFilter } : {}) } },
      });
      return response as { data: Booking[] };
    },
  });

  const { data: shipments } = useQuery({
    queryKey: ["shipments", "options"],
    queryFn: async () => {
      const { data: response } = await rawApi.GET("/v1/shipments", { params: { query: { per_page: 50 } } });
      return response as { data: ShipmentOption[] };
    },
    enabled: canCreate && showForm,
  });

  const create = useMutation({
    mutationFn: async () => {
      const { error: problem } = await rawApi.POST("/v1/bookings", {
        body: {
          shipment_id: form.shipment_id,
          carrier_id: form.carrier_id,
          booking_number: form.booking_number || null,
          vgm_cutoff: form.vgm_cutoff || null,
          doc_cutoff: form.doc_cutoff || null,
          port_cutoff: form.port_cutoff || null,
          notes: form.notes || null,
        },
      });
      if (problem) throw problem;
    },
    onSuccess: () => {
      setShowForm(false);
      setForm(emptyForm);
      setError(null);
      queryClient.invalidateQueries({ queryKey: ["bookings"] });
    },
    onError: (problem) => setError(problemMessage(problem)),
  });

  const confirm = useMutation({
    mutationFn: async ({ id, booking_number }: { id: string; booking_number: string }) => {
      const { error: problem } = await rawApi.POST(`/v1/bookings/${id}/confirm`, { body: { booking_number } });
      if (problem) throw problem;
    },
    onSuccess: () => {
      setError(null);
      queryClient.invalidateQueries({ queryKey: ["bookings"] });
    },
    onError: (problem) => setError(problemMessage(problem)),
  });

  const roll = useMutation({
    mutationFn: async (bookingId: string) => {
      const { error: problem } = await rawApi.POST(`/v1/bookings/${bookingId}/roll`, { body: {} });
      if (problem) throw problem;
    },
    onSuccess: () => {
      setError(null);
      queryClient.invalidateQueries({ queryKey: ["bookings"] });
    },
    onError: (problem) => setError(problemMessage(problem)),
  });

  const handleConfirm = (booking: Booking) => {
    const number = window.prompt("Numéro de booking :", booking.booking_number ?? "");
    if (number) confirm.mutate({ id: booking.id, booking_number: number });
  };

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-start">
        <div>
          <h1 className="text-xl font-bold">Bookings</h1>
          <p className="text-[13px] text-ink-3">Réservations maritimes auprès des compagnies</p>
        </div>
        {canCreate && (
          <button onClick={() => setShowForm((value) => !value)} className={`ml-auto ${buttonPrimary}`}>
            + Nouveau booking
          </button>
        )}
      </div>

      {showForm && (
        <form
          onSubmit={(event) => { event.preventDefault(); create.mutate(); }}
          className="grid gap-4 rounded-xl border border-line bg-surface p-5 shadow-sm md:grid-cols-6"
        >
          <Field label="Dossier" className="md:col-span-2">
            <select required value={form.shipment_id} onChange={(e) => setForm({ ...form, shipment_id: e.target.value })} className={inputClass}>
              <option value="">— Sélectionner —</option>
              {shipments?.data.map((shipment) => (
                <option key={shipment.id} value={shipment.id}>{shipment.reference}</option>
              ))}
            </select>
          </Field>
          <Field label="Compagnie (UUID)" className="md:col-span-2">
            <input required value={form.carrier_id} onChange={(e) => setForm({ ...form, carrier_id: e.target.value })} className={`${inputClass} mono`} />
          </Field>
          <Field label="N° booking" className="md:col-span-2">
            <input maxLength={32} value={form.booking_number} onChange={(e) => setForm({ ...form, booking_number: e.target.value.toUpperCase() })} className={`${inputClass} mono`} />
          </Field>
          <Field label="Cut-off VGM">
            <input type="date" value={form.vgm_cutoff} onChange={(e) => setForm({ ...form, vgm_cutoff: e.target.value })} className={inputClass} />
          </Field>
          <Field label="Cut-off docs">
            <input type="date" value={form.doc_cutoff} onChange={(e) => setForm({ ...form, doc_cutoff: e.target.value })} className={inputClass} />
          </Field>
          <Field label="Cut-off port">
            <input type="date" value={form.port_cutoff} onChange={(e) => setForm({ ...form, port_cutoff: e.target.value })} className={inputClass} />
          </Field>
          <Field label="Notes" className="md:col-span-3">
            <input maxLength={5000} value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} className={inputClass} />
          </Field>
          {error && <p className="rounded-lg bg-crit-soft px-3 py-2 text-xs text-crit md:col-span-6">{error}</p>}
          <div className="flex gap-2 md:col-span-6">
            <button type="submit" disabled={create.isPending} className={buttonPrimary}>Créer</button>
            <button type="button" onClick={() => setShowForm(false)} className={buttonSecondary}>Annuler</button>
          </div>
        </form>
      )}

      <div className="flex flex-wrap gap-2">
        {["", "requested", "confirmed", "rolled", "cancelled"].map((status) => (
          <button
            key={status}
            onClick={() => setStatusFilter(status)}
            className={`rounded-full border px-3.5 py-1 text-xs font-semibold ${
              statusFilter === status ? "border-ink bg-ink text-paper" : "border-line-strong text-ink-2 hover:bg-surface"
            }`}
          >
            {status === "" ? "Tous" : STATUS_LABEL[status]}
          </button>
        ))}
      </div>

      {error && !showForm && <p className="rounded-lg bg-crit-soft px-4 py-2.5 text-[13px] text-crit">{error}</p>}

      <div className="overflow-x-auto rounded-xl border border-line bg-surface shadow-sm">
        <table className="w-full text-[13px]">
          <thead>
            <tr className="border-b border-line text-left text-[10px] uppercase tracking-wider text-ink-3">
              <th className="px-3 py-2.5">N° booking</th>
              <th className="px-3 py-2.5">Compagnie</th>
              <th className="px-3 py-2.5">Navire / Voyage</th>
              <th className="px-3 py-2.5">Trajet</th>
              <th className="px-3 py-2.5">ETD</th>
              <th className="px-3 py-2.5">ETA</th>
              <th className="px-3 py-2.5">Statut</th>
              <th className="px-3 py-2.5">Dossier</th>
              <th className="px-3 py-2.5" />
            </tr>
          </thead>
          <tbody>
            {isLoading && (
              <tr><td colSpan={9} className="px-3 py-8 text-center text-ink-3">Chargement…</td></tr>
            )}
            {data?.data.map((booking) => (
              <tr key={booking.id} className="border-b border-line last:border-0 hover:bg-sea/5">
                <td className="mono px-3 py-2.5 font-semibold text-sea">{booking.booking_number ?? "(en attente)"}</td>
                <td className="px-3 py-2.5">{booking.carrier?.name ?? "—"}</td>
                <td className="px-3 py-2.5 text-ink-2">
                  {booking.voyage?.vessel?.name ?? "—"}
                  {booking.voyage?.voyage_number ? <span className="mono ml-1.5 text-ink-3">{booking.voyage.voyage_number}</span> : null}
                </td>
                <td className="mono px-3 py-2.5 text-ink-2">
                  {booking.voyage?.pol_locode && booking.voyage?.pod_locode
                    ? `${booking.voyage.pol_locode} → ${booking.voyage.pod_locode}`
                    : "—"}
                </td>
                <td className="mono px-3 py-2.5">{formatDate(booking.voyage?.etd)}</td>
                <td className="mono px-3 py-2.5">{formatDate(booking.voyage?.eta)}</td>
                <td className="px-3 py-2.5">
                  <span className={`rounded-full px-2.5 py-0.5 text-[11px] font-semibold ${STATUS_TONE[booking.status] ?? "bg-paper text-ink-3"}`}>
                    {STATUS_LABEL[booking.status] ?? booking.status}
                  </span>
                </td>
                <td className="mono px-3 py-2.5 text-ink-2">{booking.shipment?.reference ?? "—"}</td>
                <td className="px-3 py-2.5">
                  {canUpdate && (
                    <div className="flex justify-end gap-3">
                      {booking.status === "requested" && (
                        <button onClick={() => handleConfirm(booking)} disabled={confirm.isPending} className="text-xs font-semibold text-ok hover:underline">
                          Confirmer
                        </button>
                      )}
                      {(booking.status === "requested" || booking.status === "confirmed") && (
                        <button onClick={() => roll.mutate(booking.id)} disabled={roll.isPending} className="text-xs font-semibold text-warn hover:underline">
                          Rolling
                        </button>
                      )}
                    </div>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
