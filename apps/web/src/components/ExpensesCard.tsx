"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useState } from "react";
import { rawApi } from "@/lib/api";
import { ExpenseLinesForm } from "@/components/ExpenseLinesForm";
import { useCan } from "@/stores/auth";

interface Expense {
  id: string;
  label: string;
  amount: string;
  currency_code: string;
  status: string;
  supplier_invoice_number: string | null;
  supplier: { code: string; name: string } | null;
}
interface Side { margin: number; rate: number }
interface Margin {
  forecast: Side & { sell: number; cost: number };
  real: Side & { revenue: number; cost: number; pending_cost: number };
  variance: number;
}

const STATUS: Record<string, [string, string]> = {
  recorded: ["Enregistrée", "bg-paper text-ink-3"],
  validated: ["Validée", "bg-ok-soft text-ok"],
  paid: ["Réglée", "bg-ok-soft text-ok"],
  cancelled: ["Annulée", "bg-crit-soft text-crit"],
};
const money = (n: number, cur = "XOF") => `${new Intl.NumberFormat("fr-FR").format(Math.round(n))} ${cur}`;

export function ExpensesCard({ shipmentId }: { shipmentId: string }) {
  const queryClient = useQueryClient();
  const canCreate = useCan("expenses.create");
  const canValidate = useCan("expenses.validate");
  const canDelete = useCan("expenses.delete");
  const [open, setOpen] = useState(false);

  const key = ["expenses", shipmentId];
  const { data } = useQuery({
    queryKey: key,
    queryFn: async () => {
      const { data: r } = await rawApi.GET(`/v1/shipments/${shipmentId}/expenses`);
      return r as { data: Expense[]; margin: Margin };
    },
  });

  const invalidate = () => queryClient.invalidateQueries({ queryKey: key });

  const validate = useMutation({
    mutationFn: async (id: string) => {
      const { error: problem } = await rawApi.POST(`/v1/expenses/${id}/validate`, { body: { status: "validated" } });
      if (problem) throw problem;
    },
    onSuccess: invalidate,
  });
  const remove = useMutation({
    mutationFn: async (id: string) => {
      const { error: problem } = await rawApi.DELETE(`/v1/expenses/${id}`);
      if (problem) throw problem;
    },
    onSuccess: invalidate,
  });

  const m = data?.margin;
  const varianceTone = !m ? "" : m.variance >= 0 ? "text-ok" : "text-crit";

  return (
    <div className="rounded-xl border border-line bg-surface shadow-sm">
      <div className="flex items-center border-b border-line px-4 py-3">
        <span className="text-[13px] font-bold">Charges &amp; marge</span>
        {canCreate && (
          <button onClick={() => setOpen((v) => !v)} className="ml-auto text-xs font-semibold text-sea hover:underline">
            {open ? "Fermer" : "+ Dépense"}
          </button>
        )}
      </div>

      {m && (
        <div className="grid grid-cols-3 gap-px bg-line">
          <Cell label="Marge prévisionnelle" value={money(m.forecast.margin)} sub={`${m.forecast.rate} %`} />
          <Cell label="Marge réelle" value={money(m.real.margin)} sub={`CA ${money(m.real.revenue)} − coût ${money(m.real.cost)}`} />
          <Cell label="Écart" value={`${m.variance >= 0 ? "+" : ""}${money(m.variance)}`} sub={m.real.pending_cost > 0 ? `${money(m.real.pending_cost)} en attente` : "réel vs prévu"} tone={varianceTone} />
        </div>
      )}

      {open && canCreate && (
        <div className="border-b border-line bg-paper/40 p-4">
          <ExpenseLinesForm shipmentId={shipmentId} onSaved={() => { setOpen(false); invalidate(); }} />
        </div>
      )}

      <div className="overflow-x-auto">
        <table className="w-full text-[13px]">
          <thead>
            <tr className="border-b border-line text-left text-[10px] uppercase tracking-wider text-ink-3">
              <th className="px-4 py-2">Libellé</th>
              <th className="px-4 py-2">Fournisseur</th>
              <th className="px-4 py-2 text-right">Montant</th>
              <th className="px-4 py-2">Statut</th>
              <th className="px-4 py-2" />
            </tr>
          </thead>
          <tbody>
            {(data?.data.length ?? 0) === 0 && <tr><td colSpan={5} className="px-4 py-6 text-center text-ink-3">Aucune dépense enregistrée.</td></tr>}
            {data?.data.map((e) => {
              const [label, tone] = STATUS[e.status] ?? STATUS.recorded!;
              return (
                <tr key={e.id} className="border-b border-line last:border-0">
                  <td className="px-4 py-2">
                    {e.label}
                    {e.supplier_invoice_number && <span className="mono ml-1.5 text-[11px] text-ink-3">#{e.supplier_invoice_number}</span>}
                  </td>
                  <td className="px-4 py-2 text-ink-2">{e.supplier?.name ?? "—"}</td>
                  <td className="mono px-4 py-2 text-right font-semibold">{money(Number(e.amount), e.currency_code)}</td>
                  <td className="px-4 py-2"><span className={`rounded-full px-2.5 py-0.5 text-[11px] font-semibold ${tone}`}>{label}</span></td>
                  <td className="px-4 py-2">
                    <div className="flex items-center justify-end gap-3">
                      {e.status === "recorded" && canValidate && (
                        <button onClick={() => validate.mutate(e.id)} disabled={validate.isPending} className="text-xs font-semibold text-sea hover:underline">Valider</button>
                      )}
                      {canDelete && (
                        <button onClick={() => remove.mutate(e.id)} disabled={remove.isPending} className="text-xs font-semibold text-crit hover:underline">Supprimer</button>
                      )}
                    </div>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function Cell({ label, value, sub, tone }: { label: string; value: string; sub: string; tone?: string }) {
  return (
    <div className="bg-surface px-4 py-3">
      <div className="text-[10px] uppercase tracking-wider text-ink-3">{label}</div>
      <div className={`mono mt-1 text-base font-bold ${tone ?? ""}`}>{value}</div>
      <div className="text-[11px] text-ink-3">{sub}</div>
    </div>
  );
}
