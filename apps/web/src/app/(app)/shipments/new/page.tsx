"use client";

import { useQuery } from "@tanstack/react-query";
import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { problemMessage, rawApi } from "@/lib/api";
import { Field, buttonPrimary, inputClass } from "@/components/Field";
import { PlaceCombobox } from "@/components/PlaceCombobox";
import { useAuth } from "@/stores/auth";

interface Option {
  id: string;
  name?: string;
  legal_name?: string;
  code?: string;
  branches?: { id: string; name: string; code: string }[];
}

/** Agence de rattachement telle que /auth/me la renvoie. */
interface UserBranch {
  id: string;
  code: string;
  name: string;
  company_id: string;
  company_name: string | null;
}

export default function NewShipmentPage() {
  const router = useRouter();
  const user = useAuth((state) => state.user);
  const [form, setForm] = useState({
    client_id: "",
    company_id: "",
    branch_id: "",
    direction: "import",
    mode: "sea_fcl",
    incoterm_code: "CIF",
    origin_locode: "",
    destination_locode: "",
    priority: "normal",
    etd: "",
    eta: "",
    notes: "",
  });
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const { data: clients } = useQuery({
    queryKey: ["parties", "clients"],
    queryFn: async () => {
      const { data } = await rawApi.GET("/v1/parties", { params: { query: { type: "client", per_page: 100 } } });
      return (data as { data: Option[] }).data;
    },
  });
  // Le périmètre de saisie vient des agences de rattachement de l'utilisateur,
  // pas de l'administration : un agent transit ouvre des dossiers sans avoir
  // le droit de lire les paramètres de la société.
  const { data: scope } = useQuery({
    queryKey: ["auth", "me", "branches"],
    queryFn: async () => {
      const { data } = await rawApi.GET("/v1/auth/me");
      return (data as { branches: UserBranch[] } | undefined)?.branches ?? [];
    },
  });
  const { data: incoterms } = useQuery({
    queryKey: ["incoterms"],
    queryFn: async () => {
      const { data } = await rawApi.GET("/v1/referentials/incoterms", { params: { query: { per_page: 20 } } });
      return (data as { data: { code: string; label: string }[] }).data;
    },
  });

  const companies = Array.from(
    new Map((scope ?? []).map((branch) => [branch.company_id, branch.company_name])).entries(),
  ).map(([id, name]) => ({ id, name }));
  const branches = (scope ?? []).filter((branch) => branch.company_id === form.company_id);

  // Rattachement unique — le cas courant : on pré-remplit plutôt que d'imposer
  // deux choix sans alternative.
  const onlyCompany = companies.length === 1 ? companies[0] : undefined;
  const onlyBranch = branches.length === 1 ? branches[0] : undefined;
  useEffect(() => {
    if (onlyCompany && form.company_id === "") set("company_id", onlyCompany.id);
  }, [onlyCompany, form.company_id]);
  useEffect(() => {
    if (onlyBranch && form.branch_id === "") set("branch_id", onlyBranch.id);
  }, [onlyBranch, form.branch_id]);

  function set(key: string, value: string) {
    setForm((state) => ({ ...state, [key]: value }));
  }

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    if (!user) return;
    setSaving(true);
    setError(null);
    const { data, error: problem } = await rawApi.POST("/v1/shipments", {
      body: {
        ...form,
        agent_id: user.id,
        origin_locode: form.origin_locode.toUpperCase(),
        destination_locode: form.destination_locode.toUpperCase(),
        etd: form.etd || null,
        eta: form.eta || null,
        notes: form.notes || null,
      },
    });
    setSaving(false);
    if (problem) return setError(problemMessage(problem));
    const created = data as { data: { id: string } };
    router.push(`/shipments/${created.data.id}`);
  }

  return (
    <div className="mx-auto flex w-full max-w-3xl flex-col gap-5">
      <div>
        <h1 className="text-xl font-bold">Nouveau dossier</h1>
        <p className="text-[13px] text-ink-3">La référence sera générée automatiquement (agence + année + séquence).</p>
      </div>
      <form onSubmit={submit} className="flex flex-col gap-4 rounded-xl border border-line bg-surface p-6 shadow-sm">
        <div className="grid gap-4 md:grid-cols-2">
          <Field label="Client">
            <select required value={form.client_id} onChange={(e) => set("client_id", e.target.value)} className={inputClass}>
              <option value="">— Sélectionner —</option>
              {clients?.map((client) => (
                <option key={client.id} value={client.id}>{client.name}</option>
              ))}
            </select>
          </Field>
          <Field label="Sens / Mode">
            <div className="flex gap-2">
              <select value={form.direction} onChange={(e) => set("direction", e.target.value)} className={inputClass}>
                <option value="import">Import</option>
                <option value="export">Export</option>
                <option value="transit">Transit (transbordement)</option>
              </select>
              <select value={form.mode} onChange={(e) => set("mode", e.target.value)} className={inputClass}>
                <option value="sea_fcl">Maritime FCL</option>
                <option value="sea_lcl">Maritime LCL</option>
                <option value="air">Aérien</option>
                <option value="road">Routier</option>
                <option value="multimodal">Multimodal</option>
              </select>
            </div>
          </Field>
          <Field label="Société">
            <select required value={form.company_id} onChange={(e) => { set("company_id", e.target.value); set("branch_id", ""); }} className={inputClass}>
              <option value="">— Sélectionner —</option>
              {companies.map((company) => (
                <option key={company.id} value={company.id}>{company.name}</option>
              ))}
            </select>
          </Field>
          <Field label="Agence">
            <select required value={form.branch_id} onChange={(e) => set("branch_id", e.target.value)} className={inputClass} disabled={!form.company_id}>
              <option value="">— Sélectionner —</option>
              {branches.map((branch) => (
                <option key={branch.id} value={branch.id}>{branch.name}</option>
              ))}
            </select>
          </Field>
          <Field label="Origine (UN/LOCODE)">
            <PlaceCombobox referential="ports" required value={form.origin_locode} onChange={(v) => set("origin_locode", v)} placeholder="Port ou LOCODE (ex. Shanghai)" maxLength={5} />
          </Field>
          <Field label="Destination (UN/LOCODE)">
            <PlaceCombobox referential="ports" required value={form.destination_locode} onChange={(v) => set("destination_locode", v)} placeholder="Port ou LOCODE (ex. Abidjan)" maxLength={5} />
          </Field>
          <Field label="Incoterm">
            <select value={form.incoterm_code} onChange={(e) => set("incoterm_code", e.target.value)} className={inputClass}>
              {incoterms?.map((incoterm) => (
                <option key={incoterm.code} value={incoterm.code}>{incoterm.code} — {incoterm.label}</option>
              ))}
            </select>
          </Field>
          <Field label="Priorité">
            <select value={form.priority} onChange={(e) => set("priority", e.target.value)} className={inputClass}>
              <option value="low">Basse</option>
              <option value="normal">Normale</option>
              <option value="high">Haute</option>
              <option value="critical">Critique</option>
            </select>
          </Field>
          <Field label="ETD">
            <input type="date" value={form.etd} onChange={(e) => set("etd", e.target.value)} className={inputClass} />
          </Field>
          <Field label="ETA">
            <input type="date" value={form.eta} onChange={(e) => set("eta", e.target.value)} className={inputClass} />
          </Field>
        </div>
        <Field label="Notes">
          <textarea value={form.notes} onChange={(e) => set("notes", e.target.value)} rows={3} className={inputClass} />
        </Field>
        {error && <p className="rounded-lg bg-crit-soft px-4 py-2.5 text-[13px] text-crit">{error}</p>}
        <div className="flex justify-end gap-2">
          <button type="submit" disabled={saving} className={buttonPrimary}>
            {saving ? "Création…" : "Créer le dossier"}
          </button>
        </div>
      </form>
    </div>
  );
}
