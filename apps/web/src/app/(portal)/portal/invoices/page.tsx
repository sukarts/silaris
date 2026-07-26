"use client";

import { useQuery } from "@tanstack/react-query";
import { downloadFile, rawApi } from "@/lib/api";
import { PortalShell } from "@/components/PortalShell";

interface PortalInvoice {
  id: string;
  type: string;
  number: string;
  payment_status: string;
  currency_code: string;
  total_incl_tax: string;
  issue_date: string | null;
  due_date: string | null;
  shipment: { reference: string } | null;
}

const PAYMENT: Record<string, [string, string]> = {
  unpaid: ["À régler", "bg-warn-soft text-warn"],
  partial: ["Partiellement réglée", "bg-warn-soft text-warn"],
  paid: ["Réglée", "bg-ok-soft text-ok"],
  none: ["—", "bg-paper text-ink-3"],
};

export default function PortalInvoicesPage() {
  const { data } = useQuery({
    queryKey: ["portal", "invoices"],
    queryFn: async () => {
      const { data: response } = await rawApi.GET("/v1/portal/invoices");
      return (response as { data: PortalInvoice[] }).data;
    },
  });

  return (
    <PortalShell>
      <h1 className="pb-4 text-xl font-bold">Mes factures</h1>
      <div className="overflow-x-auto rounded-xl border border-line bg-surface shadow-sm">
        <table className="w-full text-[13px]">
          <thead>
            <tr className="border-b border-line text-left text-[10px] uppercase tracking-wider text-ink-3">
              <th className="px-3 py-2.5">Numéro</th>
              <th className="px-3 py-2.5">Dossier</th>
              <th className="px-3 py-2.5">Émise le</th>
              <th className="px-3 py-2.5">Échéance</th>
              <th className="px-3 py-2.5 text-right">Montant TTC</th>
              <th className="px-3 py-2.5">Statut</th>
              <th className="px-3 py-2.5" />
            </tr>
          </thead>
          <tbody>
            {data?.map((invoice) => {
              const [label, tone] = PAYMENT[invoice.payment_status] ?? PAYMENT.none!;
              return (
                <tr key={invoice.id} className="border-b border-line last:border-0">
                  <td className="mono px-3 py-2.5 font-semibold text-sea">{invoice.number}</td>
                  <td className="mono px-3 py-2.5 text-ink-2">{invoice.shipment?.reference ?? "—"}</td>
                  <td className="mono px-3 py-2.5">{invoice.issue_date ? new Date(invoice.issue_date).toLocaleDateString("fr-FR") : "—"}</td>
                  <td className="mono px-3 py-2.5">{invoice.due_date ? new Date(invoice.due_date).toLocaleDateString("fr-FR") : "—"}</td>
                  <td className="mono px-3 py-2.5 text-right font-bold">
                    {Number(invoice.total_incl_tax).toLocaleString("fr-FR")} {invoice.currency_code}
                  </td>
                  <td className="px-3 py-2.5">
                    <span className={`rounded-full px-2.5 py-0.5 text-[11px] font-semibold ${tone}`}>{label}</span>
                  </td>
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
    </PortalShell>
  );
}
