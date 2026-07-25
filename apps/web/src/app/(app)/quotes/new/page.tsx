"use client";

import { useState } from "react";
import { problemMessage, rawApi } from "@/lib/api";
import { Field, buttonPrimary, buttonSecondary, inputClass } from "@/components/Field";

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

export default function NewQuotePage() {
  const [form, setForm] = useState({
    mode: "sea_fcl",
    origin_locode: "CNSHA",
    destination_locode: "CIABJ",
    container_type: "40HC",
    container_qty: "2",
    gross_weight_kg: "44000",
    volume_m3: "54",
    declared_value: "",
  });
  const [lines, setLines] = useState<CalculatedLine[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  function set(key: string, value: string) {
    setForm((state) => ({ ...state, [key]: value }));
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
    setLines((data as { lines: CalculatedLine[] }).lines);
  }

  const totals = lines?.reduce<Record<string, { sell: number; buy: number }>>((acc, line) => {
    acc[line.currency] ??= { sell: 0, buy: 0 };
    acc[line.currency]!.sell += line.total;
    acc[line.currency]!.buy += line.buyTotal ?? 0;
    return acc;
  }, {});

  return (
    <div className="mx-auto flex w-full max-w-4xl flex-col gap-5">
      <div>
        <h1 className="text-xl font-bold">Nouvelle cotation</h1>
        <p className="text-[13px] text-ink-3">Simulation depuis les grilles tarifaires actives — devis formel ensuite.</p>
      </div>

      <form onSubmit={calculate} className="grid gap-4 rounded-xl border border-line bg-surface p-5 shadow-sm md:grid-cols-4">
        <Field label="Mode">
          <select value={form.mode} onChange={(e) => set("mode", e.target.value)} className={inputClass}>
            <option value="sea_fcl">Maritime FCL</option>
            <option value="sea_lcl">Maritime LCL</option>
            <option value="air">Aérien</option>
            <option value="road">Routier</option>
          </select>
        </Field>
        <Field label="Origine">
          <input value={form.origin_locode} onChange={(e) => set("origin_locode", e.target.value)} maxLength={5} className={`${inputClass} mono uppercase`} />
        </Field>
        <Field label="Destination">
          <input value={form.destination_locode} onChange={(e) => set("destination_locode", e.target.value)} maxLength={5} className={`${inputClass} mono uppercase`} />
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
          <button type="submit" disabled={loading} className={`w-full ${buttonPrimary}`}>
            {loading ? "Calcul…" : "Calculer"}
          </button>
        </div>
      </form>

      {error && <p className="rounded-lg bg-crit-soft px-4 py-2.5 text-[13px] text-crit">{error}</p>}

      {lines && (
        <div className="rounded-xl border border-line bg-surface shadow-sm">
          <div className="border-b border-line px-4 py-3 text-[13px] font-bold">Résultat de la simulation</div>
          {lines.length === 0 ? (
            <p className="p-4 text-[13px] text-ink-3">Aucune grille tarifaire applicable pour ce trajet/mode.</p>
          ) : (
            <>
              <table className="w-full text-[13px]">
                <thead>
                  <tr className="border-b border-line text-left text-[10px] uppercase tracking-wider text-ink-3">
                    <th className="px-4 py-2">Prestation</th>
                    <th className="px-4 py-2">Unité</th>
                    <th className="px-4 py-2 text-right">Qté</th>
                    <th className="px-4 py-2 text-right">PU</th>
                    <th className="px-4 py-2 text-right">Total vente</th>
                    <th className="px-4 py-2 text-right">Coût achat</th>
                    <th className="px-4 py-2 text-right">Marge</th>
                  </tr>
                </thead>
                <tbody>
                  {lines.map((line, index) => (
                    <tr key={index} className="border-b border-line last:border-0">
                      <td className="px-4 py-2">
                        {line.description}
                        {line.minimumApplied && <span className="ml-1.5 rounded bg-warn-soft px-1.5 text-[10px] text-warn">minimum</span>}
                      </td>
                      <td className="px-4 py-2 text-ink-3">{line.unit}</td>
                      <td className="mono px-4 py-2 text-right">{line.quantity}</td>
                      <td className="mono px-4 py-2 text-right">{line.unitPrice.toLocaleString("fr-FR")}</td>
                      <td className="mono px-4 py-2 text-right font-semibold">{line.total.toLocaleString("fr-FR")} {line.currency}</td>
                      <td className="mono px-4 py-2 text-right text-ink-3">{line.buyTotal !== null ? line.buyTotal.toLocaleString("fr-FR") : "—"}</td>
                      <td className={`mono px-4 py-2 text-right ${line.buyTotal !== null ? "text-ok" : "text-ink-3"}`}>
                        {line.buyTotal !== null ? (line.total - line.buyTotal).toLocaleString("fr-FR") : "—"}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
              {totals && (
                <div className="flex flex-wrap gap-4 border-t border-line px-4 py-3">
                  {Object.entries(totals).map(([currency, total]) => (
                    <div key={currency} className="text-[13px]">
                      <span className="text-ink-3">Total {currency} : </span>
                      <span className="mono font-bold">{total.sell.toLocaleString("fr-FR")}</span>
                      {total.buy > 0 && (
                        <span className="ml-1.5 text-ok">
                          (marge {(total.sell - total.buy).toLocaleString("fr-FR")})
                        </span>
                      )}
                    </div>
                  ))}
                </div>
              )}
            </>
          )}
        </div>
      )}
    </div>
  );
}
