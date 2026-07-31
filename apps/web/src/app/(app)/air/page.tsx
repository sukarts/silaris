"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useState } from "react";
import { downloadFile, problemMessage, rawApi } from "@/lib/api";
import { Field, buttonPrimary, buttonSecondary, inputClass } from "@/components/Field";
import { PlaceCombobox } from "@/components/PlaceCombobox";
import { useCan } from "@/stores/auth";

interface Airline {
  id: string;
  awb_prefix: string;
  iata: string | null;
  name: string;
}

interface AwbLeg {
  id: string;
  flight_number: string;
  origin_iata: string;
  destination_iata: string;
  departure_at: string | null;
  arrival_at: string | null;
  position: number;
}

interface Awb {
  id: string;
  type: "master" | "house";
  number: string;
  status: string;
  gross_weight_kg: string | null;
  volume_m3: string | null;
  chargeable_weight_kg: string | null;
  packages_count: number | null;
  goods_description: string | null;
  issued_at: string | null;
  tracking_status: string | null;
  last_location_iata: string | null;
  last_tracked_at: string | null;
  legs: AwbLeg[];
  shipment: { id: string; reference: string } | null;
  airline: Airline | null;
}

const STATUS_LABEL: Record<string, string> = { draft: "Brouillon", issued: "Émise" };
const STATUS_TONE: Record<string, string> = {
  draft: "bg-paper text-ink-3",
  issued: "bg-ok-soft text-ok",
};

const TRACK_LABEL: Record<string, string> = {
  booked: "Réservée",
  en_route: "En vol",
  landed: "Arrivée",
  delivered: "Livrée",
  unknown: "Inconnu",
};
const TRACK_TONE: Record<string, string> = {
  booked: "bg-paper text-ink-3",
  en_route: "bg-sea/10 text-sea",
  landed: "bg-warn-soft text-warn",
  delivered: "bg-ok-soft text-ok",
  unknown: "bg-paper text-ink-3",
};

const emptyForm = {
  shipment_id: "",
  type: "master",
  number: "",
  airline_id: "",
  gross_weight_kg: "",
  volume_m3: "",
  packages_count: "",
  goods_description: "",
  flight_number: "",
  origin_iata: "",
  destination_iata: "",
};

export default function AirPage() {
  const queryClient = useQueryClient();
  const canCreate = useCan("awb.create");
  const canIssue = useCan("awb.issue");
  const canTrack = useCan("awb.update");
  const [typeFilter, setTypeFilter] = useState<string>("");
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState(emptyForm);
  const [error, setError] = useState<string | null>(null);

  const { data, isLoading } = useQuery({
    queryKey: ["awbs", typeFilter],
    queryFn: async () => {
      const { data: response } = await rawApi.GET("/v1/air-waybills", {
        params: { query: { ...(typeFilter ? { type: typeFilter } : {}) } },
      });
      return response as { data: Awb[] };
    },
  });

  const { data: airlines } = useQuery({
    queryKey: ["referentials", "airlines"],
    queryFn: async () => {
      const referential = "airlines";
      const { data: response } = await rawApi.GET(`/v1/referentials/${referential}`, {
        params: { query: { per_page: 100 } },
      });
      return (response as { data: Airline[] } | undefined)?.data ?? [];
    },
    staleTime: 60 * 60 * 1000,
  });

  const create = useMutation({
    mutationFn: async () => {
      const hasLeg = form.flight_number && form.origin_iata && form.destination_iata;
      const { error: problem } = await rawApi.POST("/v1/air-waybills", {
        body: {
          shipment_id: form.shipment_id,
          type: form.type,
          number: form.number,
          airline_id: form.airline_id || null,
          gross_weight_kg: form.gross_weight_kg ? Number(form.gross_weight_kg) : null,
          volume_m3: form.volume_m3 ? Number(form.volume_m3) : null,
          packages_count: form.packages_count ? Number(form.packages_count) : null,
          goods_description: form.goods_description || null,
          ...(hasLeg
            ? {
                legs: [
                  {
                    flight_number: form.flight_number,
                    origin_iata: form.origin_iata.toUpperCase(),
                    destination_iata: form.destination_iata.toUpperCase(),
                  },
                ],
              }
            : {}),
        },
      });
      if (problem) throw problem;
    },
    onSuccess: () => {
      setShowForm(false);
      setForm(emptyForm);
      setError(null);
      queryClient.invalidateQueries({ queryKey: ["awbs"] });
    },
    onError: (problem) => setError(problemMessage(problem)),
  });

  const issue = useMutation({
    mutationFn: async (awbId: string) => {
      const { error: problem } = await rawApi.POST(`/v1/air-waybills/${awbId}/issue`);
      if (problem) throw problem;
    },
    onSuccess: () => {
      setError(null);
      queryClient.invalidateQueries({ queryKey: ["awbs"] });
    },
    onError: (problem) => setError(problemMessage(problem)),
  });

  const track = useMutation({
    mutationFn: async (awbId: string) => {
      const { error: problem } = await rawApi.POST(`/v1/air-waybills/${awbId}/track`);
      if (problem) throw problem;
    },
    onSuccess: () => {
      setError(null);
      queryClient.invalidateQueries({ queryKey: ["awbs"] });
    },
    onError: (problem) => setError(problemMessage(problem)),
  });

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-start">
        <div>
          <h1 className="text-xl font-bold">Aérien</h1>
          <p className="text-[13px] text-ink-3">Lettres de transport aérien (LTA / AWB)</p>
        </div>
        {canCreate && (
          <button onClick={() => setShowForm((value) => !value)} className={`ml-auto ${buttonPrimary}`}>
            + Nouvelle LTA
          </button>
        )}
      </div>

      {showForm && (
        <form
          onSubmit={(event) => { event.preventDefault(); create.mutate(); }}
          className="grid gap-4 rounded-xl border border-line bg-surface p-5 shadow-sm md:grid-cols-6"
        >
          <Field label="Dossier (ID)" className="md:col-span-2">
            <input required value={form.shipment_id} onChange={(e) => setForm({ ...form, shipment_id: e.target.value })} className={`${inputClass} mono`} placeholder="UUID du dossier" />
          </Field>
          <Field label="Type">
            <select value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })} className={inputClass}>
              <option value="master">Master (MAWB)</option>
              <option value="house">House (HAWB)</option>
            </select>
          </Field>
          <Field label="N° AWB">
            <input required maxLength={16} value={form.number} onChange={(e) => setForm({ ...form, number: e.target.value })} className={`${inputClass} mono`} placeholder="057-12345675" />
          </Field>
          <Field label="Compagnie">
            <select value={form.airline_id} onChange={(e) => setForm({ ...form, airline_id: e.target.value })} className={inputClass}>
              <option value="">—</option>
              {(airlines ?? []).map((a) => (
                <option key={a.id} value={a.id}>{a.awb_prefix} · {a.name}</option>
              ))}
            </select>
          </Field>
          <Field label="Poids brut (kg)">
            <input type="number" min={0} step="0.01" value={form.gross_weight_kg} onChange={(e) => setForm({ ...form, gross_weight_kg: e.target.value })} className={inputClass} />
          </Field>
          <Field label="Colis">
            <input type="number" min={0} value={form.packages_count} onChange={(e) => setForm({ ...form, packages_count: e.target.value })} className={inputClass} />
          </Field>
          <Field label="Volume (m³)">
            <input type="number" min={0} step="0.001" value={form.volume_m3} onChange={(e) => setForm({ ...form, volume_m3: e.target.value })} className={inputClass} />
          </Field>
          <Field label="Vol">
            <input maxLength={8} value={form.flight_number} onChange={(e) => setForm({ ...form, flight_number: e.target.value.toUpperCase() })} className={`${inputClass} mono`} placeholder="AF718" />
          </Field>
          <Field label="Origine (IATA)">
            <PlaceCombobox referential="airports" maxLength={3} value={form.origin_iata} onChange={(v) => setForm({ ...form, origin_iata: v })} placeholder="Aéroport ou IATA (ex. CDG)" />
          </Field>
          <Field label="Destination (IATA)">
            <PlaceCombobox referential="airports" maxLength={3} value={form.destination_iata} onChange={(v) => setForm({ ...form, destination_iata: v })} placeholder="Aéroport ou IATA (ex. ABJ)" />
          </Field>
          <Field label="Description marchandise" className="md:col-span-2">
            <input value={form.goods_description} onChange={(e) => setForm({ ...form, goods_description: e.target.value })} className={inputClass} />
          </Field>
          {error && <p className="rounded-lg bg-crit-soft px-3 py-2 text-xs text-crit md:col-span-6">{error}</p>}
          <div className="flex gap-2 md:col-span-6">
            <button type="submit" disabled={create.isPending} className={buttonPrimary}>Créer</button>
            <button type="button" onClick={() => setShowForm(false)} className={buttonSecondary}>Annuler</button>
          </div>
        </form>
      )}

      {!showForm && error && <p className="rounded-lg bg-crit-soft px-4 py-2.5 text-[13px] text-crit">{error}</p>}

      <div className="flex flex-wrap gap-2">
        {["", "master", "house"].map((type) => (
          <button
            key={type}
            onClick={() => setTypeFilter(type)}
            className={`rounded-full border px-3.5 py-1 text-xs font-semibold ${
              typeFilter === type ? "border-ink bg-ink text-paper" : "border-line-strong text-ink-2 hover:bg-surface"
            }`}
          >
            {type === "" ? "Toutes" : type === "master" ? "Master" : "House"}
          </button>
        ))}
      </div>

      <div className="overflow-x-auto rounded-xl border border-line bg-surface shadow-sm">
        <table className="w-full text-[13px]">
          <thead>
            <tr className="border-b border-line text-left text-[10px] uppercase tracking-wider text-ink-3">
              <th className="px-3 py-2.5">N° AWB</th>
              <th className="px-3 py-2.5">Type</th>
              <th className="px-3 py-2.5">Vol</th>
              <th className="px-3 py-2.5">Trajet</th>
              <th className="px-3 py-2.5 text-right">Poids (kg)</th>
              <th className="px-3 py-2.5 text-right">Taxable (kg)</th>
              <th className="px-3 py-2.5 text-right">Colis</th>
              <th className="px-3 py-2.5">Dossier</th>
              <th className="px-3 py-2.5">Statut</th>
              <th className="px-3 py-2.5">Suivi</th>
              <th className="px-3 py-2.5" />
            </tr>
          </thead>
          <tbody>
            {isLoading && (
              <tr><td colSpan={11} className="px-3 py-8 text-center text-ink-3">Chargement…</td></tr>
            )}
            {data?.data.map((awb) => {
              const legs = awb.legs ?? [];
              const firstLeg = legs[0];
              const lastLeg = legs[legs.length - 1];
              return (
                <tr key={awb.id} className="border-b border-line last:border-0 hover:bg-sea/5">
                  <td className="mono px-3 py-2.5 font-semibold text-sea">
                    {awb.number}
                    {awb.airline && <span className="mono block text-[11px] font-normal text-ink-3">{awb.airline.name}</span>}
                  </td>
                  <td className="px-3 py-2.5 text-ink-2">{awb.type === "master" ? "Master" : "House"}</td>
                  <td className="mono px-3 py-2.5">{firstLeg?.flight_number ?? "—"}</td>
                  <td className="mono px-3 py-2.5">
                    {firstLeg ? `${firstLeg.origin_iata} → ${lastLeg?.destination_iata ?? "?"}` : "—"}
                  </td>
                  <td className="mono px-3 py-2.5 text-right">
                    {awb.gross_weight_kg != null ? Number(awb.gross_weight_kg).toLocaleString("fr-FR") : "—"}
                  </td>
                  <td className="mono px-3 py-2.5 text-right font-semibold">
                    {awb.chargeable_weight_kg != null ? Number(awb.chargeable_weight_kg).toLocaleString("fr-FR", { maximumFractionDigits: 1 }) : "—"}
                  </td>
                  <td className="mono px-3 py-2.5 text-right">{awb.packages_count ?? "—"}</td>
                  <td className="mono px-3 py-2.5 text-ink-2">{awb.shipment?.reference ?? "—"}</td>
                  <td className="px-3 py-2.5">
                    <span className={`rounded-full px-2.5 py-0.5 text-[11px] font-semibold ${STATUS_TONE[awb.status] ?? "bg-paper text-ink-3"}`}>
                      {STATUS_LABEL[awb.status] ?? awb.status}
                    </span>
                  </td>
                  <td className="px-3 py-2.5">
                    {awb.tracking_status ? (
                      <div>
                        <span className={`rounded-full px-2.5 py-0.5 text-[11px] font-semibold ${TRACK_TONE[awb.tracking_status] ?? "bg-paper text-ink-3"}`}>
                          {TRACK_LABEL[awb.tracking_status] ?? awb.tracking_status}
                        </span>
                        {awb.last_location_iata && <span className="mono ml-1.5 text-[11px] text-ink-3">{awb.last_location_iata}</span>}
                      </div>
                    ) : (
                      <span className="text-[11px] text-ink-3">—</span>
                    )}
                  </td>
                  <td className="px-3 py-2.5">
                    <div className="flex items-center justify-end gap-3">
                      {canTrack && (
                        <button
                          onClick={() => track.mutate(awb.id)}
                          disabled={track.isPending}
                          className="text-xs font-semibold text-sea hover:underline"
                        >
                          {track.isPending && track.variables === awb.id ? "…" : "Suivre"}
                        </button>
                      )}
                      <button
                        onClick={() => downloadFile(`/v1/air-waybills/${awb.id}/lta`, `lta-${awb.number}.pdf`).catch(() => setError("LTA indisponible."))}
                        className="text-xs font-semibold text-sea hover:underline"
                      >
                        LTA
                      </button>
                      {awb.status === "draft" && canIssue && (
                        <button
                          onClick={() => issue.mutate(awb.id)}
                          disabled={issue.isPending}
                          className="text-xs font-semibold text-sea hover:underline"
                        >
                          Émettre →
                        </button>
                      )}
                    </div>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </div>
  );
}
