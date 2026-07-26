"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useState } from "react";
import { problemMessage, rawApi } from "@/lib/api";
import { Field, buttonPrimary, buttonSecondary, inputClass } from "@/components/Field";
import { CountrySelect, DialCodeSelect } from "@/components/CountrySelect";
import { useCan } from "@/stores/auth";

interface Party {
  id: string;
  type: string;
  kind: "company" | "individual";
  supplier_kind: string | null;
  code: string;
  name: string;
  currency_code: string | null;
  payment_terms_days: number | null;
  credit_limit: string | null;
  contacts_count: number;
  portal_email: string | null;
}

const INDUSTRIES = [
  "Agroalimentaire", "Agriculture & matières premières", "Automobile", "BTP & construction",
  "Biens de consommation", "Boissons", "Bois & papier", "Chimie & pétrochimie",
  "Cosmétiques & hygiène", "Distribution & négoce", "Électronique & électroménager",
  "Énergie & mines", "Équipements industriels", "Métallurgie", "Pêche",
  "Pharmaceutique & santé", "Télécoms & IT", "Textile & habillement",
  "Transport & logistique", "Autre",
];

const TYPE_LABEL: Record<string, string> = { client: "Client", prospect: "Prospect", supplier: "Fournisseur" };

/** Métiers des fournisseurs du transit — le transporteur routier sert à la sous-traitance des missions. */
const SUPPLIER_KINDS: [string, string][] = [
  ["trucker", "Transporteur routier"],
  ["ocean_carrier", "Compagnie maritime"],
  ["airline", "Compagnie aérienne"],
  ["customs_agent", "Commissionnaire en douane"],
  ["handler", "Manutentionnaire"],
  ["port_agent", "Agent portuaire"],
  ["overseas_agent", "Agent à l'étranger"],
  ["insurer", "Assureur"],
];

const SUPPLIER_LABEL: Record<string, string> = Object.fromEntries(SUPPLIER_KINDS);
const TYPE_TONE: Record<string, string> = {
  client: "bg-ok-soft text-ok",
  prospect: "bg-warn-soft text-warn",
  supplier: "bg-sea-soft text-sea",
};

export default function CrmPage() {
  const queryClient = useQueryClient();
  const canCreate = useCan("crm.create");
  const canConvert = useCan("crm.convert");
  const canUpdate = useCan("crm.update");
  const [inviteInfo, setInviteInfo] = useState<string | null>(null);
  const [typeFilter, setTypeFilter] = useState<string>("");
  const [search, setSearch] = useState("");
  const [showForm, setShowForm] = useState(false);
  const emptyForm = {
    type: "client", kind: "company", supplier_kind: "trucker", code: "", name: "", tax_id: "", industry: "",
    currency_code: "XOF", payment_terms_days: "30",
    contact_name: "", contact_email: "", contact_dial: "+225", contact_phone: "",
    address_line1: "", address_city: "", address_country: "",
  };
  const [form, setForm] = useState(emptyForm);
  const [error, setError] = useState<string | null>(null);

  const { data, isLoading } = useQuery({
    queryKey: ["parties", typeFilter, search],
    queryFn: async () => {
      const { data: response } = await rawApi.GET("/v1/parties", {
        params: { query: { ...(typeFilter ? { type: typeFilter } : {}), ...(search ? { search } : {}), per_page: 50 } },
      });
      return response as { data: Party[] };
    },
  });

  const create = useMutation({
    mutationFn: async () => {
      const { error: problem } = await rawApi.POST("/v1/parties", {
        body: {
          type: form.type,
          kind: form.kind,
          ...(form.type === "supplier" ? { supplier_kind: form.supplier_kind } : {}),
          code: form.code || null,
          name: form.name,
          tax_id: form.tax_id || null,
          industry: form.industry || null,
          currency_code: form.currency_code || null,
          payment_terms_days: Number(form.payment_terms_days) || null,
          ...(form.contact_name
            ? { contact: { name: form.contact_name, email: form.contact_email || null, phone: form.contact_phone ? `${form.contact_dial} ${form.contact_phone}` : null } }
            : {}),
          ...(form.address_line1 && form.address_city && form.address_country
            ? { address: { line1: form.address_line1, city: form.address_city, country_code: form.address_country } }
            : {}),
        },
      });
      if (problem) throw problem;
    },
    onSuccess: () => {
      setShowForm(false);
      setForm(emptyForm);
      setError(null);
      queryClient.invalidateQueries({ queryKey: ["parties"] });
    },
    onError: (problem) => setError(problemMessage(problem)),
  });

  const invitePortal = useMutation({
    mutationFn: async (partyId: string) => {
      const { data: response, error: problem } = await rawApi.POST(`/v1/parties/${partyId}/portal-account`);
      if (problem) throw problem;
      return response as { portal_account: { email: string }; invitation_sent: boolean; temporary_password: string | null };
    },
    onSuccess: (result) => {
      setInviteInfo(
        result.invitation_sent
          ? `Invitation envoyée à ${result.portal_account.email}.`
          : `Email non envoyé — mot de passe provisoire à transmettre : ${result.temporary_password}`,
      );
      queryClient.invalidateQueries({ queryKey: ["parties"] });
    },
    onError: (problem) => setInviteInfo(problemMessage(problem)),
  });

  const convert = useMutation({
    mutationFn: async (partyId: string) => {
      const { error: problem } = await rawApi.POST(`/v1/parties/${partyId}/convert`);
      if (problem) throw problem;
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["parties"] }),
  });

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-start">
        <div>
          <h1 className="text-xl font-bold">CRM</h1>
          <p className="text-[13px] text-ink-3">Clients, prospects et fournisseurs</p>
        </div>
        {canCreate && (
          <button onClick={() => setShowForm((value) => !value)} className={`ml-auto ${buttonPrimary}`}>
            + Nouvelle fiche
          </button>
        )}
      </div>

      {showForm && (
        <form
          onSubmit={(event) => { event.preventDefault(); create.mutate(); }}
          className="grid gap-4 rounded-xl border border-line bg-surface p-5 shadow-sm md:grid-cols-6"
        >
          <Field label="Type">
            <select value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })} className={inputClass}>
              <option value="client">Client</option>
              <option value="prospect">Prospect</option>
              <option value="supplier">Fournisseur</option>
            </select>
          </Field>
          {form.type === "supplier" && (
            <Field label="Métier du fournisseur">
              <select value={form.supplier_kind} onChange={(e) => setForm({ ...form, supplier_kind: e.target.value })} className={inputClass}>
                {SUPPLIER_KINDS.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
              </select>
            </Field>
          )}
          <Field label="Nature">
            <select value={form.kind} onChange={(e) => setForm({ ...form, kind: e.target.value })} className={inputClass}>
              <option value="company">Personne morale (société)</option>
              <option value="individual">Personne physique</option>
            </select>
          </Field>
          <Field label="Code">
            <input maxLength={24} value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value.toUpperCase() })} placeholder="Automatique" className={`${inputClass} mono`} />
          </Field>
          <Field label={form.kind === "company" ? "Raison sociale" : "Nom & prénom"} className="md:col-span-2">
            <input required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className={inputClass} />
          </Field>
          <Field label="Délai paiement (j)">
            <input type="number" min={0} value={form.payment_terms_days} onChange={(e) => setForm({ ...form, payment_terms_days: e.target.value })} className={inputClass} />
          </Field>
          <Field label={form.kind === "company" ? "RCCM / Registre" : "N° pièce d'identité"} className="md:col-span-2">
            <input maxLength={64} value={form.tax_id} onChange={(e) => setForm({ ...form, tax_id: e.target.value })} placeholder={form.kind === "company" ? "CI-ABJ-2026-B-12345" : ""} className={`${inputClass} mono`} />
          </Field>
          <Field label="Secteur d'activité" className="md:col-span-2">
            <select value={form.industry} onChange={(e) => setForm({ ...form, industry: e.target.value })} className={inputClass}>
              <option value="">— Sélectionner —</option>
              {INDUSTRIES.map((industry) => <option key={industry} value={industry}>{industry}</option>)}
            </select>
          </Field>
          <Field label="Devise">
            <select value={form.currency_code} onChange={(e) => setForm({ ...form, currency_code: e.target.value })} className={inputClass}>
              {["XOF", "EUR", "USD", "GNF", "MAD", "NGN", "GHS", "CNY"].map((code) => <option key={code} value={code}>{code}</option>)}
            </select>
          </Field>
          <Field label="Pays">
            <CountrySelect value={form.address_country} onChange={(v) => setForm({ ...form, address_country: v })} />
          </Field>
          <Field label="Adresse" className="md:col-span-3">
            <input value={form.address_line1} onChange={(e) => setForm({ ...form, address_line1: e.target.value })} placeholder="Rue, zone, BP…" className={inputClass} />
          </Field>
          <Field label="Ville" className="md:col-span-2">
            <input value={form.address_city} onChange={(e) => setForm({ ...form, address_city: e.target.value })} className={inputClass} />
          </Field>
          <Field label="Personne de contact" className="md:col-span-2">
            <input value={form.contact_name} onChange={(e) => setForm({ ...form, contact_name: e.target.value })} placeholder="Nom du contact principal" className={inputClass} />
          </Field>
          <Field label="Email contact" className="md:col-span-2">
            <input type="email" value={form.contact_email} onChange={(e) => setForm({ ...form, contact_email: e.target.value })} className={inputClass} />
          </Field>
          <Field label="Téléphone contact" className="md:col-span-2">
            <div className="flex gap-2">
              <DialCodeSelect value={form.contact_dial} onChange={(dial) => setForm({ ...form, contact_dial: dial })} />
              <input value={form.contact_phone} onChange={(e) => setForm({ ...form, contact_phone: e.target.value.replace(/[^0-9 ]/g, "") })} placeholder="01 02 03 04 05" className={`${inputClass} mono`} />
            </div>
          </Field>
          {error && <p className="rounded-lg bg-crit-soft px-3 py-2 text-xs text-crit md:col-span-6">{error}</p>}
          <div className="flex gap-2 md:col-span-6">
            <button type="submit" disabled={create.isPending} className={buttonPrimary}>Créer</button>
            <button type="button" onClick={() => setShowForm(false)} className={buttonSecondary}>Annuler</button>
          </div>
        </form>
      )}

      {inviteInfo && <p className="rounded-lg bg-sea-soft px-3 py-2 text-xs text-ink-2">{inviteInfo}</p>}

      <div className="flex flex-wrap gap-2">
        {["", "client", "prospect", "supplier"].map((type) => (
          <button
            key={type}
            onClick={() => setTypeFilter(type)}
            className={`rounded-full border px-3.5 py-1 text-xs font-semibold ${
              typeFilter === type ? "border-ink bg-ink text-paper" : "border-line-strong text-ink-2 hover:bg-surface"
            }`}
          >
            {type === "" ? "Tous" : TYPE_LABEL[type]}
          </button>
        ))}
        <input
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="Rechercher…"
          className="ml-auto w-56 rounded-lg border border-line bg-surface px-3 py-1.5 text-[13px] focus:outline-2 focus:outline-accent"
        />
      </div>

      <div className="overflow-x-auto rounded-xl border border-line bg-surface shadow-sm">
        <table className="w-full text-[13px]">
          <thead>
            <tr className="border-b border-line text-left text-[10px] uppercase tracking-wider text-ink-3">
              <th className="px-3 py-2.5">Code</th>
              <th className="px-3 py-2.5">Nom</th>
              <th className="px-3 py-2.5">Type</th>
              <th className="px-3 py-2.5">Devise</th>
              <th className="px-3 py-2.5">Paiement</th>
              <th className="px-3 py-2.5">Contacts</th>
              <th className="px-3 py-2.5" />
            </tr>
          </thead>
          <tbody>
            {isLoading && (
              <tr><td colSpan={7} className="px-3 py-8 text-center text-ink-3">Chargement…</td></tr>
            )}
            {data?.data.map((party) => (
              <tr key={party.id} className="border-b border-line last:border-0 hover:bg-sea/5">
                <td className="mono px-3 py-2.5 font-semibold text-sea">{party.code}</td>
                <td className="px-3 py-2.5">
                  {party.name}
                  {party.kind === "individual" && (
                    <span className="ml-1.5 rounded border border-line bg-paper px-1.5 py-px text-[10px] text-ink-3">particulier</span>
                  )}
                </td>
                <td className="px-3 py-2.5">
                  <span className={`rounded-full px-2.5 py-0.5 text-[11px] font-semibold ${TYPE_TONE[party.type]}`}>
                    {TYPE_LABEL[party.type]}
                    {party.supplier_kind ? ` · ${SUPPLIER_LABEL[party.supplier_kind] ?? party.supplier_kind}` : ""}
                  </span>
                </td>
                <td className="mono px-3 py-2.5">{party.currency_code ?? "—"}</td>
                <td className="px-3 py-2.5 text-ink-2">{party.payment_terms_days ? `${party.payment_terms_days} j` : "—"}</td>
                <td className="mono px-3 py-2.5">{party.contacts_count}</td>
                <td className="px-3 py-2.5">
                  <div className="flex items-center gap-3">
                    {party.type === "prospect" && canConvert && (
                      <button onClick={() => convert.mutate(party.id)} className="text-xs font-semibold text-sea hover:underline">
                        Convertir en client →
                      </button>
                    )}
                    {party.type === "client" && canUpdate && (
                      party.portal_email ? (
                        <button
                          onClick={() => invitePortal.mutate(party.id)}
                          disabled={invitePortal.isPending}
                          title={`Portail actif : ${party.portal_email} — régénère le mot de passe`}
                          className="text-xs font-semibold text-ok hover:underline"
                        >
                          Portail ✓ · Réinviter
                        </button>
                      ) : (
                        <button
                          onClick={() => invitePortal.mutate(party.id)}
                          disabled={invitePortal.isPending}
                          className="text-xs font-semibold text-sea hover:underline"
                        >
                          Inviter au portail
                        </button>
                      )
                    )}
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
