"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Fragment, useState } from "react";
import { problemMessage, rawApi } from "@/lib/api";
import { Field, buttonPrimary, buttonSecondary, inputClass } from "@/components/Field";
import { useCan } from "@/stores/auth";

interface Container {
  id: string;
  number: string;
  size_type: string;
  status?: string | null;
  tare_kg?: string | number | null;
  max_payload_kg?: string | number | null;
  active_assignment: number;
}

interface ShipmentOption {
  id: string;
  reference: string;
}

const SIZE_TYPES = ["20GP", "40GP", "40HC", "45HC", "20RF", "40RF", "20OT", "40OT", "20FR", "40FR", "20TK"];

const STATUS_LABEL: Record<string, string> = {
  available: "Disponible",
  assigned: "Affecté",
  in_transit: "En transit",
  returned: "Restitué",
};
const STATUS_TONE: Record<string, string> = {
  available: "bg-ok-soft text-ok",
  assigned: "bg-sea-soft text-sea",
  in_transit: "bg-warn-soft text-warn",
  returned: "bg-paper text-ink-3",
};

const emptyForm = { number: "", size_type: "20GP", tare_kg: "", max_payload_kg: "" };
const emptyAssign = { shipment_id: "", seal_number: "", vgm_kg: "", free_time_days: "" };

export default function ContainersPage() {
  const queryClient = useQueryClient();
  const canCreate = useCan("containers.create");
  const canUpdate = useCan("containers.update");
  const [search, setSearch] = useState("");
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState(emptyForm);
  const [assignFor, setAssignFor] = useState<string | null>(null);
  const [assignForm, setAssignForm] = useState(emptyAssign);
  const [error, setError] = useState<string | null>(null);

  const { data, isLoading } = useQuery({
    queryKey: ["containers", search],
    queryFn: async () => {
      const { data: response } = await rawApi.GET("/v1/containers", {
        params: { query: { ...(search ? { search } : {}) } },
      });
      return response as { data: Container[] };
    },
  });

  const { data: shipments } = useQuery({
    queryKey: ["shipments", "options"],
    queryFn: async () => {
      const { data: response } = await rawApi.GET("/v1/shipments", { params: { query: { per_page: 50 } } });
      return response as { data: ShipmentOption[] };
    },
    enabled: canUpdate && assignFor !== null,
  });

  const create = useMutation({
    mutationFn: async () => {
      const { error: problem } = await rawApi.POST("/v1/containers", {
        body: {
          number: form.number,
          size_type: form.size_type,
          tare_kg: form.tare_kg ? Number(form.tare_kg) : null,
          max_payload_kg: form.max_payload_kg ? Number(form.max_payload_kg) : null,
        },
      });
      if (problem) throw problem;
    },
    onSuccess: () => {
      setShowForm(false);
      setForm(emptyForm);
      setError(null);
      queryClient.invalidateQueries({ queryKey: ["containers"] });
    },
    onError: (problem) => setError(problemMessage(problem)),
  });

  const assign = useMutation({
    mutationFn: async (containerId: string) => {
      const { error: problem } = await rawApi.POST(`/v1/containers/${containerId}/assign`, {
        body: {
          shipment_id: assignForm.shipment_id,
          seal_number: assignForm.seal_number || null,
          vgm_kg: assignForm.vgm_kg ? Number(assignForm.vgm_kg) : null,
          free_time_days: assignForm.free_time_days ? Number(assignForm.free_time_days) : null,
        },
      });
      if (problem) throw problem;
    },
    onSuccess: () => {
      setAssignFor(null);
      setAssignForm(emptyAssign);
      setError(null);
      queryClient.invalidateQueries({ queryKey: ["containers"] });
    },
    onError: (problem) => setError(problemMessage(problem)),
  });

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-start">
        <div>
          <h1 className="text-xl font-bold">Conteneurs</h1>
          <p className="text-[13px] text-ink-3">Parc conteneurs et affectations aux dossiers</p>
        </div>
        {canCreate && (
          <button onClick={() => setShowForm((value) => !value)} className={`ml-auto ${buttonPrimary}`}>
            + Nouveau conteneur
          </button>
        )}
      </div>

      {showForm && (
        <form
          onSubmit={(event) => { event.preventDefault(); create.mutate(); }}
          className="grid gap-4 rounded-xl border border-line bg-surface p-5 shadow-sm md:grid-cols-4"
        >
          <Field label="N° conteneur (ISO 6346)">
            <input
              required
              maxLength={11}
              pattern="[A-Z]{4}[0-9]{7}"
              placeholder="MSKU1234567"
              value={form.number}
              onChange={(e) => setForm({ ...form, number: e.target.value.toUpperCase() })}
              className={`${inputClass} mono`}
            />
          </Field>
          <Field label="Type / taille">
            <select value={form.size_type} onChange={(e) => setForm({ ...form, size_type: e.target.value })} className={inputClass}>
              {SIZE_TYPES.map((sizeType) => (
                <option key={sizeType} value={sizeType}>{sizeType}</option>
              ))}
            </select>
          </Field>
          <Field label="Tare (kg)">
            <input type="number" min={0} value={form.tare_kg} onChange={(e) => setForm({ ...form, tare_kg: e.target.value })} className={inputClass} />
          </Field>
          <Field label="Charge utile max (kg)">
            <input type="number" min={0} value={form.max_payload_kg} onChange={(e) => setForm({ ...form, max_payload_kg: e.target.value })} className={inputClass} />
          </Field>
          {error && <p className="rounded-lg bg-crit-soft px-3 py-2 text-xs text-crit md:col-span-4">{error}</p>}
          <div className="flex gap-2 md:col-span-4">
            <button type="submit" disabled={create.isPending} className={buttonPrimary}>Créer</button>
            <button type="button" onClick={() => setShowForm(false)} className={buttonSecondary}>Annuler</button>
          </div>
        </form>
      )}

      <div className="flex flex-wrap gap-2">
        <input
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="Rechercher un n° de conteneur…"
          className="ml-auto w-64 rounded-lg border border-line bg-surface px-3 py-1.5 text-[13px] focus:outline-2 focus:outline-accent"
        />
      </div>

      {error && !showForm && assignFor === null && (
        <p className="rounded-lg bg-crit-soft px-4 py-2.5 text-[13px] text-crit">{error}</p>
      )}

      <div className="overflow-x-auto rounded-xl border border-line bg-surface shadow-sm">
        <table className="w-full text-[13px]">
          <thead>
            <tr className="border-b border-line text-left text-[10px] uppercase tracking-wider text-ink-3">
              <th className="px-3 py-2.5">Numéro</th>
              <th className="px-3 py-2.5">Type / taille</th>
              <th className="px-3 py-2.5">Statut</th>
              <th className="px-3 py-2.5 text-right">Tare (kg)</th>
              <th className="px-3 py-2.5 text-right">Charge max (kg)</th>
              <th className="px-3 py-2.5">Affectation</th>
              <th className="px-3 py-2.5" />
            </tr>
          </thead>
          <tbody>
            {isLoading && (
              <tr><td colSpan={7} className="px-3 py-8 text-center text-ink-3">Chargement…</td></tr>
            )}
            {data?.data.map((container) => (
              <Fragment key={container.id}>
                <tr className="border-b border-line last:border-0 hover:bg-sea/5">
                  <td className="mono px-3 py-2.5 font-semibold text-sea">{container.number}</td>
                  <td className="mono px-3 py-2.5">{container.size_type}</td>
                  <td className="px-3 py-2.5">
                    {container.status ? (
                      <span className={`rounded-full px-2.5 py-0.5 text-[11px] font-semibold ${STATUS_TONE[container.status] ?? "bg-paper text-ink-3"}`}>
                        {STATUS_LABEL[container.status] ?? container.status}
                      </span>
                    ) : container.active_assignment > 0 ? (
                      <span className="rounded-full bg-sea-soft px-2.5 py-0.5 text-[11px] font-semibold text-sea">Affecté</span>
                    ) : (
                      <span className="rounded-full bg-ok-soft px-2.5 py-0.5 text-[11px] font-semibold text-ok">Disponible</span>
                    )}
                  </td>
                  <td className="mono px-3 py-2.5 text-right text-ink-2">{container.tare_kg ? Number(container.tare_kg).toLocaleString("fr-FR") : "—"}</td>
                  <td className="mono px-3 py-2.5 text-right text-ink-2">{container.max_payload_kg ? Number(container.max_payload_kg).toLocaleString("fr-FR") : "—"}</td>
                  <td className="px-3 py-2.5 text-ink-2">
                    {container.active_assignment > 0 ? `${container.active_assignment} en cours` : "—"}
                  </td>
                  <td className="px-3 py-2.5">
                    {canUpdate && (
                      <button
                        onClick={() => {
                          setAssignFor((current) => (current === container.id ? null : container.id));
                          setAssignForm(emptyAssign);
                        }}
                        className="text-xs font-semibold text-sea hover:underline"
                      >
                        Affecter à un dossier →
                      </button>
                    )}
                  </td>
                </tr>
                {assignFor === container.id && (
                  <tr className="border-b border-line last:border-0">
                    <td colSpan={7} className="px-3 py-3">
                      <form
                        onSubmit={(event) => { event.preventDefault(); assign.mutate(container.id); }}
                        className="grid gap-4 rounded-xl border border-line bg-paper p-4 md:grid-cols-5"
                      >
                        <Field label="Dossier" className="md:col-span-2">
                          <select required value={assignForm.shipment_id} onChange={(e) => setAssignForm({ ...assignForm, shipment_id: e.target.value })} className={inputClass}>
                            <option value="">— Sélectionner —</option>
                            {shipments?.data.map((shipment) => (
                              <option key={shipment.id} value={shipment.id}>{shipment.reference}</option>
                            ))}
                          </select>
                        </Field>
                        <Field label="N° plomb">
                          <input maxLength={32} value={assignForm.seal_number} onChange={(e) => setAssignForm({ ...assignForm, seal_number: e.target.value.toUpperCase() })} className={`${inputClass} mono`} />
                        </Field>
                        <Field label="VGM (kg)">
                          <input type="number" min={0} value={assignForm.vgm_kg} onChange={(e) => setAssignForm({ ...assignForm, vgm_kg: e.target.value })} className={inputClass} />
                        </Field>
                        <Field label="Franchise (j)">
                          <input type="number" min={0} max={90} value={assignForm.free_time_days} onChange={(e) => setAssignForm({ ...assignForm, free_time_days: e.target.value })} className={inputClass} />
                        </Field>
                        {error && <p className="rounded-lg bg-crit-soft px-3 py-2 text-xs text-crit md:col-span-5">{error}</p>}
                        <div className="flex gap-2 md:col-span-5">
                          <button type="submit" disabled={assign.isPending} className={buttonPrimary}>Affecter</button>
                          <button type="button" onClick={() => setAssignFor(null)} className={buttonSecondary}>Annuler</button>
                        </div>
                      </form>
                    </td>
                  </tr>
                )}
              </Fragment>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
