"use client";

import { useQuery } from "@tanstack/react-query";
import { useState } from "react";
import { rawApi } from "@/lib/api";
import { Field, inputClass } from "@/components/Field";

interface MonthMargin { month: string; revenue: number; cost: number; margin: number }
interface ModeMargin { mode: string; revenue: number; cost: number; margin: number; rate: number; won_count: number }
interface MonthRevenue { month: string; invoiced: number }
interface CompanyRevenue { company: string; invoiced: number }
interface Business {
  period: { from: string; to: string };
  margin: {
    totals: { revenue: number; cost: number; margin: number; rate: number; won_count: number };
    by_month: MonthMargin[];
    by_mode: ModeMargin[];
  };
  revenue: { total: number; by_month: MonthRevenue[]; by_company: CompanyRevenue[] };
}

const MODE_LABEL: Record<string, string> = { sea_fcl: "Maritime FCL", sea_lcl: "Maritime LCL", air: "Aérien", road: "Terrestre" };
const money = (n: number) => new Intl.NumberFormat("fr-FR", { notation: n >= 1_000_000 ? "compact" : "standard", maximumFractionDigits: 1 }).format(n);
const MONTHS = ["", "janv.", "févr.", "mars", "avr.", "mai", "juin", "juil.", "août", "sept.", "oct.", "nov.", "déc."];
const monthLabel = (m: string) => {
  const [y = "", mo = ""] = m.split("-");
  return `${MONTHS[Number(mo)] ?? ""} ${y.slice(2)}`;
};

export default function ReportsPage() {
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");

  const { data, isLoading } = useQuery({
    queryKey: ["reports", "business", from, to],
    queryFn: async () => {
      const { data: response } = await rawApi.GET("/v1/reports/business", {
        params: { query: { ...(from ? { from } : {}), ...(to ? { to } : {}) } },
      });
      return response as Business;
    },
  });

  const maxMarginBar = Math.max(1, ...(data?.margin.by_month ?? []).map((m) => m.revenue));
  const maxRevBar = Math.max(1, ...(data?.revenue.by_month ?? []).map((m) => m.invoiced));

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-end gap-4">
        <div>
          <h1 className="text-xl font-bold">Rapports</h1>
          <p className="text-[13px] text-ink-3">Marge des offres gagnées et chiffre d&apos;affaires facturé.</p>
        </div>
        <div className="ml-auto flex items-end gap-2">
          <Field label="Du"><input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className={inputClass} /></Field>
          <Field label="Au"><input type="date" value={to} onChange={(e) => setTo(e.target.value)} className={inputClass} /></Field>
        </div>
      </div>

      {isLoading && <p className="text-[13px] text-ink-3">Chargement…</p>}

      {data && (
        <>
          {/* ——— Marge ——— */}
          <section className="flex flex-col gap-3">
            <h2 className="text-[13px] font-semibold text-ink-2">Marge — offres gagnées</h2>
            <div className="grid grid-cols-2 gap-3 md:grid-cols-5">
              {[
                { label: "CA vendu", value: money(data.margin.totals.revenue) },
                { label: "Coût estimé", value: money(data.margin.totals.cost) },
                { label: "Marge", value: money(data.margin.totals.margin) },
                { label: "Taux de marge", value: `${data.margin.totals.rate} %` },
                { label: "Offres gagnées", value: String(data.margin.totals.won_count) },
              ].map((c) => (
                <div key={c.label} className="rounded-xl border border-line bg-surface px-4 py-3.5 shadow-sm">
                  <div className="text-[11px] uppercase tracking-wider text-ink-3">{c.label}</div>
                  <div className="mono mt-1 text-lg font-bold">{c.value}</div>
                </div>
              ))}
            </div>
            <p className="text-[11px] text-ink-3">Le coût est l&apos;estimation portée à la cotation ; la marge est donc prévisionnelle.</p>

            <div className="grid gap-3 md:grid-cols-2">
              <div className="rounded-xl border border-line bg-surface p-4 shadow-sm">
                <div className="mb-3 text-[11px] uppercase tracking-wider text-ink-3">Par mois (CA vendu vs coût)</div>
                {data.margin.by_month.length === 0 ? (
                  <p className="py-6 text-center text-xs text-ink-3">Aucune offre gagnée sur la période.</p>
                ) : (
                  <div className="flex h-40 items-end gap-3">
                    {data.margin.by_month.map((m) => (
                      <div key={m.month} className="flex flex-1 flex-col items-center gap-1">
                        <div className="flex h-32 w-full items-end justify-center gap-1">
                          <div className="w-1/2 rounded-t bg-sea/70" style={{ height: `${(m.revenue / maxMarginBar) * 100}%` }} title={`CA ${money(m.revenue)}`} />
                          <div className="w-1/2 rounded-t bg-ink-3/40" style={{ height: `${(m.cost / maxMarginBar) * 100}%` }} title={`Coût ${money(m.cost)}`} />
                        </div>
                        <span className="text-[10px] text-ink-3">{monthLabel(m.month)}</span>
                      </div>
                    ))}
                  </div>
                )}
              </div>

              <div className="overflow-x-auto rounded-xl border border-line bg-surface p-4 shadow-sm">
                <div className="mb-3 text-[11px] uppercase tracking-wider text-ink-3">Par mode</div>
                <table className="w-full text-[13px]">
                  <thead>
                    <tr className="border-b border-line text-left text-[10px] uppercase tracking-wider text-ink-3">
                      <th className="py-1.5">Mode</th>
                      <th className="py-1.5 text-right">CA</th>
                      <th className="py-1.5 text-right">Marge</th>
                      <th className="py-1.5 text-right">Taux</th>
                      <th className="py-1.5 text-right">Gagnées</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.margin.by_mode.length === 0 && <tr><td colSpan={5} className="py-4 text-center text-ink-3">—</td></tr>}
                    {data.margin.by_mode.map((m) => (
                      <tr key={m.mode} className="border-b border-line last:border-0">
                        <td className="py-1.5">{MODE_LABEL[m.mode] ?? m.mode}</td>
                        <td className="mono py-1.5 text-right">{money(m.revenue)}</td>
                        <td className="mono py-1.5 text-right">{money(m.margin)}</td>
                        <td className="mono py-1.5 text-right">{m.rate} %</td>
                        <td className="mono py-1.5 text-right">{m.won_count}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          </section>

          {/* ——— Chiffre d'affaires ——— */}
          <section className="flex flex-col gap-3">
            <h2 className="text-[13px] font-semibold text-ink-2">Chiffre d&apos;affaires — facturé (net des avoirs)</h2>
            <div className="grid gap-3 md:grid-cols-2">
              <div className="rounded-xl border border-line bg-surface p-4 shadow-sm">
                <div className="text-[11px] uppercase tracking-wider text-ink-3">CA sur la période</div>
                <div className="mono mt-1 text-2xl font-bold">{money(data.revenue.total)}</div>
                <div className="mt-4 flex h-32 items-end gap-3">
                  {data.revenue.by_month.map((m) => (
                    <div key={m.month} className="flex flex-1 flex-col items-center gap-1">
                      <div className="flex h-24 w-full items-end">
                        <div className="w-full rounded-t bg-ok/70" style={{ height: `${(m.invoiced / maxRevBar) * 100}%` }} title={money(m.invoiced)} />
                      </div>
                      <span className="text-[10px] text-ink-3">{monthLabel(m.month)}</span>
                    </div>
                  ))}
                  {data.revenue.by_month.length === 0 && <p className="w-full py-6 text-center text-xs text-ink-3">Aucune facture sur la période.</p>}
                </div>
              </div>

              <div className="overflow-x-auto rounded-xl border border-line bg-surface p-4 shadow-sm">
                <div className="mb-3 text-[11px] uppercase tracking-wider text-ink-3">Par société</div>
                <table className="w-full text-[13px]">
                  <thead>
                    <tr className="border-b border-line text-left text-[10px] uppercase tracking-wider text-ink-3">
                      <th className="py-1.5">Société</th>
                      <th className="py-1.5 text-right">CA facturé</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.revenue.by_company.length === 0 && <tr><td colSpan={2} className="py-4 text-center text-ink-3">—</td></tr>}
                    {data.revenue.by_company.map((c) => (
                      <tr key={c.company} className="border-b border-line last:border-0">
                        <td className="py-1.5">{c.company}</td>
                        <td className="mono py-1.5 text-right">{money(c.invoiced)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          </section>
        </>
      )}
    </div>
  );
}
