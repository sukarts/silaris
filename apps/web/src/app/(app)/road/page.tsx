"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useState } from "react";
import { downloadFile, problemMessage, rawApi } from "@/lib/api";
import { Field, buttonPrimary, buttonSecondary, inputClass } from "@/components/Field";
import { SignaturePad } from "@/components/SignaturePad";
import { TrackMap } from "@/components/TrackMap";
import { useCan } from "@/stores/auth";

interface Carrier {
  id: string;
  name: string;
}

interface Truck {
  id: string;
  plate_number: string;
  carrier_party_id: string | null;
  type: string | null;
  capacity_kg: string | null;
  inspection_due: string | null;
  insurance_due: string | null;
}

interface Trailer {
  id: string;
  plate_number: string;
  type: string | null;
  carrier_party_id: string | null;
}

interface Driver {
  id: string;
  name: string;
  carrier_party_id: string | null;
  phone: string | null;
  license_number: string | null;
  license_categories: string | null;
  license_expiry: string | null;
}

interface MissionStop {
  id: string;
  label: string;
  planned_at: string | null;
  position: number;
}

interface Mission {
  id: string;
  reference: string;
  type: string;
  status: string;
  window_start: string | null;
  window_end: string | null;
  failure_reason: string | null;
  driver: { id: string; name: string } | null;
  truck: { id: string; plate_number: string } | null;
  carrier: { id: string; name: string } | null;
  carrier_reference: string | null;
  shipment: { id: string; reference: string } | null;
  stops: MissionStop[];
}

const MISSION_TRANSITIONS: Record<string, string[]> = {
  planned: ["in_progress", "cancelled"],
  in_progress: ["delivered", "failed"],
  delivered: [],
  failed: ["planned"],
  cancelled: [],
};

const STATUS_LABEL: Record<string, string> = {
  planned: "Planifiée",
  in_progress: "En cours",
  delivered: "Livrée",
  failed: "Échec",
  cancelled: "Annulée",
};

const STATUS_TONE: Record<string, string> = {
  planned: "bg-sea-soft text-sea",
  in_progress: "bg-warn-soft text-warn",
  delivered: "bg-ok-soft text-ok",
  failed: "bg-crit-soft text-crit",
  cancelled: "bg-paper text-ink-3",
};

const TRANSITION_LABEL: Record<string, string> = {
  in_progress: "Démarrer",
  delivered: "Livrer",
  failed: "Échec",
  cancelled: "Annuler",
  planned: "Replanifier",
};

const TYPE_LABEL: Record<string, string> = { delivery: "Livraison", pickup: "Enlèvement", transfer: "Transfert" };

/**
 * Transporteurs affrétés : peu de transitaires roulent avec leurs propres
 * camions, le pré/post-acheminement passe par des prestataires enregistrés au
 * CRM comme fournisseurs. Cette information reste interne — ni le portail
 * client ni le suivi public ne la reprennent.
 */
/** Moyens rattachés au propriétaire retenu — flotte propre quand aucun prestataire. */
function ownedBy<T extends { carrier_party_id: string | null }>(items: T[] | undefined, carrierId: string): T[] {
  return (items ?? []).filter((item) => (item.carrier_party_id ?? "") === carrierId);
}

/** Étiquette « Propre » ou nom du prestataire, sans refaire une requête par ligne. */
function OwnerBadge({ carrierId }: { carrierId: string | null }) {
  const carriers = useCarriers();
  if (carrierId === null) {
    return <span className="text-[11px] text-ink-3">Propre</span>;
  }
  const name = carriers.data?.find((carrier) => carrier.id === carrierId)?.name;

  return <span className="rounded-full bg-sea-soft px-2 py-0.5 text-[11px] font-semibold text-sea">{name ?? "Affrété"}</span>;
}

function useCarriers() {
  return useQuery({
    queryKey: ["carriers"],
    queryFn: async () => {
      const { data } = await rawApi.GET("/v1/parties", {
        params: { query: { type: "supplier", supplier_kind: "trucker", per_page: 100 } },
      });
      return ((data as { data: Carrier[] } | undefined)?.data ?? []);
    },
  });
}

/** Sélecteur « flotte propre / prestataire », partagé par la flotte et les missions. */
function CarrierSelect({ value, onChange }: { value: string; onChange: (id: string) => void }) {
  const carriers = useCarriers();

  return (
    <select value={value} onChange={(e) => onChange(e.target.value)} className={inputClass}>
      <option value="">Flotte propre</option>
      {carriers.data?.map((carrier) => <option key={carrier.id} value={carrier.id}>{carrier.name}</option>)}
    </select>
  );
}

const emptyMissionForm = {
  shipment_id: "",
  type: "delivery",
  carrier_party_id: "",
  carrier_reference: "",
  truck_id: "",
  trailer_id: "",
  driver_id: "",
  window_start: "",
  window_end: "",
  origin_label: "",
  destination_label: "",
  notes: "",
};

export default function RoadPage() {
  const queryClient = useQueryClient();
  const canCreate = useCan("road.create");
  const canUpdate = useCan("road.update");
  const canPod = useCan("pod.create");
  const [tab, setTab] = useState<"missions" | "fleet" | "devices">("missions");
  const [trackFor, setTrackFor] = useState<Mission | null>(null);
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState(emptyMissionForm);
  const [podFor, setPodFor] = useState<Mission | null>(null);
  const [podForm, setPodForm] = useState({ recipient_name: "", remarks: "", signature_data: null as string | null });
  // Le POD se saisit sur le lieu de livraison : la position du téléphone de
  // l'exploitant atteste de l'endroit où la signature a été recueillie.
  const [podPosition, setPodPosition] = useState<{ latitude: number; longitude: number } | null>(null);
  const [error, setError] = useState<string | null>(null);

  const missions = useQuery({
    queryKey: ["missions"],
    queryFn: async () => {
      const { data: response } = await rawApi.GET("/v1/missions");
      return response as { data: Mission[] };
    },
  });

  const trucks = useQuery({
    queryKey: ["trucks"],
    queryFn: async () => {
      const { data: response } = await rawApi.GET("/v1/fleet/trucks");
      return response as { data: Truck[] };
    },
  });

  const trailers = useQuery({
    queryKey: ["trailers"],
    queryFn: async () => {
      const { data: response } = await rawApi.GET("/v1/fleet/trailers");
      return response as { data: Trailer[] };
    },
  });

  const drivers = useQuery({
    queryKey: ["drivers"],
    queryFn: async () => {
      const { data: response } = await rawApi.GET("/v1/fleet/drivers");
      return response as { data: Driver[] };
    },
  });

  const createMission = useMutation({
    mutationFn: async () => {
      const stops = [
        ...(form.origin_label ? [{ label: form.origin_label }] : []),
        ...(form.destination_label ? [{ label: form.destination_label }] : []),
      ];
      const { error: problem } = await rawApi.POST("/v1/missions", {
        body: {
          shipment_id: form.shipment_id || null,
          type: form.type,
          carrier_party_id: form.carrier_party_id || null,
          carrier_reference: form.carrier_reference || null,
          truck_id: form.truck_id || null,
          trailer_id: form.trailer_id || null,
          driver_id: form.driver_id || null,
          window_start: form.window_start || null,
          window_end: form.window_end || null,
          notes: form.notes || null,
          ...(stops.length > 0 ? { stops } : {}),
        },
      });
      if (problem) throw problem;
    },
    onSuccess: () => {
      setShowForm(false);
      setForm(emptyMissionForm);
      setError(null);
      queryClient.invalidateQueries({ queryKey: ["missions"] });
    },
    onError: (problem) => setError(problemMessage(problem)),
  });

  const transition = useMutation({
    mutationFn: async ({ missionId, status, failure_reason }: { missionId: string; status: string; failure_reason?: string }) => {
      const { error: problem } = await rawApi.POST(`/v1/missions/${missionId}/transition`, {
        body: { status, ...(failure_reason ? { failure_reason } : {}) },
      });
      if (problem) throw problem;
    },
    onSuccess: () => {
      setError(null);
      queryClient.invalidateQueries({ queryKey: ["missions"] });
    },
    onError: (problem) => setError(problemMessage(problem)),
  });

  const submitPod = useMutation({
    mutationFn: async () => {
      if (!podFor) return;
      const { error: problem } = await rawApi.POST(`/v1/missions/${podFor.id}/pod`, {
        body: {
          recipient_name: podForm.recipient_name,
          remarks: podForm.remarks || null,
          signature_data: podForm.signature_data,
          ...(podPosition ?? {}),
        },
      });
      if (problem) throw problem;
    },
    onSuccess: () => {
      setPodFor(null);
      setPodForm({ recipient_name: "", remarks: "", signature_data: null });
      setPodPosition(null);
      setError(null);
      queryClient.invalidateQueries({ queryKey: ["missions"] });
    },
    onError: (problem) => setError(problemMessage(problem)),
  });

  const openPod = (mission: Mission) => {
    setPodFor(mission);
    setPodPosition(null);
    navigator.geolocation?.getCurrentPosition(
      (position) => setPodPosition({ latitude: position.coords.latitude, longitude: position.coords.longitude }),
      // Refus ou GPS indisponible : la preuve reste valable sans coordonnées.
      () => setPodPosition(null),
      { enableHighAccuracy: true, timeout: 8000 },
    );
  };

  const onTransition = (mission: Mission, status: string) => {
    if (status === "failed") {
      const reason = window.prompt("Motif de l'échec ?");
      if (!reason) return;
      transition.mutate({ missionId: mission.id, status, failure_reason: reason });
      return;
    }
    transition.mutate({ missionId: mission.id, status });
  };

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-start">
        <div>
          <h1 className="text-xl font-bold">Terrestre</h1>
          <p className="text-[13px] text-ink-3">Missions de transport et gestion de la flotte</p>
        </div>
        {canCreate && tab === "missions" && (
          <button onClick={() => setShowForm((value) => !value)} className={`ml-auto ${buttonPrimary}`}>
            + Nouvelle mission
          </button>
        )}
      </div>

      <div className="flex gap-2">
        {(["missions", "fleet", "devices"] as const).map((key) => (
          <button
            key={key}
            onClick={() => setTab(key)}
            className={`rounded-full border px-3.5 py-1 text-xs font-semibold ${
              tab === key ? "border-ink bg-ink text-paper" : "border-line-strong text-ink-2 hover:bg-surface"
            }`}
          >
            {key === "missions" ? "Missions" : key === "fleet" ? "Flotte" : "Balises"}
          </button>
        ))}
      </div>

      {error && <p className="rounded-lg bg-crit-soft px-4 py-2.5 text-[13px] text-crit">{error}</p>}

      {tab === "missions" && (
        <>
          {trackFor && <MissionTrackPanel mission={trackFor} onClose={() => setTrackFor(null)} />}
          {showForm && (
            <form
              onSubmit={(event) => { event.preventDefault(); createMission.mutate(); }}
              className="grid gap-4 rounded-xl border border-line bg-surface p-5 shadow-sm md:grid-cols-6"
            >
              <Field label="Type">
                <select value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })} className={inputClass}>
                  <option value="delivery">Livraison</option>
                  <option value="pickup">Enlèvement</option>
                  <option value="transfer">Transfert</option>
                </select>
              </Field>
              <Field label="Transporteur">
                <CarrierSelect
                  value={form.carrier_party_id}
                  // Changer de transporteur invalide les moyens déjà choisis :
                  // un camion affrété ne roule que pour son propriétaire.
                  onChange={(id) => setForm({ ...form, carrier_party_id: id, truck_id: "", trailer_id: "", driver_id: "" })}
                />
              </Field>
              <Field label="Camion">
                <select value={form.truck_id} onChange={(e) => setForm({ ...form, truck_id: e.target.value })} className={inputClass}>
                  <option value="">—</option>
                  {ownedBy(trucks.data?.data, form.carrier_party_id).map((truck) => (
                    <option key={truck.id} value={truck.id}>{truck.plate_number}</option>
                  ))}
                </select>
              </Field>
              <Field label="Remorque">
                <select value={form.trailer_id} onChange={(e) => setForm({ ...form, trailer_id: e.target.value })} className={inputClass}>
                  <option value="">—</option>
                  {ownedBy(trailers.data?.data, form.carrier_party_id).map((trailer) => (
                    <option key={trailer.id} value={trailer.id}>{trailer.plate_number}</option>
                  ))}
                </select>
              </Field>
              <Field label="Chauffeur">
                <select value={form.driver_id} onChange={(e) => setForm({ ...form, driver_id: e.target.value })} className={inputClass}>
                  <option value="">—</option>
                  {ownedBy(drivers.data?.data, form.carrier_party_id).map((driver) => (
                    <option key={driver.id} value={driver.id}>{driver.name}</option>
                  ))}
                </select>
              </Field>
              <Field label="Début de créneau">
                <input type="datetime-local" value={form.window_start} onChange={(e) => setForm({ ...form, window_start: e.target.value })} className={inputClass} />
              </Field>
              <Field label="Fin de créneau">
                <input type="datetime-local" value={form.window_end} onChange={(e) => setForm({ ...form, window_end: e.target.value })} className={inputClass} />
              </Field>
              <Field label="Origine (étape 1)" className="md:col-span-2">
                <input value={form.origin_label} onChange={(e) => setForm({ ...form, origin_label: e.target.value })} className={inputClass} placeholder="Port d'Abidjan" />
              </Field>
              <Field label="Destination (étape 2)" className="md:col-span-2">
                <input value={form.destination_label} onChange={(e) => setForm({ ...form, destination_label: e.target.value })} className={inputClass} placeholder="Entrepôt client" />
              </Field>
              <Field label="N° d'ordre chez le transporteur">
                <input value={form.carrier_reference} onChange={(e) => setForm({ ...form, carrier_reference: e.target.value })} disabled={form.carrier_party_id === ""} placeholder={form.carrier_party_id === "" ? "—" : "OT-2026-014"} className={`${inputClass} mono`} />
              </Field>
              <Field label="Dossier (ID)" className="md:col-span-2">
                <input value={form.shipment_id} onChange={(e) => setForm({ ...form, shipment_id: e.target.value })} className={`${inputClass} mono`} placeholder="UUID du dossier (optionnel)" />
              </Field>
              <Field label="Notes" className="md:col-span-6">
                <input value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} className={inputClass} />
              </Field>
              <div className="flex gap-2 md:col-span-6">
                <button type="submit" disabled={createMission.isPending} className={buttonPrimary}>Créer</button>
                <button type="button" onClick={() => setShowForm(false)} className={buttonSecondary}>Annuler</button>
              </div>
            </form>
          )}

          {podFor && (
            <form
              onSubmit={(event) => { event.preventDefault(); submitPod.mutate(); }}
              className="grid gap-4 rounded-xl border border-line bg-surface p-5 shadow-sm md:grid-cols-6"
            >
              <p className="text-[13px] font-semibold md:col-span-6">
                Preuve de livraison — <span className="mono text-sea">{podFor.reference}</span>
              </p>
              <Field label="Nom du destinataire" className="md:col-span-2">
                <input required value={podForm.recipient_name} onChange={(e) => setPodForm({ ...podForm, recipient_name: e.target.value })} className={inputClass} />
              </Field>
              <Field label="Remarques" className="md:col-span-4">
                <input value={podForm.remarks} onChange={(e) => setPodForm({ ...podForm, remarks: e.target.value })} className={inputClass} />
              </Field>
              <Field label="Signature du destinataire" className="md:col-span-4">
                <SignaturePad value={podForm.signature_data} onChange={(data) => setPodForm({ ...podForm, signature_data: data })} />
              </Field>
              <Field label="Lieu de la signature" className="md:col-span-2">
                <p className="mono rounded-xl border border-line bg-paper px-3 py-2 text-[12px] text-ink-2">
                  {podPosition
                    ? `${podPosition.latitude.toFixed(5)}, ${podPosition.longitude.toFixed(5)}`
                    : "Position indisponible"}
                </p>
              </Field>
              <div className="flex gap-2 md:col-span-6">
                <button type="submit" disabled={submitPod.isPending} className={buttonPrimary}>Enregistrer le POD</button>
                <button type="button" onClick={() => setPodFor(null)} className={buttonSecondary}>Annuler</button>
              </div>
            </form>
          )}

          <div className="overflow-x-auto rounded-xl border border-line bg-surface shadow-sm">
            <table className="w-full text-[13px]">
              <thead>
                <tr className="border-b border-line text-left text-[10px] uppercase tracking-wider text-ink-3">
                  <th className="px-3 py-2.5">Référence</th>
                  <th className="px-3 py-2.5">Type</th>
                  <th className="px-3 py-2.5">Camion</th>
                  <th className="px-3 py-2.5">Chauffeur</th>
                  <th className="px-3 py-2.5">Trajet</th>
                  <th className="px-3 py-2.5">Dossier</th>
                  <th className="px-3 py-2.5">Créneau</th>
                  <th className="px-3 py-2.5">Statut</th>
                  <th className="px-3 py-2.5" />
                </tr>
              </thead>
              <tbody>
                {missions.isLoading && (
                  <tr><td colSpan={9} className="px-3 py-8 text-center text-ink-3">Chargement…</td></tr>
                )}
                {missions.data?.data.map((mission) => {
                  const stops = mission.stops ?? [];
                  const firstStop = stops[0];
                  const lastStop = stops[stops.length - 1];
                  const targets = MISSION_TRANSITIONS[mission.status] ?? [];
                  return (
                    <tr key={mission.id} className="border-b border-line last:border-0 hover:bg-sea/5">
                      <td className="mono px-3 py-2.5 font-semibold text-sea">{mission.reference}</td>
                      <td className="px-3 py-2.5 text-ink-2">{TYPE_LABEL[mission.type] ?? mission.type}</td>
                      <td className="mono px-3 py-2.5">
                        {mission.truck?.plate_number ?? "—"}
                        {mission.carrier && (
                          <span className="mt-0.5 block text-[11px] font-semibold text-sea">
                            {mission.carrier.name}{mission.carrier_reference ? ` · ${mission.carrier_reference}` : ""}
                          </span>
                        )}
                      </td>
                      <td className="px-3 py-2.5">{mission.driver?.name ?? "—"}</td>
                      <td className="px-3 py-2.5 text-ink-2">
                        {firstStop ? `${firstStop.label}${lastStop && lastStop.id !== firstStop.id ? ` → ${lastStop.label}` : ""}` : "—"}
                      </td>
                      <td className="mono px-3 py-2.5 text-ink-2">{mission.shipment?.reference ?? "—"}</td>
                      <td className="mono px-3 py-2.5 text-ink-2">
                        {mission.window_start ? new Date(mission.window_start).toLocaleString("fr-FR", { dateStyle: "short", timeStyle: "short" }) : "—"}
                      </td>
                      <td className="px-3 py-2.5">
                        <span
                          className={`rounded-full px-2.5 py-0.5 text-[11px] font-semibold ${STATUS_TONE[mission.status] ?? "bg-paper text-ink-3"}`}
                          title={mission.failure_reason ?? undefined}
                        >
                          {STATUS_LABEL[mission.status] ?? mission.status}
                        </span>
                      </td>
                      <td className="px-3 py-2.5">
                        <div className="flex gap-2.5">
                          {canUpdate && targets.map((target) => (
                            <button
                              key={target}
                              onClick={() => onTransition(mission, target)}
                              disabled={transition.isPending}
                              className={`text-xs font-semibold hover:underline ${target === "failed" || target === "cancelled" ? "text-crit" : "text-sea"}`}
                            >
                              {TRANSITION_LABEL[target] ?? target}
                            </button>
                          ))}
                          {mission.status === "delivered" && (
                            <button
                              onClick={() => downloadFile(`/v1/missions/${mission.id}/delivery-note`, "bon-livraison.pdf").catch(() => setError("Bon de livraison indisponible — aucune preuve de livraison enregistrée."))}
                              className="text-xs font-semibold text-sea hover:underline"
                            >
                              Bon de livraison
                            </button>
                          )}
                          {canPod && mission.status === "in_progress" && (
                            <button
                              onClick={() => openPod(mission)}
                              className="text-xs font-semibold text-ok hover:underline"
                            >
                              POD →
                            </button>
                          )}
                          <button
                            onClick={() => setTrackFor(mission)}
                            className="text-xs font-semibold text-sea hover:underline"
                          >
                            Carte
                          </button>
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </>
      )}

      {tab === "devices" && (
        <DevicesPanel trucks={trucks.data?.data ?? []} canCreate={canCreate} />
      )}

      {tab === "fleet" && (
        <div className="grid gap-4 xl:grid-cols-3">
          <TruckPanel trucks={trucks.data?.data ?? []} isLoading={trucks.isLoading} canCreate={canCreate} />
          <TrailerPanel trailers={trailers.data?.data ?? []} isLoading={trailers.isLoading} canCreate={canCreate} />
          <DriverPanel drivers={drivers.data?.data ?? []} isLoading={drivers.isLoading} canCreate={canCreate} />
        </div>
      )}
    </div>
  );
}

function TruckPanel({ trucks, isLoading, canCreate }: { trucks: Truck[]; isLoading: boolean; canCreate: boolean }) {
  const queryClient = useQueryClient();
  const [form, setForm] = useState({ plate_number: "", type: "", capacity_kg: "", carrier_party_id: "" });
  const [error, setError] = useState<string | null>(null);

  const create = useMutation({
    mutationFn: async () => {
      const { error: problem } = await rawApi.POST("/v1/fleet/trucks", {
        body: {
          plate_number: form.plate_number,
          type: form.type || null,
          carrier_party_id: form.carrier_party_id || null,
          capacity_kg: form.capacity_kg ? Number(form.capacity_kg) : null,
        },
      });
      if (problem) throw problem;
    },
    onSuccess: () => {
      setForm({ plate_number: "", type: "", capacity_kg: "", carrier_party_id: "" });
      setError(null);
      queryClient.invalidateQueries({ queryKey: ["trucks"] });
    },
    onError: (problem) => setError(problemMessage(problem)),
  });

  return (
    <div className="flex flex-col gap-3 rounded-xl border border-line bg-surface p-4 shadow-sm">
      <h2 className="text-sm font-bold">Camions</h2>
      {canCreate && (
        <form onSubmit={(event) => { event.preventDefault(); create.mutate(); }} className="flex flex-wrap gap-2">
          <input required maxLength={16} value={form.plate_number} onChange={(e) => setForm({ ...form, plate_number: e.target.value.toUpperCase() })} placeholder="Immatriculation" className={`${inputClass} mono w-36 flex-none`} />
          <input maxLength={32} value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })} placeholder="Type" className={`${inputClass} w-28 flex-none`} />
          <input type="number" min={0} value={form.capacity_kg} onChange={(e) => setForm({ ...form, capacity_kg: e.target.value })} placeholder="Cap. (kg)" className={`${inputClass} w-24 flex-none`} />
          <div className="w-44 flex-none"><CarrierSelect value={form.carrier_party_id} onChange={(id) => setForm({ ...form, carrier_party_id: id })} /></div>
          <button type="submit" disabled={create.isPending} className={buttonSecondary}>Ajouter</button>
        </form>
      )}
      {error && <p className="rounded-lg bg-crit-soft px-3 py-2 text-xs text-crit">{error}</p>}
      <table className="w-full text-[13px]">
        <thead>
          <tr className="border-b border-line text-left text-[10px] uppercase tracking-wider text-ink-3">
            <th className="px-2 py-2">Immat.</th>
            <th className="px-2 py-2">Type</th>
            <th className="px-2 py-2 text-right">Cap. (kg)</th>
            <th className="px-2 py-2">Visite tech.</th>
            <th className="px-2 py-2">Propriétaire</th>
          </tr>
        </thead>
        <tbody>
          {isLoading && <tr><td colSpan={5} className="px-2 py-6 text-center text-ink-3">Chargement…</td></tr>}
          {trucks.map((truck) => (
            <tr key={truck.id} className="border-b border-line last:border-0">
              <td className="mono px-2 py-2 font-semibold">{truck.plate_number}</td>
              <td className="px-2 py-2 text-ink-2">{truck.type ?? "—"}</td>
              <td className="mono px-2 py-2 text-right">{truck.capacity_kg != null ? Number(truck.capacity_kg).toLocaleString("fr-FR") : "—"}</td>
              <td className="mono px-2 py-2 text-ink-2">{truck.inspection_due ? new Date(truck.inspection_due).toLocaleDateString("fr-FR") : "—"}</td>
              <td className="px-2 py-2"><OwnerBadge carrierId={truck.carrier_party_id} /></td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function TrailerPanel({ trailers, isLoading, canCreate }: { trailers: Trailer[]; isLoading: boolean; canCreate: boolean }) {
  const queryClient = useQueryClient();
  const [form, setForm] = useState({ plate_number: "", type: "", carrier_party_id: "" });
  const [error, setError] = useState<string | null>(null);

  const create = useMutation({
    mutationFn: async () => {
      const { error: problem } = await rawApi.POST("/v1/fleet/trailers", {
        body: { plate_number: form.plate_number, type: form.type || null, carrier_party_id: form.carrier_party_id || null },
      });
      if (problem) throw problem;
    },
    onSuccess: () => {
      setForm({ plate_number: "", type: "", carrier_party_id: "" });
      setError(null);
      queryClient.invalidateQueries({ queryKey: ["trailers"] });
    },
    onError: (problem) => setError(problemMessage(problem)),
  });

  return (
    <div className="flex flex-col gap-3 rounded-xl border border-line bg-surface p-4 shadow-sm">
      <h2 className="text-sm font-bold">Remorques</h2>
      {canCreate && (
        <form onSubmit={(event) => { event.preventDefault(); create.mutate(); }} className="flex flex-wrap gap-2">
          <input required maxLength={16} value={form.plate_number} onChange={(e) => setForm({ ...form, plate_number: e.target.value.toUpperCase() })} placeholder="Immatriculation" className={`${inputClass} mono w-36 flex-none`} />
          <input maxLength={32} value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })} placeholder="Type" className={`${inputClass} w-28 flex-none`} />
          <div className="w-44 flex-none"><CarrierSelect value={form.carrier_party_id} onChange={(id) => setForm({ ...form, carrier_party_id: id })} /></div>
          <button type="submit" disabled={create.isPending} className={buttonSecondary}>Ajouter</button>
        </form>
      )}
      {error && <p className="rounded-lg bg-crit-soft px-3 py-2 text-xs text-crit">{error}</p>}
      <table className="w-full text-[13px]">
        <thead>
          <tr className="border-b border-line text-left text-[10px] uppercase tracking-wider text-ink-3">
            <th className="px-2 py-2">Immat.</th>
            <th className="px-2 py-2">Type</th>
            <th className="px-2 py-2">Propriétaire</th>
          </tr>
        </thead>
        <tbody>
          {isLoading && <tr><td colSpan={3} className="px-2 py-6 text-center text-ink-3">Chargement…</td></tr>}
          {trailers.map((trailer) => (
            <tr key={trailer.id} className="border-b border-line last:border-0">
              <td className="mono px-2 py-2 font-semibold">{trailer.plate_number}</td>
              <td className="px-2 py-2 text-ink-2">{trailer.type ?? "—"}</td>
              <td className="px-2 py-2"><OwnerBadge carrierId={trailer.carrier_party_id} /></td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function DriverPanel({ drivers, isLoading, canCreate }: { drivers: Driver[]; isLoading: boolean; canCreate: boolean }) {
  const queryClient = useQueryClient();
  const [form, setForm] = useState({ name: "", phone: "", license_number: "", carrier_party_id: "" });
  const [error, setError] = useState<string | null>(null);

  const create = useMutation({
    mutationFn: async () => {
      const { error: problem } = await rawApi.POST("/v1/fleet/drivers", {
        body: {
          name: form.name,
          phone: form.phone || null,
          license_number: form.license_number || null,
          carrier_party_id: form.carrier_party_id || null,
        },
      });
      if (problem) throw problem;
    },
    onSuccess: () => {
      setForm({ name: "", phone: "", license_number: "", carrier_party_id: "" });
      setError(null);
      queryClient.invalidateQueries({ queryKey: ["drivers"] });
    },
    onError: (problem) => setError(problemMessage(problem)),
  });

  return (
    <div className="flex flex-col gap-3 rounded-xl border border-line bg-surface p-4 shadow-sm">
      <h2 className="text-sm font-bold">Chauffeurs</h2>
      {canCreate && (
        <form onSubmit={(event) => { event.preventDefault(); create.mutate(); }} className="flex flex-wrap gap-2">
          <input required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="Nom" className={`${inputClass} w-36 flex-none`} />
          <input maxLength={32} value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} placeholder="Téléphone" className={`${inputClass} w-32 flex-none`} />
          <input maxLength={32} value={form.license_number} onChange={(e) => setForm({ ...form, license_number: e.target.value })} placeholder="N° permis" className={`${inputClass} mono w-28 flex-none`} />
          <button type="submit" disabled={create.isPending} className={buttonSecondary}>Ajouter</button>
        </form>
      )}
      {error && <p className="rounded-lg bg-crit-soft px-3 py-2 text-xs text-crit">{error}</p>}
      <table className="w-full text-[13px]">
        <thead>
          <tr className="border-b border-line text-left text-[10px] uppercase tracking-wider text-ink-3">
            <th className="px-2 py-2">Nom</th>
            <th className="px-2 py-2">Téléphone</th>
            <th className="px-2 py-2">Permis</th>
            <th className="px-2 py-2">Expiration</th>
            <th className="px-2 py-2">Employeur</th>
          </tr>
        </thead>
        <tbody>
          {isLoading && <tr><td colSpan={5} className="px-2 py-6 text-center text-ink-3">Chargement…</td></tr>}
          {drivers.map((driver) => (
            <tr key={driver.id} className="border-b border-line last:border-0">
              <td className="px-2 py-2 font-semibold">{driver.name}</td>
              <td className="mono px-2 py-2 text-ink-2">{driver.phone ?? "—"}</td>
              <td className="mono px-2 py-2 text-ink-2">{driver.license_number ?? "—"}</td>
              <td className="mono px-2 py-2 text-ink-2">{driver.license_expiry ? new Date(driver.license_expiry).toLocaleDateString("fr-FR") : "—"}</td>
              <td className="px-2 py-2"><OwnerBadge carrierId={driver.carrier_party_id} /></td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

interface TrackingDevice {
  id: string;
  identifier: string;
  label: string;
  kind: string;
  key_prefix: string;
  truck_id: string | null;
  plate_number: string | null;
  is_active: boolean;
  last_seen_at: string | null;
}

function DevicesPanel({ trucks, canCreate }: { trucks: Truck[]; canCreate: boolean }) {
  const queryClient = useQueryClient();
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState({ identifier: "", label: "", truck_id: "" });
  const [newKey, setNewKey] = useState<{ key: string; url: string } | null>(null);
  const [error, setError] = useState<string | null>(null);

  const { data } = useQuery({
    queryKey: ["devices"],
    queryFn: async () => {
      const { data: response } = await rawApi.GET("/v1/road/devices");
      return (response as { data: TrackingDevice[] }).data;
    },
  });

  const create = useMutation({
    mutationFn: async () => {
      const { data: response, error: problem } = await rawApi.POST("/v1/road/devices", {
        body: { ...form, truck_id: form.truck_id || null },
      });
      if (problem) throw problem;
      return response as { api_key: string; ingest_url: string };
    },
    onSuccess: (result) => {
      setNewKey({ key: result.api_key, url: result.ingest_url });
      setShowForm(false);
      setForm({ identifier: "", label: "", truck_id: "" });
      setError(null);
      queryClient.invalidateQueries({ queryKey: ["devices"] });
    },
    onError: (problem) => setError(problemMessage(problem)),
  });

  const assign = useMutation({
    mutationFn: async ({ id, truckId }: { id: string; truckId: string }) => {
      const { error: problem } = await rawApi.PATCH(`/v1/road/devices/${id}`, { body: { truck_id: truckId || null } });
      if (problem) throw problem;
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["devices"] }),
  });

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-start">
        <p className="text-[13px] text-ink-3">
          Une balise posée sur le véhicule suit la marchandise du port jusqu&apos;à la livraison. Toute balise GPS,
          plateforme télématique ou application mobile capable d&apos;un envoi HTTP fonctionne.
        </p>
        {canCreate && (
          <button onClick={() => { setShowForm((v) => !v); setNewKey(null); }} className={`ml-auto ${buttonPrimary}`}>
            + Enrôler une balise
          </button>
        )}
      </div>

      {newKey && (
        <div className="rounded-xl border border-line bg-surface p-5 shadow-sm">
          <p className="text-xs font-semibold text-ok">Balise enrôlée. Clé affichée une seule fois — copiez-la maintenant.</p>
          <div className="mono mt-3 select-all break-all rounded-lg bg-paper px-3 py-2 text-[13px]">{newKey.key}</div>
          <p className="pt-3 text-xs text-ink-3">
            À configurer dans la balise — envoi HTTP POST vers <span className="mono">{newKey.url}</span>, en-tête{" "}
            <span className="mono">X-Device-Key</span>, corps{" "}
            <span className="mono">{`{"positions":[{"latitude":…,"longitude":…,"recorded_at":"…"}]}`}</span>
          </p>
          <button onClick={() => navigator.clipboard.writeText(newKey.key)} className={`mt-3 ${buttonSecondary}`}>
            Copier la clé
          </button>
        </div>
      )}

      {showForm && (
        <form onSubmit={(e) => { e.preventDefault(); create.mutate(); }} className="grid gap-4 rounded-xl border border-line bg-surface p-5 shadow-sm md:grid-cols-3">
          <Field label="Identifiant (IMEI / n° de série)">
            <input required maxLength={64} value={form.identifier} onChange={(e) => setForm({ ...form, identifier: e.target.value })} placeholder="864893820001234" className={`${inputClass} mono`} />
          </Field>
          <Field label="Libellé">
            <input required value={form.label} onChange={(e) => setForm({ ...form, label: e.target.value })} placeholder="Balise camion CI-1234-AB" className={inputClass} />
          </Field>
          <Field label="Véhicule porteur">
            <select value={form.truck_id} onChange={(e) => setForm({ ...form, truck_id: e.target.value })} className={inputClass}>
              <option value="">— Non affectée —</option>
              {trucks.map((truck) => <option key={truck.id} value={truck.id}>{truck.plate_number}</option>)}
            </select>
          </Field>
          {error && <p className="rounded-lg bg-crit-soft px-3 py-2 text-xs text-crit md:col-span-3">{error}</p>}
          <div className="flex gap-2 md:col-span-3">
            <button type="submit" disabled={create.isPending} className={buttonPrimary}>Enrôler</button>
            <button type="button" onClick={() => setShowForm(false)} className={buttonSecondary}>Annuler</button>
          </div>
        </form>
      )}

      <div className="overflow-x-auto rounded-xl border border-line bg-surface shadow-sm">
        <table className="w-full text-[13px]">
          <thead>
            <tr className="border-b border-line text-left text-[10px] uppercase tracking-wider text-ink-3">
              <th className="px-3 py-2.5">Libellé</th>
              <th className="px-3 py-2.5">Identifiant</th>
              <th className="px-3 py-2.5">Véhicule</th>
              <th className="px-3 py-2.5">Dernier contact</th>
              <th className="px-3 py-2.5">Statut</th>
            </tr>
          </thead>
          <tbody>
            {(data ?? []).map((device) => {
              const seen = device.last_seen_at ? new Date(device.last_seen_at) : null;
              const stale = seen ? Date.now() - seen.getTime() > 6 * 3600 * 1000 : true;
              return (
                <tr key={device.id} className="border-b border-line last:border-0">
                  <td className="px-3 py-2.5 font-semibold">{device.label}</td>
                  <td className="mono px-3 py-2.5 text-ink-2">{device.identifier}</td>
                  <td className="px-3 py-2.5">
                    <select
                      value={device.truck_id ?? ""}
                      onChange={(e) => assign.mutate({ id: device.id, truckId: e.target.value })}
                      className={`${inputClass} py-1 text-xs`}
                    >
                      <option value="">— Non affectée —</option>
                      {trucks.map((truck) => <option key={truck.id} value={truck.id}>{truck.plate_number}</option>)}
                    </select>
                  </td>
                  <td className="mono px-3 py-2.5 text-ink-2">
                    {seen ? seen.toLocaleString("fr-FR", { day: "2-digit", month: "2-digit", hour: "2-digit", minute: "2-digit" }) : "—"}
                  </td>
                  <td className="px-3 py-2.5">
                    <span className={`rounded-full px-2.5 py-0.5 text-[11px] font-semibold ${
                      !device.is_active ? "bg-warn-soft text-warn" : stale ? "bg-crit-soft text-crit" : "bg-ok-soft text-ok"
                    }`}>
                      {!device.is_active ? "Inactive" : stale ? "Sans signal" : "En ligne"}
                    </span>
                  </td>
                </tr>
              );
            })}
            {(data ?? []).length === 0 && (
              <tr><td colSpan={5} className="px-3 py-8 text-center text-ink-3">Aucune balise enrôlée.</td></tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}

interface MissionTrack {
  mission: { id: string; reference: string; status: string };
  last_position: { latitude: string; longitude: string; speed_kmh: string | null; recorded_at: string } | null;
  distance_to_next_stop_m: number | null;
  positions: { latitude: string; longitude: string }[];
  stops: { label: string; latitude: string | null; longitude: string | null; arrived_at: string | null }[];
}

function MissionTrackPanel({ mission, onClose }: { mission: Mission; onClose: () => void }) {
  const { data, isLoading } = useQuery({
    queryKey: ["mission-track", mission.id],
    queryFn: async () => {
      const { data: response } = await rawApi.GET(`/v1/missions/${mission.id}/positions`);
      return response as MissionTrack;
    },
    // Mission en cours : la position bouge, on rafraîchit régulièrement.
    refetchInterval: mission.status === "in_progress" ? 60_000 : false,
  });

  const last = data?.last_position;

  return (
    <div className="rounded-xl border border-line bg-surface p-5 shadow-sm">
      <div className="flex items-start pb-4">
        <div>
          <h2 className="text-sm font-bold">Suivi véhicule — {mission.reference}</h2>
          <p className="pt-1 text-xs text-ink-3">
            {isLoading
              ? "Chargement…"
              : last
                ? `Dernier point ${new Date(last.recorded_at).toLocaleString("fr-FR", { day: "2-digit", month: "2-digit", hour: "2-digit", minute: "2-digit" })}${
                    last.speed_kmh ? ` · ${Math.round(Number(last.speed_kmh))} km/h` : ""
                  }${
                    data?.distance_to_next_stop_m != null
                      ? ` · ${(data.distance_to_next_stop_m / 1000).toFixed(1)} km du prochain arrêt`
                      : " · tous les arrêts atteints"
                  }`
                : "Aucune position reçue — vérifiez qu'une balise est affectée au véhicule."}
          </p>
        </div>
        <button onClick={onClose} className={`ml-auto ${buttonSecondary}`}>Fermer</button>
      </div>
      {data && (data.positions.length > 0 || data.stops.some((s) => s.latitude)) && (
        <TrackMap
          trail={data.positions}
          stops={data.stops.map((s) => ({ latitude: s.latitude ?? 0, longitude: s.longitude ?? 0, label: s.label, reached: s.arrived_at !== null })).filter((s) => s.latitude !== 0)}
          vehicle={last ? { latitude: last.latitude, longitude: last.longitude, label: "Véhicule" } : null}
          height={360}
        />
      )}
    </div>
  );
}
