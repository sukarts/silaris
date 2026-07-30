"use client";

import { useMutation, useQuery } from "@tanstack/react-query";
import { useRouter } from "next/navigation";
import { useEffect, useMemo, useState } from "react";
import { problemMessage, rawApi } from "@/lib/api";
import { Field, buttonPrimary, buttonSecondary, inputClass } from "@/components/Field";
import { ServiceCatalogDatalist, useServiceCatalog } from "@/components/ServiceCatalog";

interface Branch { company_id: string; company_name: string }
interface TaxRate { id: string; name: string; rate_percent: string }
interface Line {
  service_code: string;
  description: string;
  quantity: string;
  unit: string;
  unit_price: string;
  tax_rate_id: string;
}

const UNITS = ["container", "kg", "m3", "wm", "flat", "percent", "unit"];
const blankLine = (): Line => ({ service_code: "", description: "", quantity: "1", unit: "flat", unit_price: "0", tax_rate_id: "" });
const money = (n: number, cur: string) => `${new Intl.NumberFormat("fr-FR", { maximumFractionDigits: 0 }).format(n)} ${cur}`;

export default function NewInvoicePage() {
  const router = useRouter();
  const [companyId, setCompanyId] = useState("");
  const [partyId, setPartyId] = useState("");
  const [type, setType] = useState("invoice");
  const [currency, setCurrency] = useState("XOF");
  const [lines, setLines] = useState<Line[]>([blankLine()]);
  const [error, setError] = useState<string | null>(null);
  const catalog = useServiceCatalog();

  const { data: branches } = useQuery({
    queryKey: ["auth", "me", "branches"],
    queryFn: async () => {
      const { data } = await rawApi.GET("/v1/auth/me");
      return (data as { branches: Branch[] } | undefined)?.branches ?? [];
    },
  });
  const { data: clients } = useQuery({
    queryKey: ["parties", "clients"],
    queryFn: async () => {
      const { data } = await rawApi.GET("/v1/parties", { params: { query: { type: "client", per_page: 100 } } });
      return (data as { data: { id: string; name: string; code: string }[] }).data;
    },
  });
  const { data: taxRates } = useQuery({
    queryKey: ["tax-rates"],
    queryFn: async () => {
      const { data } = await rawApi.GET("/v1/tax-rates");
      return data as TaxRate[];
    },
  });

  const companies = useMemo(
    () => Array.from(new Map((branches ?? []).map((b) => [b.company_id, b.company_name])).entries()).map(([id, name]) => ({ id, name })),
    [branches],
  );
  useEffect(() => {
    if (companies.length === 1 && companyId === "") setCompanyId(companies[0]!.id);
  }, [companies, companyId]);

  const rateOf = (id: string) => Number(taxRates?.find((r) => r.id === id)?.rate_percent ?? 0);
  const totals = lines.reduce(
    (acc, l) => {
      const ht = (Number(l.quantity) || 0) * (Number(l.unit_price) || 0);
      const tax = l.tax_rate_id ? (ht * rateOf(l.tax_rate_id)) / 100 : 0;
      return { ht: acc.ht + ht, tax: acc.tax + tax };
    },
    { ht: 0, tax: 0 },
  );

  const update = (i: number, patch: Partial<Line>) => setLines((s) => s.map((l, idx) => (idx === i ? { ...l, ...patch } : l)));

  const create = useMutation({
    mutationFn: async () => {
      const { data, error: problem } = await rawApi.POST("/v1/invoices", {
        body: {
          company_id: companyId,
          type,
          party_id: partyId,
          currency_code: currency,
          lines: lines.map((l) => ({
            service_code: l.service_code || "DIVERS",
            description: l.description,
            quantity: Number(l.quantity) || 0,
            unit: l.unit,
            unit_price: Number(l.unit_price) || 0,
            tax_rate_id: l.tax_rate_id || null,
          })),
        },
      });
      if (problem) throw problem;
      return data as { id: string };
    },
    onSuccess: () => router.push("/billing"),
    onError: (problem) => setError(problemMessage(problem)),
  });

  const ready = companyId && partyId && lines.length > 0 && lines.every((l) => l.description.trim() !== "");

  return (
    <div className="flex flex-col gap-4">
      <div>
        <button onClick={() => router.push("/billing")} className="text-[12px] text-ink-3 hover:underline">← Facturation</button>
        <h1 className="mt-1 text-xl font-bold">Nouvelle facture</h1>
        <p className="text-[13px] text-ink-3">Établie et numérotée dans SILARIS. Pour facturer un accord client, partez plutôt d&apos;une cotation acceptée.</p>
      </div>

      {error && <div className="rounded-lg bg-crit-soft px-3 py-2 text-[13px] text-crit">{error}</div>}

      <div className="grid gap-4 rounded-xl border border-line bg-surface p-5 shadow-sm md:grid-cols-4">
        <Field label="Société">
          <select value={companyId} onChange={(e) => setCompanyId(e.target.value)} className={inputClass}>
            <option value="">—</option>
            {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </select>
        </Field>
        <Field label="Client">
          <select value={partyId} onChange={(e) => setPartyId(e.target.value)} className={inputClass}>
            <option value="">—</option>
            {(clients ?? []).map((c) => <option key={c.id} value={c.id}>{c.name} ({c.code})</option>)}
          </select>
        </Field>
        <Field label="Type">
          <select value={type} onChange={(e) => setType(e.target.value)} className={inputClass}>
            <option value="invoice">Facture</option>
            <option value="proforma">Proforma</option>
          </select>
        </Field>
        <Field label="Devise">
          <select value={currency} onChange={(e) => setCurrency(e.target.value)} className={inputClass}>
            {["XOF", "EUR", "USD"].map((c) => <option key={c} value={c}>{c}</option>)}
          </select>
        </Field>
      </div>

      <div className="flex flex-col gap-3 rounded-xl border border-line bg-surface p-4 shadow-sm">
        <ServiceCatalogDatalist items={catalog.items} />
        <div className="overflow-x-auto">
          <table className="w-full text-[13px]">
            <thead>
              <tr className="border-b border-line text-left text-[10px] uppercase tracking-wider text-ink-3">
                <th className="px-2 py-2">Désignation</th>
                <th className="px-2 py-2 text-right">Quantité</th>
                <th className="px-2 py-2">Unité</th>
                <th className="px-2 py-2 text-right">P.U.</th>
                <th className="px-2 py-2">TVA</th>
                <th className="px-2 py-2 text-right">Total HT</th>
                <th className="px-2 py-2" />
              </tr>
            </thead>
            <tbody>
              {lines.map((line, i) => (
                <tr key={i} className="border-b border-line last:border-0">
                  <td className="px-2 py-1.5">
                    <input
                      list="service-catalog" value={line.description}
                      onChange={(e) => {
                        const item = catalog.resolve(e.target.value);
                        // Choisir un poste connu renseigne son code ; la saisie
                        // libre reste possible et garde le code déjà là.
                        update(i, item ? { description: item.label, service_code: item.code } : { description: e.target.value });
                      }}
                      className={`${inputClass} !py-1`}
                    />
                  </td>
                  <td className="px-2 py-1.5"><input type="number" min="0" step="0.001" value={line.quantity} onChange={(e) => update(i, { quantity: e.target.value })} className={`${inputClass} mono !py-1 text-right`} /></td>
                  <td className="px-2 py-1.5">
                    <select value={line.unit} onChange={(e) => update(i, { unit: e.target.value })} className={`${inputClass} !py-1`}>
                      {UNITS.map((u) => <option key={u} value={u}>{u}</option>)}
                    </select>
                  </td>
                  <td className="px-2 py-1.5"><input type="number" min="0" step="1" value={line.unit_price} onChange={(e) => update(i, { unit_price: e.target.value })} className={`${inputClass} mono !py-1 text-right`} /></td>
                  <td className="px-2 py-1.5">
                    <select value={line.tax_rate_id} onChange={(e) => update(i, { tax_rate_id: e.target.value })} className={`${inputClass} !py-1`}>
                      <option value="">Aucune</option>
                      {(taxRates ?? []).map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
                    </select>
                  </td>
                  <td className="mono px-2 py-1.5 text-right">{money((Number(line.quantity) || 0) * (Number(line.unit_price) || 0), currency)}</td>
                  <td className="px-2 py-1.5 text-right"><button onClick={() => setLines((s) => s.filter((_, idx) => idx !== i))} className="text-[12px] text-crit hover:underline">Retirer</button></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <div className="flex flex-wrap items-center gap-3">
          <button onClick={() => setLines((s) => [...s, blankLine()])} className="rounded-lg border border-line-strong px-2.5 py-1 text-[12px] hover:bg-paper">+ Ligne</button>
          <span className="ml-auto text-[13px] text-ink-2">HT <span className="mono font-semibold">{money(totals.ht, currency)}</span></span>
          <span className="text-[13px] text-ink-2">TVA <span className="mono font-semibold">{money(totals.tax, currency)}</span></span>
          <span className="text-[13px]">TTC <span className="mono font-bold">{money(totals.ht + totals.tax, currency)}</span></span>
        </div>
      </div>

      <div className="flex gap-2">
        <button onClick={() => { setError(null); create.mutate(); }} disabled={!ready || create.isPending} className={buttonPrimary}>
          {create.isPending ? "Création…" : "Créer le brouillon"}
        </button>
        <button onClick={() => router.push("/billing")} className={buttonSecondary}>Annuler</button>
      </div>
    </div>
  );
}
