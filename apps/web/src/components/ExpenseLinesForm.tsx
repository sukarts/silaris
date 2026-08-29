"use client";

import { useMutation, useQuery } from "@tanstack/react-query";
import { useState } from "react";
import { problemMessage, rawApi } from "@/lib/api";
import { buttonPrimary, inputClass } from "@/components/Field";

interface Line {
  label: string;
  amount: string;
  currency_code: string;
  supplier_id: string;
  supplier_invoice_number: string;
  invoice_date: string;
}
const blankLine = (): Line => ({ label: "", amount: "", currency_code: "XOF", supplier_id: "", supplier_invoice_number: "", invoice_date: "" });

/**
 * Saisie de plusieurs dépenses d'un dossier en une fois, façon lignes de
 * facture. Le lot part en une requête ; le dossier est fixé par l'appelant.
 */
export function ExpenseLinesForm({ shipmentId, onSaved }: { shipmentId: string; onSaved: () => void }) {
  const [lines, setLines] = useState<Line[]>([blankLine()]);
  const [error, setError] = useState<string | null>(null);

  const { data: suppliers } = useQuery({
    queryKey: ["parties", "supplier"],
    queryFn: async () => {
      const { data: r } = await rawApi.GET("/v1/parties", { params: { query: { type: "supplier", per_page: 100 } } });
      return (r as { data: { id: string; name: string; code: string }[] }).data;
    },
  });

  const update = (i: number, patch: Partial<Line>) => setLines((s) => s.map((l, idx) => (idx === i ? { ...l, ...patch } : l)));

  const save = useMutation({
    mutationFn: async () => {
      const { error: problem } = await rawApi.POST(`/v1/shipments/${shipmentId}/expenses`, {
        body: {
          lines: lines.map((l) => ({
            label: l.label,
            amount: Number(l.amount) || 0,
            currency_code: l.currency_code,
            supplier_id: l.supplier_id || null,
            supplier_invoice_number: l.supplier_invoice_number || null,
            invoice_date: l.invoice_date || null,
          })),
        },
      });
      if (problem) throw problem;
    },
    onSuccess: () => { setLines([blankLine()]); setError(null); onSaved(); },
    onError: (problem) => setError(problemMessage(problem)),
  });

  const ready = lines.length > 0 && lines.every((l) => l.label.trim() !== "" && l.amount !== "");

  return (
    <form onSubmit={(e) => { e.preventDefault(); save.mutate(); }} className="flex flex-col gap-3">
      <div className="overflow-x-auto">
        <table className="w-full text-[13px]">
          <thead>
            <tr className="text-left text-[10px] uppercase tracking-wider text-ink-3">
              <th className="px-2 py-1">Libellé</th>
              <th className="px-2 py-1 text-right">Montant</th>
              <th className="px-2 py-1">Devise</th>
              <th className="px-2 py-1">Fournisseur</th>
              <th className="px-2 py-1">N° facture</th>
              <th className="px-2 py-1">Date</th>
              <th className="px-2 py-1" />
            </tr>
          </thead>
          <tbody>
            {lines.map((line, i) => (
              <tr key={i}>
                <td className="px-2 py-1"><input required value={line.label} onChange={(e) => update(i, { label: e.target.value })} className={`${inputClass} !py-1`} placeholder="Acconage, transport…" /></td>
                <td className="px-2 py-1"><input required type="number" min={0} step="1" value={line.amount} onChange={(e) => update(i, { amount: e.target.value })} className={`${inputClass} mono !py-1 text-right`} /></td>
                <td className="px-2 py-1">
                  <select value={line.currency_code} onChange={(e) => update(i, { currency_code: e.target.value })} className={`${inputClass} !py-1`}>
                    {["XOF", "EUR", "USD"].map((c) => <option key={c} value={c}>{c}</option>)}
                  </select>
                </td>
                <td className="px-2 py-1">
                  <select value={line.supplier_id} onChange={(e) => update(i, { supplier_id: e.target.value })} className={`${inputClass} !py-1`}>
                    <option value="">—</option>
                    {(suppliers ?? []).map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                  </select>
                </td>
                <td className="px-2 py-1"><input value={line.supplier_invoice_number} onChange={(e) => update(i, { supplier_invoice_number: e.target.value })} className={`${inputClass} mono !py-1`} /></td>
                <td className="px-2 py-1"><input type="date" value={line.invoice_date} onChange={(e) => update(i, { invoice_date: e.target.value })} className={`${inputClass} !py-1`} /></td>
                <td className="px-2 py-1 text-right">
                  {lines.length > 1 && <button type="button" onClick={() => setLines((s) => s.filter((_, idx) => idx !== i))} className="text-[12px] text-crit hover:underline">Retirer</button>}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {error && <p className="rounded-lg bg-crit-soft px-3 py-2 text-xs text-crit">{error}</p>}
      <div className="flex items-center gap-3">
        <button type="button" onClick={() => setLines((s) => [...s, blankLine()])} className="rounded-lg border border-line-strong px-2.5 py-1 text-[12px] hover:bg-paper">+ Ligne</button>
        <button type="submit" disabled={!ready || save.isPending} className={`${buttonPrimary} !py-2`}>
          {save.isPending ? "Enregistrement…" : `Enregistrer ${lines.length > 1 ? `${lines.length} dépenses` : "la dépense"}`}
        </button>
      </div>
    </form>
  );
}
