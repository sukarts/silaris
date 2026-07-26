"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useEffect, useRef, useState } from "react";
import { problemMessage, rawApi } from "@/lib/api";
import { Field, buttonPrimary, buttonSecondary, inputClass } from "@/components/Field";
import { CountrySelect } from "@/components/CountrySelect";
import { useAuth, useCan } from "@/stores/auth";

interface Branch {
  id: string;
  name: string;
  code: string;
  timezone: string;
  phone: string | null;
  email: string | null;
  is_active: boolean;
}

interface Company {
  id: string;
  legal_name: string;
  code: string;
  tax_id: string | null;
  currency_code: string;
  address: { line1?: string; city?: string; country?: string } | null;
  invoice_settings: { number_format?: string; footer?: string } | null;
  shipment_settings: { reference_format?: string; reference_prefix?: string } | null;
  logo_document_id: string | null;
  branches: Branch[];
}

const FORMAT_PRESETS = [
  { format: "{COMPANY}-{YEAR}-{SEQ:5}", label: "TAL-2026-00128 — code société + année" },
  { format: "{PREFIX}-{YEAR}-{SEQ:4}", label: "SAHA-2026-0128 — préfixe libre + année" },
  { format: "{PREFIX}/{BRANCH}/{YY}/{SEQ:4}", label: "SAHA/ABJ/26/0128 — préfixe + agence" },
  { format: "{PREFIX}{YY}{MONTH}-{SEQ:4}", label: "SAHA2607-0128 — préfixe + année/mois" },
  { format: "{BRANCH}-{YEAR}-{SEQ:5}", label: "ABJ-2026-00128 — code agence + année" },
];

const TIMEZONES = [
  "Africa/Abidjan", "Africa/Dakar", "Africa/Bamako", "Africa/Conakry", "Africa/Ouagadougou",
  "Africa/Lome", "Africa/Cotonou", "Africa/Accra", "Africa/Lagos", "Africa/Douala",
  "Africa/Casablanca", "Africa/Tunis", "Africa/Algiers", "Europe/Paris", "UTC",
];

function CompanyTab({ company, canUpdate }: { company: Company; canUpdate: boolean }) {
  const queryClient = useQueryClient();
  const [form, setForm] = useState({
    legal_name: company.legal_name,
    tax_id: company.tax_id ?? "",
    currency_code: company.currency_code,
    line1: company.address?.line1 ?? "",
    city: company.address?.city ?? "",
    country: company.address?.country ?? "",
    reference_format: company.shipment_settings?.reference_format ?? "{COMPANY}-{YEAR}-{SEQ:5}",
    reference_prefix: company.shipment_settings?.reference_prefix ?? company.code,
    invoice_format: company.invoice_settings?.number_format ?? "F-{YEAR}-{SEQ:4}",
    footer: company.invoice_settings?.footer ?? "",
  });
  const [preview, setPreview] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [logoUrl, setLogoUrl] = useState<string | null>(null);
  const fileRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    rawApi.GET(`/v1/admin/companies/${company.id}/logo-url`).then(({ data }) => {
      setLogoUrl((data as { logo_url: string | null } | undefined)?.logo_url ?? null);
    });
  }, [company.id]);

  useEffect(() => {
    const timer = setTimeout(async () => {
      const { data } = await rawApi.GET(`/v1/admin/companies/${company.id}/reference-preview`, {
        params: { query: { format: form.reference_format, prefix: form.reference_prefix } },
      });
      setPreview((data as { preview: string } | undefined)?.preview ?? null);
    }, 250);
    return () => clearTimeout(timer);
  }, [company.id, form.reference_format, form.reference_prefix]);

  const save = useMutation({
    mutationFn: async () => {
      const { error: problem } = await rawApi.PATCH(`/v1/admin/companies/${company.id}`, {
        body: {
          legal_name: form.legal_name,
          tax_id: form.tax_id || null,
          currency_code: form.currency_code,
          address: { line1: form.line1, city: form.city, country: form.country },
          invoice_settings: { ...company.invoice_settings, number_format: form.invoice_format, footer: form.footer },
          shipment_settings: { reference_format: form.reference_format, reference_prefix: form.reference_prefix },
        },
      });
      if (problem) throw problem;
    },
    onSuccess: () => {
      setMessage("Paramètres enregistrés.");
      queryClient.invalidateQueries({ queryKey: ["companies"] });
    },
    onError: (problem) => setMessage(problemMessage(problem)),
  });

  async function uploadLogo(file: File) {
    const base = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8088/api";
    const body = new FormData();
    body.append("logo", file);
    const response = await fetch(`${base}/v1/admin/companies/${company.id}/logo`, {
      method: "POST",
      headers: { Authorization: `Bearer ${useAuth.getState().token ?? ""}` },
      body,
    });
    if (!response.ok) return setMessage("Logo refusé (PNG/JPG/WebP, 2 Mo max).");
    const result = (await response.json()) as { logo_url: string };
    setLogoUrl(result.logo_url);
    setMessage("Logo mis à jour.");
  }

  return (
    <div className="flex flex-col gap-4">
      {message && <p className="rounded-lg bg-ok-soft px-3 py-2 text-xs text-ok">{message}</p>}

      <section className="rounded-xl border border-line bg-surface p-5 shadow-sm">
        <h2 className="pb-4 text-sm font-bold">Informations légales</h2>
        <div className="grid gap-4 md:grid-cols-3">
          <Field label="Raison sociale" className="md:col-span-2">
            <input value={form.legal_name} onChange={(e) => setForm({ ...form, legal_name: e.target.value })} disabled={!canUpdate} className={inputClass} />
          </Field>
          <Field label="Code société">
            <input value={company.code} disabled className={`${inputClass} mono opacity-60`} />
          </Field>
          <Field label="RCCM / Identifiant fiscal" className="md:col-span-2">
            <input value={form.tax_id} onChange={(e) => setForm({ ...form, tax_id: e.target.value })} disabled={!canUpdate} placeholder="CI-ABJ-2020-B-12345" className={`${inputClass} mono`} />
          </Field>
          <Field label="Devise">
            <select value={form.currency_code} onChange={(e) => setForm({ ...form, currency_code: e.target.value })} disabled={!canUpdate} className={inputClass}>
              {["XOF", "EUR", "USD", "GNF", "MAD", "NGN", "GHS", "CNY"].map((code) => <option key={code} value={code}>{code}</option>)}
            </select>
          </Field>
          <Field label="Adresse" className="md:col-span-2">
            <input value={form.line1} onChange={(e) => setForm({ ...form, line1: e.target.value })} disabled={!canUpdate} placeholder="Zone portuaire, Vridi" className={inputClass} />
          </Field>
          <Field label="Ville">
            <input value={form.city} onChange={(e) => setForm({ ...form, city: e.target.value })} disabled={!canUpdate} className={inputClass} />
          </Field>
          <Field label="Pays">
            <CountrySelect value={form.country} onChange={(v) => setForm({ ...form, country: v })} />
          </Field>
        </div>
      </section>

      <section className="rounded-xl border border-line bg-surface p-5 shadow-sm">
        <h2 className="text-sm font-bold">Numérotation des dossiers</h2>
        <p className="pb-4 pt-1 text-xs text-ink-3">
          Placeholders : <span className="mono">{"{PREFIX}"}</span> préfixe · <span className="mono">{"{COMPANY}"}</span> code société ·{" "}
          <span className="mono">{"{BRANCH}"}</span> code agence · <span className="mono">{"{YEAR}"}</span> année ·{" "}
          <span className="mono">{"{YY}"}</span> année courte · <span className="mono">{"{MONTH}"}</span> mois ·{" "}
          <span className="mono">{"{SEQ:4}"}</span> séquence (chiffres).
        </p>
        <div className="grid gap-4 md:grid-cols-3">
          <Field label="Préfixe">
            <input maxLength={16} value={form.reference_prefix} onChange={(e) => setForm({ ...form, reference_prefix: e.target.value.toUpperCase() })} disabled={!canUpdate} className={`${inputClass} mono`} />
          </Field>
          <Field label="Format" className="md:col-span-2">
            <select value={form.reference_format} onChange={(e) => setForm({ ...form, reference_format: e.target.value })} disabled={!canUpdate} className={inputClass}>
              {FORMAT_PRESETS.map((preset) => <option key={preset.format} value={preset.format}>{preset.label}</option>)}
              {!FORMAT_PRESETS.some((p) => p.format === form.reference_format) && (
                <option value={form.reference_format}>{form.reference_format} — personnalisé</option>
              )}
            </select>
          </Field>
          <div className="md:col-span-3">
            <span className="text-[10px] uppercase tracking-wide text-ink-3">Aperçu du prochain dossier</span>
            <div className="mono pt-1 text-lg font-bold text-sea">{preview ?? "…"}</div>
          </div>
        </div>
      </section>

      <section className="rounded-xl border border-line bg-surface p-5 shadow-sm">
        <h2 className="pb-4 text-sm font-bold">Facturation & logo</h2>
        <div className="grid gap-4 md:grid-cols-3">
          <Field label="Format n° de facture">
            <input value={form.invoice_format} onChange={(e) => setForm({ ...form, invoice_format: e.target.value })} disabled={!canUpdate} className={`${inputClass} mono`} />
          </Field>
          <Field label="Mentions légales (pied de page PDF)" className="md:col-span-2">
            <input value={form.footer} onChange={(e) => setForm({ ...form, footer: e.target.value })} disabled={!canUpdate} placeholder="SARL au capital de … — RCCM …" className={inputClass} />
          </Field>
          <div className="md:col-span-3">
            <span className="text-[10px] uppercase tracking-wide text-ink-3">Logo</span>
            <div className="flex items-center gap-4 pt-2">
              {logoUrl ? (
                // eslint-disable-next-line @next/next/no-img-element
                <img src={logoUrl} alt="Logo de la société" className="h-14 rounded-lg border border-line bg-white object-contain p-1" />
              ) : (
                <div className="grid h-14 w-24 place-items-center rounded-lg border border-dashed border-line-strong text-[10px] text-ink-3">Aucun</div>
              )}
              {canUpdate && (
                <>
                  <input ref={fileRef} type="file" accept="image/png,image/jpeg,image/webp" className="hidden"
                    onChange={(e) => { const f = e.target.files?.[0]; if (f) uploadLogo(f); }} />
                  <button type="button" onClick={() => fileRef.current?.click()} className={buttonSecondary}>
                    {logoUrl ? "Remplacer le logo" : "Ajouter un logo"}
                  </button>
                  <span className="text-[11px] text-ink-3">PNG, JPG ou WebP · 2 Mo max</span>
                </>
              )}
            </div>
          </div>
        </div>
      </section>

      {canUpdate && (
        <div>
          <button onClick={() => save.mutate()} disabled={save.isPending} className={buttonPrimary}>
            Enregistrer les paramètres
          </button>
        </div>
      )}
    </div>
  );
}

function BranchesTab({ company, canCreate }: { company: Company; canCreate: boolean }) {
  const queryClient = useQueryClient();
  const emptyBranch = { name: "", code: "", timezone: "Africa/Abidjan", phone: "", email: "" };
  const [form, setForm] = useState(emptyBranch);
  const [showForm, setShowForm] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const create = useMutation({
    mutationFn: async () => {
      const { error: problem } = await rawApi.POST(`/v1/admin/companies/${company.id}/branches`, {
        body: { ...form, phone: form.phone || null, email: form.email || null },
      });
      if (problem) throw problem;
    },
    onSuccess: () => {
      setShowForm(false);
      setForm(emptyBranch);
      setError(null);
      queryClient.invalidateQueries({ queryKey: ["companies"] });
    },
    onError: (problem) => setError(problemMessage(problem)),
  });

  const toggle = useMutation({
    mutationFn: async (branch: Branch) => {
      const { error: problem } = await rawApi.PATCH(`/v1/admin/branches/${branch.id}`, { body: { is_active: !branch.is_active } });
      if (problem) throw problem;
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["companies"] }),
  });

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-start">
        <p className="text-[13px] text-ink-3">Agences de {company.legal_name} — le code agence sert dans les références dossier.</p>
        {canCreate && (
          <button onClick={() => setShowForm((v) => !v)} className={`ml-auto ${buttonPrimary}`}>+ Nouvelle agence</button>
        )}
      </div>

      {showForm && (
        <form onSubmit={(e) => { e.preventDefault(); create.mutate(); }} className="grid gap-4 rounded-xl border border-line bg-surface p-5 shadow-sm md:grid-cols-3">
          <Field label="Nom de l'agence" className="md:col-span-2">
            <input required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="Agence Abidjan" className={inputClass} />
          </Field>
          <Field label="Code (max 8)">
            <input required maxLength={8} value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, "") })} placeholder="ABJ" className={`${inputClass} mono`} />
          </Field>
          <Field label="Fuseau horaire">
            <select value={form.timezone} onChange={(e) => setForm({ ...form, timezone: e.target.value })} className={inputClass}>
              {TIMEZONES.map((tz) => <option key={tz} value={tz}>{tz}</option>)}
            </select>
          </Field>
          <Field label="Téléphone">
            <input value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} className={`${inputClass} mono`} />
          </Field>
          <Field label="Email">
            <input type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} className={inputClass} />
          </Field>
          {error && <p className="rounded-lg bg-crit-soft px-3 py-2 text-xs text-crit md:col-span-3">{error}</p>}
          <div className="flex gap-2 md:col-span-3">
            <button type="submit" disabled={create.isPending} className={buttonPrimary}>Créer l&apos;agence</button>
            <button type="button" onClick={() => setShowForm(false)} className={buttonSecondary}>Annuler</button>
          </div>
        </form>
      )}

      <div className="overflow-x-auto rounded-xl border border-line bg-surface shadow-sm">
        <table className="w-full text-[13px]">
          <thead>
            <tr className="border-b border-line text-left text-[10px] uppercase tracking-wider text-ink-3">
              <th className="px-3 py-2.5">Code</th>
              <th className="px-3 py-2.5">Nom</th>
              <th className="px-3 py-2.5">Fuseau</th>
              <th className="px-3 py-2.5">Téléphone</th>
              <th className="px-3 py-2.5">Email</th>
              <th className="px-3 py-2.5">Statut</th>
              <th className="px-3 py-2.5" />
            </tr>
          </thead>
          <tbody>
            {company.branches.map((branch) => (
              <tr key={branch.id} className="border-b border-line last:border-0">
                <td className="mono px-3 py-2.5 font-semibold text-sea">{branch.code}</td>
                <td className="px-3 py-2.5">{branch.name}</td>
                <td className="px-3 py-2.5 text-ink-2">{branch.timezone}</td>
                <td className="mono px-3 py-2.5 text-ink-2">{branch.phone ?? "—"}</td>
                <td className="px-3 py-2.5 text-ink-2">{branch.email ?? "—"}</td>
                <td className="px-3 py-2.5">
                  <span className={`rounded-full px-2.5 py-0.5 text-[11px] font-semibold ${branch.is_active ? "bg-ok-soft text-ok" : "bg-warn-soft text-warn"}`}>
                    {branch.is_active ? "Active" : "Inactive"}
                  </span>
                </td>
                <td className="px-3 py-2.5">
                  {canCreate && (
                    <button onClick={() => toggle.mutate(branch)} className="text-xs font-semibold text-sea hover:underline">
                      {branch.is_active ? "Désactiver" : "Activer"}
                    </button>
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

export default function SettingsPage() {
  const canUpdate = useCan("companies.update");
  const canCreateBranch = useCan("branches.create");
  const [tab, setTab] = useState<"company" | "branches">("company");

  const { data: companies } = useQuery({
    queryKey: ["companies"],
    queryFn: async () => {
      const { data } = await rawApi.GET("/v1/admin/companies");
      return data as Company[];
    },
  });

  const company = companies?.[0];
  if (!company) return <p className="py-8 text-center text-ink-3">Chargement…</p>;

  return (
    <div className="flex max-w-4xl flex-col gap-4">
      <div>
        <h1 className="text-xl font-bold">Paramètres</h1>
        <p className="text-[13px] text-ink-3">Société, numérotation, agences et identité visuelle</p>
      </div>

      <div className="flex gap-2">
        {([["company", "Société"], ["branches", "Agences"]] as const).map(([key, label]) => (
          <button
            key={key}
            onClick={() => setTab(key)}
            className={`rounded-full border px-3.5 py-1 text-xs font-semibold ${
              tab === key ? "border-ink bg-ink text-paper" : "border-line-strong text-ink-2 hover:bg-surface"
            }`}
          >
            {label}
          </button>
        ))}
      </div>

      {tab === "company" ? (
        <CompanyTab company={company} canUpdate={canUpdate} />
      ) : (
        <BranchesTab company={company} canCreate={canCreateBranch} />
      )}
    </div>
  );
}
