"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import Link from "next/link";
import { useState } from "react";
import { downloadFile, problemMessage, rawApi } from "@/lib/api";
import { buttonPrimary } from "@/components/Field";
import { useCan } from "@/stores/auth";

interface Invoice {
  id: string;
  type: string;
  number: string | null;
  status: string;
  payment_status: string;
  currency_code: string;
  total_excl_tax: string;
  total_incl_tax: string;
  issue_date: string | null;
  due_date: string | null;
  fne_reference: string | null;
  party: { name: string };
  shipment: { reference: string } | null;
}

const TYPE_LABEL: Record<string, string> = { proforma: "Proforma", invoice: "Facture", credit_note: "Avoir" };
const STATUS_TONE: Record<string, string> = {
  draft: "bg-paper text-ink-3",
  validated: "bg-sea-soft text-sea",
  synced: "bg-ok-soft text-ok",
  sync_failed: "bg-crit-soft text-crit",
};
const PAYMENT_LABEL: Record<string, [string, string]> = {
  none: ["—", "text-ink-3"],
  unpaid: ["Impayée", "text-warn"],
  partial: ["Partielle", "text-warn"],
  paid: ["Payée", "text-ok"],
};

export default function BillingPage() {
  const queryClient = useQueryClient();
  const canValidate = useCan("invoices.validate");
  const canCreate = useCan("invoices.create");
  const canCertify = useCan("invoices.certify_fne");
  const [error, setError] = useState<string | null>(null);

  const { data, isLoading } = useQuery({
    queryKey: ["invoices"],
    queryFn: async () => {
      const { data: response } = await rawApi.GET("/v1/invoices");
      return response as { data: Invoice[] };
    },
  });

  const validate = useMutation({
    mutationFn: async (invoiceId: string) => {
      const { error: problem } = await rawApi.POST(`/v1/invoices/${invoiceId}/validate`);
      if (problem) throw problem;
    },
    onSuccess: () => {
      setError(null);
      queryClient.invalidateQueries({ queryKey: ["invoices"] });
    },
    onError: (problem) => setError(problemMessage(problem)),
  });

  // Certification FNE : une facture en devise étrangère (B2F) exige le taux de
  // change ; on le demande à ce moment plutôt que de le stocker à l'avance.
  const certify = useMutation({
    mutationFn: async (invoice: Invoice) => {
      let body: Record<string, unknown> | undefined;
      if (invoice.currency_code !== "XOF") {
        const rate = window.prompt(`Taux de change ${invoice.currency_code} → XOF pour la DGI :`);
        if (rate === null) return;
        body = { foreign_currency_rate: Number(rate) };
      }
      const { error: problem } = await rawApi.POST(`/v1/invoices/${invoice.id}/fne-certify`, body ? { body } : undefined);
      if (problem) throw problem;
    },
    onSuccess: () => {
      setError(null);
      queryClient.invalidateQueries({ queryKey: ["invoices"] });
    },
    onError: (problem) => setError(problemMessage(problem)),
  });

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-start">
        <div>
          <h1 className="text-xl font-bold">Facturation</h1>
          <p className="text-[13px] text-ink-3">
            Proformas, factures et avoirs — établis, numérotés et validés dans SILARIS.
          </p>
        </div>
        {canCreate && (
          <Link href="/billing/new" className={`ml-auto ${buttonPrimary}`}>+ Nouvelle facture</Link>
        )}
      </div>
      {error && <p className="rounded-lg bg-crit-soft px-4 py-2.5 text-[13px] text-crit">{error}</p>}
      <div className="overflow-x-auto rounded-xl border border-line bg-surface shadow-sm">
        <table className="w-full text-[13px]">
          <thead>
            <tr className="border-b border-line text-left text-[10px] uppercase tracking-wider text-ink-3">
              <th className="px-3 py-2.5">Numéro</th>
              <th className="px-3 py-2.5">Type</th>
              <th className="px-3 py-2.5">Client</th>
              <th className="px-3 py-2.5">Dossier</th>
              <th className="px-3 py-2.5">Statut</th>
              <th className="px-3 py-2.5">Paiement</th>
              <th className="px-3 py-2.5 text-right">HT</th>
              <th className="px-3 py-2.5 text-right">TTC</th>
              <th className="px-3 py-2.5">Échéance</th>
              <th className="px-3 py-2.5" />
            </tr>
          </thead>
          <tbody>
            {isLoading && <tr><td colSpan={10} className="px-3 py-8 text-center text-ink-3">Chargement…</td></tr>}
            {data?.data.map((invoice) => {
              const [paymentLabel, paymentTone] = PAYMENT_LABEL[invoice.payment_status] ?? ["—", ""];
              return (
                <tr key={invoice.id} className="border-b border-line last:border-0 hover:bg-sea/5">
                  <td className="mono px-3 py-2.5 font-semibold text-sea">{invoice.number ?? "(brouillon)"}</td>
                  <td className="px-3 py-2.5 text-ink-2">{TYPE_LABEL[invoice.type]}</td>
                  <td className="px-3 py-2.5">{invoice.party.name}</td>
                  <td className="mono px-3 py-2.5 text-ink-2">{invoice.shipment?.reference ?? "—"}</td>
                  <td className="px-3 py-2.5">
                    <span className={`rounded-full px-2.5 py-0.5 text-[11px] font-semibold ${STATUS_TONE[invoice.status]}`}>
                      {invoice.status === "draft" ? "Brouillon" : invoice.status === "validated" ? "Validée" : invoice.status === "synced" ? "Exportée compta" : "Export à reprendre"}
                    </span>
                  </td>
                  <td className={`px-3 py-2.5 text-xs font-semibold ${paymentTone}`}>{paymentLabel}</td>
                  <td className="mono px-3 py-2.5 text-right">{Number(invoice.total_excl_tax).toLocaleString("fr-FR")}</td>
                  <td className="mono px-3 py-2.5 text-right font-semibold">
                    {Number(invoice.total_incl_tax).toLocaleString("fr-FR")} {invoice.currency_code}
                  </td>
                  <td className="mono px-3 py-2.5 text-ink-2">
                    {invoice.due_date ? new Date(invoice.due_date).toLocaleDateString("fr-FR") : "—"}
                  </td>
                  <td className="px-3 py-2.5">
                    <div className="flex items-center gap-3">
                      {invoice.status === "draft" && canValidate && (
                        <button
                          onClick={() => validate.mutate(invoice.id)}
                          disabled={validate.isPending}
                          className="text-xs font-semibold text-sea hover:underline"
                        >
                          Valider →
                        </button>
                      )}
                      {/* La certification n'a de sens qu'une fois la facture
                          validée, et une seule fois : le sceau la fige. */}
                      {invoice.status !== "draft" && invoice.type !== "proforma" && !invoice.fne_reference && canCertify && (
                        <button
                          onClick={() => certify.mutate(invoice)}
                          disabled={certify.isPending}
                          className="text-xs font-semibold text-sea hover:underline"
                        >
                          Certifier FNE
                        </button>
                      )}
                      {invoice.fne_reference && (
                        <span className="text-xs font-semibold text-ok" title={`Numéro fiscal ${invoice.fne_reference}`}>
                          ✓ FNE
                        </span>
                      )}
                      <button
                        onClick={() => downloadFile(`/v1/invoices/${invoice.id}/pdf`, "facture.pdf").catch(() => undefined)}
                        className="text-xs font-semibold text-ink-2 hover:text-ink hover:underline"
                        title="Télécharger le PDF"
                      >
                        PDF
                      </button>
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
