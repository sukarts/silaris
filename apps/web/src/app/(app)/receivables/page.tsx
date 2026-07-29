"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useMemo, useState } from "react";
import { problemMessage, rawApi } from "@/lib/api";
import { useCan } from "@/stores/auth";

interface AgedRow {
  party: { id: string; code: string; name: string };
  current: number;
  "1_30": number;
  "31_60": number;
  "61_90": number;
  over_90: number;
  total: number;
}

interface OutstandingInvoice {
  invoice_id: string;
  company_id: string;
  number: string;
  due_date: string;
  currency_code: string;
  total: number;
  allocated: number;
  outstanding: number;
}

const BUCKETS = [
  ["current", "Non échu"],
  ["1_30", "1 à 30 j"],
  ["31_60", "31 à 60 j"],
  ["61_90", "61 à 90 j"],
  ["over_90", "Plus de 90 j"],
] as const;

// Le retard se lit d'un coup d'œil : au-delà de 90 jours, une créance se
// provisionne plutôt qu'elle ne se relance.
const BUCKET_TONE: Record<string, string> = {
  current: "text-ink-2",
  "1_30": "text-sea",
  "31_60": "text-warn",
  "61_90": "text-warn",
  over_90: "text-crit",
};

const METHODS = [
  ["mobile_money", "Mobile money"],
  ["transfer", "Virement"],
  ["cheque", "Chèque"],
  ["cash", "Espèces"],
  ["card", "Carte"],
  ["compensation", "Compensation"],
] as const;

const money = (value: number, currency = "XOF") =>
  `${new Intl.NumberFormat("fr-FR", { maximumFractionDigits: 0 }).format(value)} ${currency}`;

export default function ReceivablesPage() {
  const canRecord = useCan("payments.create");
  const [openParty, setOpenParty] = useState<AgedRow["party"] | null>(null);

  const { data, isLoading } = useQuery({
    queryKey: ["receivables"],
    queryFn: async () => {
      const { data: response } = await rawApi.GET("/v1/receivables");
      return response as { as_of: string; rows: AgedRow[]; totals: Record<string, number> };
    },
  });

  const rows = data?.rows ?? [];

  return (
    <div className="flex flex-col gap-4">
      <div>
        <h1 className="text-xl font-bold">Recouvrement</h1>
        <p className="text-[13px] text-ink-3">
          Ce que les clients doivent encore, classé par ancienneté — c&apos;est le retard, pas le montant, qui
          décide de l&apos;action.
        </p>
      </div>

      <div className="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
        {BUCKETS.map(([key, label]) => (
          <div key={key} className="rounded-xl border border-line bg-surface p-4 shadow-sm">
            <div className="text-[10px] uppercase tracking-wider text-ink-3">{label}</div>
            {/* Une tranche vide n'est pas une alerte : elle reste discrète. */}
            <div
              className={`mono mt-1 text-lg font-bold ${(data?.totals[key] ?? 0) > 0 ? BUCKET_TONE[key] : "text-ink-3"}`}
            >
              {new Intl.NumberFormat("fr-FR", { maximumFractionDigits: 0 }).format(data?.totals[key] ?? 0)}
            </div>
          </div>
        ))}
        <div className="rounded-xl border border-line-strong bg-paper p-4 shadow-sm">
          <div className="text-[10px] uppercase tracking-wider text-ink-3">Total dû</div>
          <div className="mono mt-1 text-lg font-bold">
            {new Intl.NumberFormat("fr-FR", { maximumFractionDigits: 0 }).format(data?.totals.total ?? 0)}
          </div>
        </div>
      </div>

      <div className="overflow-x-auto rounded-xl border border-line bg-surface shadow-sm">
        <table className="w-full text-[13px]">
          <thead>
            <tr className="border-b border-line text-left text-[10px] uppercase tracking-wider text-ink-3">
              <th className="px-3 py-2.5">Client</th>
              {BUCKETS.map(([key, label]) => (
                <th key={key} className="px-3 py-2.5 text-right">{label}</th>
              ))}
              <th className="px-3 py-2.5 text-right">Total</th>
              <th className="px-3 py-2.5" />
            </tr>
          </thead>
          <tbody>
            {isLoading && (
              <tr><td colSpan={8} className="px-3 py-8 text-center text-ink-3">Chargement…</td></tr>
            )}
            {!isLoading && rows.length === 0 && (
              <tr>
                <td colSpan={8} className="px-3 py-8 text-center text-ink-3">
                  Aucune créance en cours.
                </td>
              </tr>
            )}
            {rows.map((row) => (
              <tr key={row.party.id} className="border-b border-line last:border-0 hover:bg-sea/5">
                <td className="px-3 py-2.5">
                  <span className="font-semibold">{row.party.name}</span>
                  <span className="ml-1.5 text-[11px] text-ink-3">{row.party.code}</span>
                </td>
                {BUCKETS.map(([key]) => (
                  <td key={key} className={`mono px-3 py-2.5 text-right ${row[key] > 0 ? BUCKET_TONE[key] : "text-ink-3"}`}>
                    {row[key] > 0 ? new Intl.NumberFormat("fr-FR", { maximumFractionDigits: 0 }).format(row[key]) : "—"}
                  </td>
                ))}
                <td className="mono px-3 py-2.5 text-right font-semibold">
                  {new Intl.NumberFormat("fr-FR", { maximumFractionDigits: 0 }).format(row.total)}
                </td>
                <td className="px-3 py-2.5 text-right">
                  <button
                    type="button"
                    onClick={() => setOpenParty(row.party)}
                    className="rounded-lg border border-line-strong px-2.5 py-1 text-[12px] font-semibold hover:bg-paper"
                  >
                    {canRecord ? "Encaisser" : "Détail"}
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {openParty && <PaymentPanel party={openParty} onClose={() => setOpenParty(null)} canRecord={canRecord} />}
    </div>
  );
}

function PaymentPanel({
  party,
  onClose,
  canRecord,
}: {
  party: AgedRow["party"];
  onClose: () => void;
  canRecord: boolean;
}) {
  const queryClient = useQueryClient();
  const [error, setError] = useState<string | null>(null);
  const [amount, setAmount] = useState("");
  const [method, setMethod] = useState<string>("transfer");
  const [methodReference, setMethodReference] = useState("");
  const [receivedOn, setReceivedOn] = useState(new Date().toISOString().slice(0, 10));
  const [note, setNote] = useState("");
  const [manual, setManual] = useState<Record<string, string>>({});

  const { data } = useQuery({
    queryKey: ["receivables", party.id],
    queryFn: async () => {
      const { data: response } = await rawApi.GET(`/v1/receivables/${party.id}`);
      return response as { invoices: OutstandingInvoice[]; total: number };
    },
  });

  const invoices = useMemo(() => data?.invoices ?? [], [data]);

  /**
   * Imputation au plus ancien, rejouée à chaque frappe : le comptable voit
   * immédiatement quelles factures le règlement solde, et peut corriger ligne
   * à ligne s'il en décide autrement.
   */
  const suggested = useMemo(() => {
    let remaining = Number(amount) || 0;
    const result: Record<string, number> = {};
    for (const invoice of invoices) {
      if (remaining <= 0) break;
      const share = Math.min(remaining, invoice.outstanding);
      if (share > 0) {
        result[invoice.invoice_id] = share;
        remaining -= share;
      }
    }
    return result;
  }, [amount, invoices]);

  const allocationOf = (invoiceId: string) =>
    manual[invoiceId] !== undefined ? Number(manual[invoiceId]) || 0 : (suggested[invoiceId] ?? 0);

  const allocatedTotal = invoices.reduce((sum, invoice) => sum + allocationOf(invoice.invoice_id), 0);
  const unallocated = Math.round(((Number(amount) || 0) - allocatedTotal) * 100) / 100;

  const record = useMutation({
    mutationFn: async () => {
      const allocations = invoices
        .map((invoice) => ({ invoice_id: invoice.invoice_id, amount: allocationOf(invoice.invoice_id) }))
        .filter((line) => line.amount > 0);

      const { data: response, error: failure } = await rawApi.POST("/v1/payments", {
        body: {
          company_id: invoices[0]!.company_id,
          party_id: party.id,
          method,
          method_reference: methodReference || null,
          currency_code: invoices[0]!.currency_code,
          amount: Number(amount),
          received_on: receivedOn,
          note: note || null,
          allocations,
        },
      });
      if (failure) throw failure;
      return response;
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["receivables"] });
      void queryClient.invalidateQueries({ queryKey: ["invoices"] });
      onClose();
    },
    onError: (failure) => setError(problemMessage(failure)),
  });

  const currency = invoices[0]?.currency_code ?? "XOF";
  const canSubmit =
    canRecord && invoices.length > 0 && Number(amount) > 0 && unallocated >= 0 && !record.isPending;

  return (
    <div className="fixed inset-0 z-40 flex items-start justify-center bg-ink/30 p-4 sm:p-8" onClick={onClose}>
      <div
        className="max-h-full w-full max-w-3xl overflow-y-auto rounded-xl border border-line bg-surface shadow-lg"
        onClick={(event) => event.stopPropagation()}
      >
        <div className="flex items-start gap-3 border-b border-line px-5 py-4">
          <div>
            <h2 className="text-base font-bold">{party.name}</h2>
            <p className="text-[12px] text-ink-3">
              {invoices.length} facture{invoices.length > 1 ? "s" : ""} en cours — {money(data?.total ?? 0, currency)}
            </p>
          </div>
          <button type="button" onClick={onClose} className="ml-auto text-ink-3 hover:text-ink">
            Fermer
          </button>
        </div>

        {canRecord && (
          <div className="grid gap-3 border-b border-line px-5 py-4 sm:grid-cols-4">
            <label className="flex flex-col gap-1 text-[12px] text-ink-3">
              Montant reçu
              <input
                type="number"
                min="0"
                step="1"
                value={amount}
                onChange={(event) => {
                  setAmount(event.target.value);
                  setManual({});
                }}
                className="mono rounded-lg border border-line-strong bg-surface px-3 py-1.5 text-[13px] text-ink"
              />
            </label>
            <label className="flex flex-col gap-1 text-[12px] text-ink-3">
              Moyen
              <select
                value={method}
                onChange={(event) => setMethod(event.target.value)}
                className="rounded-lg border border-line-strong bg-surface px-3 py-1.5 text-[13px] text-ink"
              >
                {METHODS.map(([value, label]) => (
                  <option key={value} value={value}>{label}</option>
                ))}
              </select>
            </label>
            <label className="flex flex-col gap-1 text-[12px] text-ink-3">
              Référence
              <input
                value={methodReference}
                onChange={(event) => setMethodReference(event.target.value)}
                placeholder="N° chèque, transaction…"
                className="rounded-lg border border-line-strong bg-surface px-3 py-1.5 text-[13px] text-ink"
              />
            </label>
            <label className="flex flex-col gap-1 text-[12px] text-ink-3">
              Reçu le
              <input
                type="date"
                value={receivedOn}
                max={new Date().toISOString().slice(0, 10)}
                onChange={(event) => setReceivedOn(event.target.value)}
                className="rounded-lg border border-line-strong bg-surface px-3 py-1.5 text-[13px] text-ink"
              />
            </label>
            <label className="flex flex-col gap-1 text-[12px] text-ink-3 sm:col-span-4">
              Note
              <input
                value={note}
                onChange={(event) => setNote(event.target.value)}
                className="rounded-lg border border-line-strong bg-surface px-3 py-1.5 text-[13px] text-ink"
              />
            </label>
          </div>
        )}

        <table className="w-full text-[13px]">
          <thead>
            <tr className="border-b border-line text-left text-[10px] uppercase tracking-wider text-ink-3">
              <th className="px-5 py-2.5">Facture</th>
              <th className="px-3 py-2.5">Échéance</th>
              <th className="px-3 py-2.5 text-right">Montant</th>
              <th className="px-3 py-2.5 text-right">Déjà réglé</th>
              <th className="px-3 py-2.5 text-right">Reste dû</th>
              {canRecord && <th className="px-5 py-2.5 text-right">Imputer</th>}
            </tr>
          </thead>
          <tbody>
            {invoices.map((invoice) => {
              const overdue = new Date(invoice.due_date) < new Date();
              return (
                <tr key={invoice.invoice_id} className="border-b border-line last:border-0">
                  <td className="mono px-5 py-2.5 font-semibold">{invoice.number}</td>
                  <td className={`mono px-3 py-2.5 ${overdue ? "text-crit" : "text-ink-2"}`}>
                    {new Date(invoice.due_date).toLocaleDateString("fr-FR")}
                  </td>
                  <td className="mono px-3 py-2.5 text-right text-ink-2">
                    {new Intl.NumberFormat("fr-FR", { maximumFractionDigits: 0 }).format(invoice.total)}
                  </td>
                  <td className="mono px-3 py-2.5 text-right text-ink-2">
                    {invoice.allocated > 0
                      ? new Intl.NumberFormat("fr-FR", { maximumFractionDigits: 0 }).format(invoice.allocated)
                      : "—"}
                  </td>
                  <td className="mono px-3 py-2.5 text-right font-semibold">
                    {new Intl.NumberFormat("fr-FR", { maximumFractionDigits: 0 }).format(invoice.outstanding)}
                  </td>
                  {canRecord && (
                    <td className="px-5 py-2.5 text-right">
                      <input
                        type="number"
                        min="0"
                        max={invoice.outstanding}
                        step="1"
                        value={
                          manual[invoice.invoice_id] !== undefined
                            ? manual[invoice.invoice_id]
                            : (suggested[invoice.invoice_id] ?? "")
                        }
                        onChange={(event) =>
                          setManual((previous) => ({ ...previous, [invoice.invoice_id]: event.target.value }))
                        }
                        className="mono w-32 rounded-lg border border-line-strong bg-surface px-2 py-1 text-right text-[13px] text-ink"
                      />
                    </td>
                  )}
                </tr>
              );
            })}
          </tbody>
        </table>

        {canRecord && (
          <div className="flex flex-wrap items-center gap-3 border-t border-line px-5 py-4">
            <div className="text-[12px] text-ink-3">
              Imputé <span className="mono font-semibold text-ink">{money(allocatedTotal, currency)}</span>
              {unallocated > 0 && (
                <>
                  {" · "}
                  <span className="text-warn">Acompte non imputé {money(unallocated, currency)}</span>
                </>
              )}
              {unallocated < 0 && (
                <>
                  {" · "}
                  <span className="text-crit">Dépasse le montant reçu de {money(-unallocated, currency)}</span>
                </>
              )}
            </div>
            {error && <div className="text-[12px] text-crit">{error}</div>}
            <button
              type="button"
              disabled={!canSubmit}
              onClick={() => {
                setError(null);
                record.mutate();
              }}
              className="ml-auto rounded-lg bg-sea px-4 py-2 text-[13px] font-semibold text-white disabled:opacity-40"
            >
              {record.isPending ? "Enregistrement…" : "Enregistrer le règlement"}
            </button>
          </div>
        )}
      </div>
    </div>
  );
}
