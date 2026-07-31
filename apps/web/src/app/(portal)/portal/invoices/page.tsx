"use client";

import { useQuery } from "@tanstack/react-query";
import { downloadFile, rawApi } from "@/lib/api";
import { PortalShell } from "@/components/PortalShell";

interface PortalInvoice {
  id: string;
  type: string;
  number: string;
  currency_code: string;
  total_incl_tax: number;
  paid: number;
  outstanding: number;
  pay_status: string;
  issue_date: string | null;
  due_date: string | null;
  shipment: { reference: string } | null;
}
interface Receipt { reference: string; method: string; amount: number; received_on: string; note: string | null }
interface Summary { current: number; "1_30": number; "31_60": number; "61_90": number; over_90: number; total: number }
interface Statement { data: PortalInvoice[]; summary: Summary; receipts: Receipt[] }

const PAY: Record<string, [string, string]> = {
  unpaid: ["À régler", "bg-warn-soft text-warn"],
  partial: ["Partiellement réglée", "bg-warn-soft text-warn"],
  paid: ["Réglée", "bg-ok-soft text-ok"],
  n_a: ["Avoir", "bg-paper text-ink-3"],
};
const METHOD: Record<string, string> = {
  transfer: "Virement", cash: "Espèces", cheque: "Chèque", mobile_money: "Mobile money", card: "Carte",
};
const money = (n: number, cur = "XOF") => `${new Intl.NumberFormat("fr-FR").format(n)} ${cur}`;
const date = (d: string | null) => (d ? new Date(d).toLocaleDateString("fr-FR") : "—");

export default function PortalInvoicesPage() {
  const { data } = useQuery({
    queryKey: ["portal", "statement"],
    queryFn: async () => {
      const { data: response } = await rawApi.GET("/v1/portal/invoices");
      return response as Statement;
    },
  });

  const cur = data?.data[0]?.currency_code ?? "XOF";
  const overdue = data ? data.summary["31_60"] + data.summary["61_90"] + data.summary.over_90 : 0;

  return (
    <PortalShell>
      <h1 className="pb-4 text-xl font-bold">Factures &amp; règlements</h1>

      <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-3">
        <div className="rounded-xl border border-line bg-surface px-4 py-3.5 shadow-sm">
          <div className="text-[11px] uppercase tracking-wider text-ink-3">Reste à régler</div>
          <div className="mono mt-1 text-lg font-bold">{money(data?.summary.total ?? 0, cur)}</div>
        </div>
        <div className="rounded-xl border border-line bg-surface px-4 py-3.5 shadow-sm">
          <div className="text-[11px] uppercase tracking-wider text-ink-3">Dont en retard (+30 j)</div>
          <div className={`mono mt-1 text-lg font-bold ${overdue > 0 ? "text-crit" : ""}`}>{money(overdue, cur)}</div>
        </div>
        <div className="rounded-xl border border-line bg-surface px-4 py-3.5 shadow-sm">
          <div className="text-[11px] uppercase tracking-wider text-ink-3">Non échu</div>
          <div className="mono mt-1 text-lg font-bold">{money(data?.summary.current ?? 0, cur)}</div>
        </div>
      </div>

      <div className="overflow-x-auto rounded-xl border border-line bg-surface shadow-sm">
        <table className="w-full text-[13px]">
          <thead>
            <tr className="border-b border-line text-left text-[10px] uppercase tracking-wider text-ink-3">
              <th className="px-3 py-2.5">Numéro</th>
              <th className="px-3 py-2.5">Dossier</th>
              <th className="px-3 py-2.5">Émise</th>
              <th className="px-3 py-2.5">Échéance</th>
              <th className="px-3 py-2.5 text-right">TTC</th>
              <th className="px-3 py-2.5 text-right">Payé</th>
              <th className="px-3 py-2.5 text-right">Reste dû</th>
              <th className="px-3 py-2.5">Statut</th>
              <th className="px-3 py-2.5" />
            </tr>
          </thead>
          <tbody>
            {data?.data.length === 0 && <tr><td colSpan={9} className="px-3 py-8 text-center text-ink-3">Aucune facture.</td></tr>}
            {data?.data.map((invoice) => {
              const [label, tone] = PAY[invoice.pay_status] ?? PAY.unpaid!;
              return (
                <tr key={invoice.id} className="border-b border-line last:border-0">
                  <td className="mono px-3 py-2.5 font-semibold text-sea">{invoice.number}</td>
                  <td className="mono px-3 py-2.5 text-ink-2">{invoice.shipment?.reference ?? "—"}</td>
                  <td className="mono px-3 py-2.5">{date(invoice.issue_date)}</td>
                  <td className="mono px-3 py-2.5">{date(invoice.due_date)}</td>
                  <td className="mono px-3 py-2.5 text-right font-bold">{money(invoice.total_incl_tax, invoice.currency_code)}</td>
                  <td className="mono px-3 py-2.5 text-right text-ink-2">{invoice.type === "invoice" ? money(invoice.paid, invoice.currency_code) : "—"}</td>
                  <td className="mono px-3 py-2.5 text-right font-semibold">{invoice.type === "invoice" ? money(invoice.outstanding, invoice.currency_code) : "—"}</td>
                  <td className="px-3 py-2.5"><span className={`rounded-full px-2.5 py-0.5 text-[11px] font-semibold ${tone}`}>{label}</span></td>
                  <td className="px-3 py-2.5">
                    <button
                      onClick={() => downloadFile(`/v1/portal/invoices/${invoice.id}/pdf`, "facture.pdf").catch(() => undefined)}
                      className="text-xs font-semibold text-sea hover:underline"
                    >
                      PDF
                    </button>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>

      <h2 className="mb-2 mt-6 text-[13px] font-semibold text-ink-2">Mes règlements</h2>
      <div className="overflow-x-auto rounded-xl border border-line bg-surface shadow-sm">
        <table className="w-full text-[13px]">
          <thead>
            <tr className="border-b border-line text-left text-[10px] uppercase tracking-wider text-ink-3">
              <th className="px-3 py-2.5">Reçu</th>
              <th className="px-3 py-2.5">Moyen</th>
              <th className="px-3 py-2.5">Reçu le</th>
              <th className="px-3 py-2.5 text-right">Montant</th>
              <th className="px-3 py-2.5">Note</th>
            </tr>
          </thead>
          <tbody>
            {(data?.receipts.length ?? 0) === 0 && <tr><td colSpan={5} className="px-3 py-6 text-center text-ink-3">Aucun règlement enregistré.</td></tr>}
            {data?.receipts.map((r) => (
              <tr key={r.reference} className="border-b border-line last:border-0">
                <td className="mono px-3 py-2.5 font-semibold">{r.reference}</td>
                <td className="px-3 py-2.5 text-ink-2">{METHOD[r.method] ?? r.method}</td>
                <td className="mono px-3 py-2.5">{date(r.received_on)}</td>
                <td className="mono px-3 py-2.5 text-right font-semibold text-ok">{money(r.amount, cur)}</td>
                <td className="px-3 py-2.5 text-ink-3">{r.note ?? "—"}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </PortalShell>
  );
}
