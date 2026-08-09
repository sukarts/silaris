"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import Link from "next/link";
import { useState } from "react";
import { rawApi } from "@/lib/api";
import { inputClass } from "@/components/Field";
import { useCan } from "@/stores/auth";

interface Expense {
  id: string;
  label: string;
  amount: string;
  currency_code: string;
  status: string;
  supplier_invoice_number: string | null;
  invoice_date: string | null;
  supplier: { code: string; name: string } | null;
  shipment: { id: string; reference: string } | null;
}

const STATUS: Record<string, [string, string]> = {
  recorded: ["Enregistrée", "bg-paper text-ink-3"],
  validated: ["Validée", "bg-ok-soft text-ok"],
  paid: ["Réglée", "bg-ok-soft text-ok"],
  cancelled: ["Annulée", "bg-crit-soft text-crit"],
};
const FILTERS: [string, string][] = [["", "Toutes"], ["recorded", "À valider"], ["validated", "Validées"], ["paid", "Réglées"], ["cancelled", "Annulées"]];
const money = (n: number, cur = "XOF") => `${new Intl.NumberFormat("fr-FR").format(Math.round(n))} ${cur}`;
const date = (d: string | null) => (d ? new Date(d).toLocaleDateString("fr-FR") : "—");

export default function ExpensesPage() {
  const queryClient = useQueryClient();
  const canValidate = useCan("expenses.validate");
  const canDelete = useCan("expenses.delete");
  const [status, setStatus] = useState("");
  const [search, setSearch] = useState("");

  const key = ["expenses", "all", status, search];
  const { data, isLoading } = useQuery({
    queryKey: key,
    queryFn: async () => {
      const { data: r } = await rawApi.GET("/v1/expenses", {
        params: { query: { ...(status ? { status } : {}), ...(search ? { search } : {}) } },
      });
      return (r as { data: Expense[] }).data;
    },
  });

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ["expenses"] });
  const validate = useMutation({
    mutationFn: async (id: string) => { const { error } = await rawApi.POST(`/v1/expenses/${id}/validate`, { body: { status: "validated" } }); if (error) throw error; },
    onSuccess: invalidate,
  });
  const remove = useMutation({
    mutationFn: async (id: string) => { const { error } = await rawApi.DELETE(`/v1/expenses/${id}`); if (error) throw error; },
    onSuccess: invalidate,
  });

  return (
    <div className="flex flex-col gap-4">
      <div>
        <h1 className="text-xl font-bold">Dépenses</h1>
        <p className="text-[13px] text-ink-3">Factures fournisseurs de tous les dossiers. La saisie se fait dans le dossier concerné.</p>
      </div>

      <div className="flex flex-wrap items-center gap-2">
        {FILTERS.map(([value, label]) => (
          <button
            key={value}
            onClick={() => setStatus(value)}
            className={`rounded-full border px-3.5 py-1 text-xs font-semibold ${status === value ? "border-ink bg-ink text-paper" : "border-line-strong text-ink-2 hover:bg-surface"}`}
          >
            {label}
          </button>
        ))}
        <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Rechercher un libellé…" className={`${inputClass} ml-auto max-w-xs`} />
      </div>

      <div className="overflow-x-auto rounded-xl border border-line bg-surface shadow-sm">
        <table className="w-full text-[13px]">
          <thead>
            <tr className="border-b border-line text-left text-[10px] uppercase tracking-wider text-ink-3">
              <th className="px-4 py-2.5">Dossier</th>
              <th className="px-4 py-2.5">Libellé</th>
              <th className="px-4 py-2.5">Fournisseur</th>
              <th className="px-4 py-2.5 text-right">Montant</th>
              <th className="px-4 py-2.5">Date facture</th>
              <th className="px-4 py-2.5">Statut</th>
              <th className="px-4 py-2.5" />
            </tr>
          </thead>
          <tbody>
            {isLoading && <tr><td colSpan={7} className="px-4 py-8 text-center text-ink-3">Chargement…</td></tr>}
            {!isLoading && (data?.length ?? 0) === 0 && <tr><td colSpan={7} className="px-4 py-8 text-center text-ink-3">Aucune dépense.</td></tr>}
            {data?.map((e) => {
              const [label, tone] = STATUS[e.status] ?? STATUS.recorded!;
              return (
                <tr key={e.id} className="border-b border-line last:border-0 hover:bg-sea/5">
                  <td className="mono px-4 py-2.5">
                    {e.shipment ? <Link href={`/shipments/${e.shipment.id}`} className="font-semibold text-sea hover:underline">{e.shipment.reference}</Link> : "—"}
                  </td>
                  <td className="px-4 py-2.5">
                    {e.label}
                    {e.supplier_invoice_number && <span className="mono ml-1.5 text-[11px] text-ink-3">#{e.supplier_invoice_number}</span>}
                  </td>
                  <td className="px-4 py-2.5 text-ink-2">{e.supplier?.name ?? "—"}</td>
                  <td className="mono px-4 py-2.5 text-right font-semibold">{money(Number(e.amount), e.currency_code)}</td>
                  <td className="mono px-4 py-2.5">{date(e.invoice_date)}</td>
                  <td className="px-4 py-2.5"><span className={`rounded-full px-2.5 py-0.5 text-[11px] font-semibold ${tone}`}>{label}</span></td>
                  <td className="px-4 py-2.5">
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
