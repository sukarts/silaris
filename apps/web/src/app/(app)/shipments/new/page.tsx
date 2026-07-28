"use client";

import { useQuery } from "@tanstack/react-query";
import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { problemMessage, rawApi } from "@/lib/api";
import { Field, buttonPrimary, inputClass } from "@/components/Field";
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

const MODE_LABEL: Record<string, string> = { sea_fcl: "Maritime FCL", sea_lcl: "Maritime LCL", air: "Aérien", road: "Terrestre", multimodal: "Multimodal" };
const DIRECTION_LABEL: Record<string, string> = { import: "Import", export: "Export", transit: "Transit" };

interface QuoteOption {
  id: string;
  number: string;
  mode: string;
  direction: string;
  origin_locode: string;
  destination_locode: string;
  incoterm_code: string;
  currency_code: string;
  total_amount: string;
}

export default function NewShipmentPage() {
  const router = useRouter();
  const user = useAuth((state) => state.user);
  const [form, setForm] = useState({
    client_id: "",
    company_id: "",
    branch_id: "",
    quote_id: "",
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
  // Un dossier n'existe que sur accord préalable du client : la liste ne montre
  // que ses cotations acceptées.
  const { data: quotes } = useQuery({
    queryKey: ["quotes", "accepted", form.client_id],
    enabled: form.client_id !== "",
    queryFn: async () => {
      const { data } = await rawApi.GET("/v1/quotes", {
        params: { query: { status: "accepted", party_id: form.client_id, per_page: 100 } },
      });
      return (data as { data: QuoteOption[] }).data;
    },
  });

  const chosenQuote = quotes?.find((quote) => quote.id === form.quote_id);

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
        // Mode, sens, incoterm et ports viennent de la cotation acceptée :
        // les envoyer ici les laisserait diverger de ce que le client a validé.
        ...form,
        agent_id: user.id,
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
        {form.client_id !== "" && quotes?.length === 0 && (
          <p className="rounded-lg bg-warn-soft px-3 py-2 text-xs text-warn">
            Ce client n'a aucune cotation acceptée en attente de dossier. Émettez-en une, ou attendez son accord.
          </p>
        )}

        {chosenQuote && (
          <div className="rounded-xl bg-paper p-4">
            <p className="text-[10px] uppercase tracking-wider text-ink-3">Conditions acceptées par le client</p>
            <p className="mt-1 text-[13px]">
              <span className="font-semibold">{DIRECTION_LABEL[chosenQuote.direction] ?? chosenQuote.direction}</span>
              {" · "}{MODE_LABEL[chosenQuote.mode] ?? chosenQuote.mode}
              {" · "}<span className="mono">{chosenQuote.origin_locode} → {chosenQuote.destination_locode}</span>
              {" · "}{chosenQuote.incoterm_code}
            </p>
            <p className="mono mt-0.5 text-[13px] font-bold">
              {Number(chosenQuote.total_amount).toLocaleString("fr-FR")} {chosenQuote.currency_code}
            </p>
          </div>
        )}

        <div className="grid gap-4 md:grid-cols-2">
          <Field label="Client">
            <select required value={form.client_id} onChange={(e) => set("client_id", e.target.value)} className={inputClass}>
              <option value="">— Sélectionner —</option>
              {clients?.map((client) => (
                <option key={client.id} value={client.id}>{client.name}</option>
              ))}
            </select>
          </Field>
          <Field label="Cotation acceptée">
            <select required value={form.quote_id} onChange={(e) => set("quote_id", e.target.value)} className={inputClass} disabled={!form.client_id}>
              <option value="">{form.client_id ? "— Sélectionner —" : "Choisissez d'abord le client"}</option>
              {quotes?.map((quote) => (
                <option key={quote.id} value={quote.id}>
                  {quote.number} — {quote.origin_locode} → {quote.destination_locode}
                </option>
              ))}
            </select>
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
