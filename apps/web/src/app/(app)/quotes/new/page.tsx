"use client";

import { useMutation, useQuery } from "@tanstack/react-query";
import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { problemMessage, rawApi } from "@/lib/api";
import { Field, buttonPrimary, buttonSecondary, inputClass } from "@/components/Field";
import { PlaceCombobox } from "@/components/PlaceCombobox";
import { useAuth } from "@/stores/auth";

interface CalculatedLine {
  serviceCode: string;
  description: string;
  unit: string;
  quantity: number;
  unitPrice: number;
  total: number;
  currency: string;
  minimumApplied: boolean;
  buyTotal: number | null;
}

/** Ligne éditable du devis — issue de la simulation ou saisie à la main. */
/** Deux familles de débours, deux sous-totaux — comme sur l'offre type. */
type LineCategory = "customs" | "other";

interface QuoteLine {
  category: LineCategory;
  service_code: string;
  description: string;
  unit: string;
  quantity: string;
  unit_price: string;
  buy_price: string;
  // Le fret se cote souvent en devise étrangère quand les prestations locales
  // restent en monnaie du pays : chaque ligne garde donc la sienne.
  currency_code: string;
  minimumApplied?: boolean;
}

interface UserBranch {
  company_id: string;
  company_name: string | null;
}

const UNITS = [
  ["container", "conteneur"],
  ["kg", "kg"],
  ["m3", "m³"],
  ["wm", "u. payante"],
  ["flat", "forfait"],
  ["percent", "%"],
  ["unit", "unité"],
] as const;

function blankLine(currency: string, category: LineCategory = "other"): QuoteLine {
  return {
    category, service_code: "", description: "", unit: "flat", quantity: "1",
    unit_price: "", buy_price: "", currency_code: currency,
  };
}

/**
 * Trame de l'offre de transit maritime import, reprise de l'offre type du
 * transitaire. Proposée d'emblée pour qu'aucun poste ne soit oublié : un débours
 * omis à la cotation se facture ensuite sans avoir été annoncé.
 */
const IMPORT_TEMPLATE: { category: LineCategory; code: string; label: string }[] = [
  { category: "customs", code: "DD", label: "Droit de douane" },
  { category: "customs", code: "RSTA", label: "RSTA" },
  { category: "customs", code: "PCS", label: "PCS" },
  { category: "customs", code: "PUA", label: "PUA" },
  { category: "customs", code: "PCC", label: "PCC" },
  { category: "customs", code: "RPI", label: "RPI" },
  { category: "customs", code: "TVA", label: "TVA" },
  { category: "customs", code: "TS_SYDAM", label: "TS + Sydam" },
  { category: "other", code: "OUVERTURE", label: "Ouverture" },
  { category: "other", code: "FDI_RFCV", label: "FDI/RFCV" },
  { category: "other", code: "ASSURANCE", label: "Assurance" },
  { category: "other", code: "TIRAGE", label: "Tirage" },
  { category: "other", code: "PASSAGE", label: "Passage" },
  { category: "other", code: "AGIO", label: "Agio/Gestion crédit" },
  { category: "other", code: "AMENDE_BSC", label: "Amende BSC" },
  { category: "other", code: "VISITE", label: "Visite" },
  { category: "other", code: "ACCONAGE", label: "Acconage" },
  { category: "other", code: "CAUTION", label: "Caution" },
  { category: "other", code: "ECHANGE_BL", label: "Echange BL" },
  { category: "other", code: "LIVRAISON", label: "Livraison" },
  { category: "other", code: "COMMISSION", label: "Commission de facilitation" },
  { category: "other", code: "PRESTATIONS", label: "Prestations" },
];

const CATEGORY_LABEL: Record<LineCategory, string> = {
  customs: "Débours douane",
  other: "Débours divers",
};

function templateLines(currency: string): QuoteLine[] {
  return IMPORT_TEMPLATE.map((entry) => ({
    ...blankLine(currency, entry.category),
    service_code: entry.code,
    description: entry.label,
  }));
}

function inNinetyDays(): string {
  const date = new Date();
  date.setDate(date.getDate() + 30);

  return date.toISOString().slice(0, 10);
}

export default function NewQuotePage() {
  const router = useRouter();
  const user = useAuth((state) => state.user);
  const [form, setForm] = useState({
    mode: "sea_fcl",
    direction: "import",
    origin_locode: "CNSHA",
    destination_locode: "CIABJ",
    container_type: "40HC",
    container_qty: "2",
    gross_weight_kg: "44000",
    volume_m3: "54",
    declared_value: "",
  });
  const [deal, setDeal] = useState({
    party_id: "",
    company_id: "",
    incoterm_code: "CIF",
    currency_code: "XOF",
    valid_until: inNinetyDays(),
  });
  const [lines, setLines] = useState<QuoteLine[]>([]);
  // Position tarifaire, valeur en douane et régime : de quoi chiffrer les
  // débours douane au lieu de les saisir.
  const [customs, setCustoms] = useState({ hs_code: "", customs_value: "", customs_regime: "IM4" });
  const [customsInfo, setCustomsInfo] = useState<string | null>(null);
  const [calculated, setCalculated] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);

  const { data: clients } = useQuery({
    queryKey: ["parties", "clients"],
    queryFn: async () => {
      const { data } = await rawApi.GET("/v1/parties", { params: { query: { type: "client", per_page: 100 } } });
      return (data as { data: { id: string; name: string; code: string }[] }).data;
    },
  });
  const { data: incoterms } = useQuery({
    queryKey: ["incoterms"],
    queryFn: async () => {
      const { data } = await rawApi.GET("/v1/referentials/incoterms", { params: { query: { per_page: 20 } } });
      return (data as { data: { code: string; label: string }[] }).data;
    },
  });
  const { data: regimes } = useQuery({
    queryKey: ["customs-regimes"],
    queryFn: async () => {
      const { data } = await rawApi.GET("/v1/customs-regimes");
      return (data as { data: { code: string; name: string; note: string | null; is_suspensive: boolean }[] }).data;
    },
  });

  const { data: currencies } = useQuery({
    queryKey: ["currencies"],
    queryFn: async () => {
      const { data } = await rawApi.GET("/v1/referentials/currencies", { params: { query: { per_page: 50 } } });
      return (data as { data: { code: string; name: string }[] }).data;
    },
  });
  // Société émettrice : celle des agences de rattachement, pas l'administration
  // — un commercial cote sans droit sur les paramètres de la société.
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
  const onlyCompany = companies.length === 1 ? companies[0] : undefined;
  useEffect(() => {
    if (onlyCompany && deal.company_id === "") setDeal((state) => ({ ...state, company_id: onlyCompany.id }));
  }, [onlyCompany, deal.company_id]);

  function set(key: string, value: string) {
    setForm((state) => ({ ...state, [key]: value }));
  }

  // Le maritime FCL à l'import suit une trame connue : la proposer d'emblée
  // évite d'oublier un poste, sans empêcher d'en ajouter ou d'en retirer.
  useEffect(() => {
    const applies = form.mode === "sea_fcl" && form.direction === "import";
    if (applies && lines.length === 0 && !calculated) {
      setLines(templateLines(deal.currency_code));
    }
  }, [form.mode, form.direction, lines.length, calculated, deal.currency_code]);

  function setLine(index: number, key: keyof QuoteLine, value: string) {
    setLines((state) => state.map((line, i) => (i === index ? { ...line, [key]: value } : line)));
  }

  async function calculate(event: React.FormEvent) {
    event.preventDefault();
    setLoading(true);
    setError(null);
    const { data, error: problem } = await rawApi.POST("/v1/quotes/calculate", {
      body: {
        mode: form.mode,
        origin_locode: form.origin_locode.toUpperCase(),
        destination_locode: form.destination_locode.toUpperCase(),
        containers: form.container_qty ? { [form.container_type]: Number(form.container_qty) } : {},
        gross_weight_kg: Number(form.gross_weight_kg) || 0,
        volume_m3: Number(form.volume_m3) || 0,
        declared_value: Number(form.declared_value) || 0,
      },
    });
    setLoading(false);
    if (problem) return setError(problemMessage(problem));

    const computed = (data as { lines: CalculatedLine[] }).lines;
    setCalculated(true);
    setLines(computed.map((line) => ({
      // La grille tarifaire chiffre des prestations, pas des droits de douane.
      category: "other" as const,
      service_code: line.serviceCode,
      description: line.description,
      unit: line.unit,
      quantity: String(line.quantity),
      unit_price: String(line.unitPrice),
      buy_price: line.buyTotal !== null && line.quantity > 0 ? String(line.buyTotal / line.quantity) : "",
      currency_code: line.currency,
      minimumApplied: line.minimumApplied,
    })));
  }

  /** Chiffre les huit lignes de débours douane depuis le tarif officiel. */
  const computeDuties = useMutation({
    mutationFn: async () => {
      const { data, error: problem } = await rawApi.POST("/v1/customs-tariffs/compute", {
        body: {
          hs_code: customs.hs_code,
          customs_value: Number(customs.customs_value) || 0,
          customs_regime: customs.customs_regime,
        },
      });
      if (problem) throw problem;
      return data as {
        hs_code: string; description: string; regime_name: string | null;
        lines: Record<string, number>; total: number;
      };
    },
    onSuccess: (result) => {
      // Les montants remplacent les lignes douane ; celles saisies à la main
      // dans les débours divers ne bougent pas.
      setLines((state) => state.map((line) => line.category === "customs" && result.lines[line.service_code] !== undefined
        ? { ...line, unit_price: String(result.lines[line.service_code]), quantity: "1" }
        : line));
      setCustomsInfo(`${result.hs_code} — ${result.description.slice(0, 70)}${result.regime_name ? ` · ${result.regime_name}` : ""}`);
    },
    onError: (problem) => setCustomsInfo(problemMessage(problem)),
  });

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    if (!user) return;
    setSaving(true);
    setError(null);
    const { error: problem } = await rawApi.POST("/v1/quotes", {
      body: {
        company_id: deal.company_id,
        party_id: deal.party_id,
        owner_id: user.id,
        mode: form.mode,
        direction: form.direction,
        origin_locode: form.origin_locode.toUpperCase(),
        destination_locode: form.destination_locode.toUpperCase(),
        incoterm_code: deal.incoterm_code,
        currency_code: deal.currency_code,
        valid_until: deal.valid_until,
        cargo_summary: {
          containers: form.container_qty ? { [form.container_type]: Number(form.container_qty) } : {},
          gross_weight_kg: Number(form.gross_weight_kg) || 0,
          volume_m3: Number(form.volume_m3) || 0,
        },
        hs_code: customs.hs_code || null,
        customs_value: Number(customs.customs_value) || null,
        customs_regime: customs.customs_regime || null,
        lines: lines.map((line) => ({
          category: line.category,
          service_code: line.service_code || "SERVICE",
          description: line.description,
          quantity: Number(line.quantity) || 0,
          unit: line.unit,
          unit_price: Number(line.unit_price) || 0,
          currency_code: line.currency_code,
          buy_price: line.buy_price === "" ? null : Number(line.buy_price),
        })),
      },
    });
    setSaving(false);
    if (problem) return setError(problemMessage(problem));
    router.push("/quotes");
  }

  const money = (value: number) => value.toLocaleString("fr-FR", { maximumFractionDigits: 0 });

  /** Sous-total d'une famille de débours — les deux blocs de l'offre type. */
  const subtotal = (category: LineCategory) => lines
    .filter((line) => line.category === category)
    .reduce((sum, line) => sum + (Number(line.quantity) || 0) * (Number(line.unit_price) || 0), 0);
  const totals = lines.reduce<Record<string, { sell: number; buy: number }>>((acc, line) => {
    const quantity = Number(line.quantity) || 0;
    acc[line.currency_code] ??= { sell: 0, buy: 0 };
    acc[line.currency_code]!.sell += quantity * (Number(line.unit_price) || 0);
    acc[line.currency_code]!.buy += quantity * (Number(line.buy_price) || 0);

    return acc;
  }, {});

  return (
    <div className="mx-auto flex w-full max-w-5xl flex-col gap-5">
      <div>
        <h1 className="text-xl font-bold">Nouvelle cotation</h1>
        <p className="text-[13px] text-ink-3">
          Les grilles tarifaires proposent les lignes ; vous les ajustez, puis vous émettez le devis.
        </p>
      </div>

      <form onSubmit={calculate} className="grid gap-4 rounded-xl border border-line bg-surface p-5 shadow-sm md:grid-cols-4">
        <p className="text-[13px] font-bold md:col-span-4">1 · Trajet et marchandise</p>
        <Field label="Mode">
          <select value={form.mode} onChange={(e) => set("mode", e.target.value)} className={inputClass}>
            <option value="sea_fcl">Maritime FCL</option>
            <option value="sea_lcl">Maritime LCL</option>
            <option value="air">Aérien</option>
            <option value="road">Terrestre</option>
          </select>
        </Field>
        <Field label="Sens">
          <select value={form.direction} onChange={(e) => set("direction", e.target.value)} className={inputClass}>
            <option value="import">Import</option>
            <option value="export">Export</option>
            <option value="transit">Transit</option>
          </select>
        </Field>
        <Field label="Origine">
          <PlaceCombobox referential="ports" value={form.origin_locode} onChange={(v) => set("origin_locode", v)} placeholder="Port ou LOCODE" maxLength={5} />
        </Field>
        <Field label="Destination">
          <PlaceCombobox referential="ports" value={form.destination_locode} onChange={(v) => set("destination_locode", v)} placeholder="Port ou LOCODE" maxLength={5} />
        </Field>
        <Field label="Conteneurs">
          <div className="flex gap-1.5">
            <select value={form.container_type} onChange={(e) => set("container_type", e.target.value)} className={inputClass}>
              {["20GP", "40GP", "40HC", "45HC", "20RF", "40RF"].map((type) => <option key={type}>{type}</option>)}
            </select>
            <input type="number" min={0} value={form.container_qty} onChange={(e) => set("container_qty", e.target.value)} className={`${inputClass} w-16`} />
          </div>
        </Field>
        <Field label="Poids brut (kg)">
          <input type="number" min={0} value={form.gross_weight_kg} onChange={(e) => set("gross_weight_kg", e.target.value)} className={`${inputClass} mono`} />
        </Field>
        <Field label="Volume (m³)">
          <input type="number" min={0} step="0.1" value={form.volume_m3} onChange={(e) => set("volume_m3", e.target.value)} className={`${inputClass} mono`} />
        </Field>
        <Field label="Valeur déclarée (assurance)">
          <input type="number" min={0} value={form.declared_value} onChange={(e) => set("declared_value", e.target.value)} className={`${inputClass} mono`} />
        </Field>
        <div className="flex items-end">
          <button type="submit" disabled={loading} className={`w-full ${buttonSecondary}`}>
            {loading ? "Calcul…" : "Proposer depuis les tarifs"}
          </button>
        </div>
      </form>

      <form onSubmit={submit} className="flex flex-col gap-5">
        {form.direction === "import" && (
          <section className="grid gap-4 rounded-xl border border-line bg-surface p-5 shadow-sm md:grid-cols-4">
            <p className="text-[13px] font-bold md:col-span-4">
              2 · Douane
              <span className="ml-2 font-normal text-ink-3">
                le tarif chiffre les droits, le régime dit s&apos;ils sont dus
              </span>
            </p>
            <Field label="Position tarifaire">
              <input
                value={customs.hs_code}
                onChange={(event) => setCustoms({ ...customs, hs_code: event.target.value })}
                placeholder="8703.23.00.00"
                className={`${inputClass} mono`}
              />
            </Field>
            <Field label="Valeur en douane (CAF)">
              <input
                type="number"
                min={0}
                value={customs.customs_value}
                onChange={(event) => setCustoms({ ...customs, customs_value: event.target.value })}
                className={`${inputClass} mono`}
              />
            </Field>
            <Field label="Régime douanier">
              <select
                value={customs.customs_regime}
                onChange={(event) => setCustoms({ ...customs, customs_regime: event.target.value })}
                className={inputClass}
              >
                {regimes?.map((regime) => (
                  <option key={regime.code} value={regime.code}>{regime.code} — {regime.name}</option>
                ))}
              </select>
            </Field>
            <div className="flex items-end">
              <button
                type="button"
                onClick={() => computeDuties.mutate()}
                disabled={computeDuties.isPending || customs.hs_code === "" || customs.customs_value === ""}
                className={`w-full ${buttonSecondary}`}
              >
                {computeDuties.isPending ? "Calcul…" : "Chiffrer les droits"}
              </button>
            </div>
            {customsInfo && <p className="text-xs text-ink-3 md:col-span-4">{customsInfo}</p>}
            {regimes?.find((regime) => regime.code === customs.customs_regime)?.note && (
              <p className="rounded-lg bg-paper px-3 py-2 text-xs text-ink-2 md:col-span-4">
                {regimes.find((regime) => regime.code === customs.customs_regime)?.note}
              </p>
            )}
          </section>
        )}

        <section className="rounded-xl border border-line bg-surface p-5 shadow-sm">
          <div className="flex items-center pb-3">
            <p className="text-[13px] font-bold">3 · Lignes du devis</p>
            <div className="ml-auto flex gap-3">
              <button type="button" onClick={() => setLines((state) => [...state, blankLine(deal.currency_code, "customs")])} className="text-xs font-semibold text-sea hover:underline">
                + Débours douane
              </button>
              <button type="button" onClick={() => setLines((state) => [...state, blankLine(deal.currency_code, "other")])} className="text-xs font-semibold text-sea hover:underline">
                + Débours divers
              </button>
            </div>
          </div>

          {calculated && lines.length === 0 && (
            <p className="rounded-lg bg-warn-soft px-3 py-2 text-xs text-warn">
              Aucune grille tarifaire ne couvre ce trajet. Ajoutez les lignes à la main — ou créez la grille
              correspondante pour que les prochaines cotations se remplissent seules.
            </p>
          )}
          {!calculated && lines.length === 0 && (
            <p className="text-xs text-ink-3">
              Lancez la proposition tarifaire ci-dessus, ou ajoutez directement vos lignes.
            </p>
          )}

          {lines.length > 0 && (
            <div className="overflow-x-auto">
              <table className="w-full text-[13px]">
                <thead>
                  <tr className="border-b border-line text-left text-[10px] uppercase tracking-wider text-ink-3">
                    <th className="py-2 pr-2">Désignation</th>
                    <th className="px-2 py-2">Unité</th>
                    <th className="px-2 py-2 text-right">Qté</th>
                    <th className="px-2 py-2 text-right">PU vente</th>
                    <th className="px-2 py-2 text-right">PU achat</th>
                    <th className="px-2 py-2">Devise</th>
                    <th className="px-2 py-2 text-right">Total</th>
                    <th className="py-2" />
                  </tr>
                </thead>
                {(["customs", "other"] as LineCategory[]).map((category) => {
                  const indexes = lines
                    .map((line, index) => ({ line, index }))
                    .filter((entry) => entry.line.category === category);
                  if (indexes.length === 0) return null;

                  return (
                <tbody key={category}>
                  <tr>
                    <td colSpan={8} className="pt-4 pb-1 text-[11px] font-bold uppercase tracking-wider text-ink-2">
                      {CATEGORY_LABEL[category]}
                    </td>
                  </tr>
                  {indexes.map(({ line, index }) => (
                    <tr key={index} className="border-b border-line last:border-0">
                      <td className="py-1.5 pr-2">
                        <input required value={line.description} onChange={(e) => setLine(index, "description", e.target.value)} placeholder="Fret maritime" className={`${inputClass} w-full`} />
                        {line.minimumApplied && <span className="mt-1 inline-block rounded bg-warn-soft px-1.5 text-[10px] text-warn">minimum appliqué</span>}
                      </td>
                      <td className="px-2 py-1.5">
                        <select value={line.unit} onChange={(e) => setLine(index, "unit", e.target.value)} className={inputClass}>
                          {UNITS.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                        </select>
                      </td>
                      <td className="px-2 py-1.5">
                        <input type="number" min={0} step="0.001" required value={line.quantity} onChange={(e) => setLine(index, "quantity", e.target.value)} className={`${inputClass} mono w-20 text-right`} />
                      </td>
                      <td className="px-2 py-1.5">
                        <input type="number" min={0} required value={line.unit_price} onChange={(e) => setLine(index, "unit_price", e.target.value)} className={`${inputClass} mono w-28 text-right`} />
                      </td>
                      <td className="px-2 py-1.5">
                        <input type="number" min={0} value={line.buy_price} onChange={(e) => setLine(index, "buy_price", e.target.value)} placeholder="—" className={`${inputClass} mono w-28 text-right`} />
                      </td>
                      <td className="px-2 py-1.5">
                        <select value={line.currency_code} onChange={(e) => setLine(index, "currency_code", e.target.value)} className={inputClass}>
                          {currencies?.map((currency) => <option key={currency.code} value={currency.code}>{currency.code}</option>)}
                        </select>
                      </td>
                      <td className="mono px-2 py-1.5 text-right font-semibold">
                        {money((Number(line.quantity) || 0) * (Number(line.unit_price) || 0))}
                      </td>
                      <td className="py-1.5 pl-2">
                        <button type="button" onClick={() => setLines((state) => state.filter((_, i) => i !== index))} className="text-xs font-semibold text-crit hover:underline">
                          Retirer
                        </button>
                      </td>
                    </tr>
                  ))}
                  <tr className="border-t border-line-strong">
                    <td colSpan={6} className="py-2 text-right text-[11px] font-bold uppercase tracking-wider text-ink-2">
                      Total {CATEGORY_LABEL[category].toLowerCase()}
                    </td>
                    <td className="mono px-2 py-2 text-right font-bold">
                      {money(subtotal(category))}
                    </td>
                    <td />
                  </tr>
                </tbody>
                  );
                })}
              </table>
              <div className="mt-3 flex flex-wrap items-center gap-5 rounded-lg bg-paper px-4 py-3 text-[13px]">
                <span className="text-[11px] font-bold uppercase tracking-wider text-ink-2">Net à payer</span>
                <span className="mono text-base font-bold">
                  {money(subtotal("customs") + subtotal("other"))} {deal.currency_code}
                </span>
              </div>

              <div className="flex flex-wrap gap-5 border-t border-line pt-3 text-[13px]">
                {Object.entries(totals).map(([currency, total]) => (
                  <span key={currency}>
                    <span className="text-ink-3">Total {currency} : </span>
                    <span className="mono font-bold">{money(total.sell)}</span>
                    {total.buy > 0 && <span className="ml-1.5 text-ok">(marge {money(total.sell - total.buy)})</span>}
                  </span>
                ))}
              </div>
            </div>
          )}
        </section>

        <section className="grid gap-4 rounded-xl border border-line bg-surface p-5 shadow-sm md:grid-cols-4">
          <p className="text-[13px] font-bold md:col-span-4">4 · Client et validité</p>
          <Field label="Client" className="md:col-span-2">
            <select required value={deal.party_id} onChange={(e) => setDeal({ ...deal, party_id: e.target.value })} className={inputClass}>
              <option value="">— Sélectionner —</option>
              {clients?.map((client) => <option key={client.id} value={client.id}>{client.name}</option>)}
            </select>
          </Field>
          <Field label="Société émettrice">
            <select required value={deal.company_id} onChange={(e) => setDeal({ ...deal, company_id: e.target.value })} className={inputClass}>
              <option value="">— Sélectionner —</option>
              {companies.map((company) => <option key={company.id} value={company.id}>{company.name}</option>)}
            </select>
          </Field>
          <Field label="Incoterm">
            <select value={deal.incoterm_code} onChange={(e) => setDeal({ ...deal, incoterm_code: e.target.value })} className={inputClass}>
              {incoterms?.map((incoterm) => <option key={incoterm.code} value={incoterm.code}>{incoterm.code} — {incoterm.label}</option>)}
            </select>
          </Field>
          <Field label="Devise">
            <select value={deal.currency_code} onChange={(e) => setDeal({ ...deal, currency_code: e.target.value })} className={inputClass}>
              {currencies?.map((currency) => <option key={currency.code} value={currency.code}>{currency.code} — {currency.name}</option>)}
            </select>
          </Field>
          <Field label="Valable jusqu'au">
            <input type="date" required value={deal.valid_until} onChange={(e) => setDeal({ ...deal, valid_until: e.target.value })} className={inputClass} />
          </Field>
        </section>

        {error && <p className="rounded-lg bg-crit-soft px-4 py-2.5 text-[13px] text-crit">{error}</p>}

        <div className="flex justify-end">
          <button type="submit" disabled={saving || lines.length === 0} className={buttonPrimary}>
            {saving ? "Création…" : "Créer la cotation"}
          </button>
        </div>
      </form>
    </div>
  );
}
