"use client";

import { useQuery } from "@tanstack/react-query";
import Link from "next/link";
import { rawApi } from "@/lib/api";
import { buttonPrimary } from "@/components/Field";
import { useCan } from "@/stores/auth";

interface Quote {
  id: string;
  number: string;
  status: string;
  mode: string;
  direction: string;
  origin_locode: string;
  destination_locode: string;
  currency_code: string;
  total_amount: string;
  total_buy_amount: string | null;
  valid_until: string;
  party: { name: string };
}

const STATUS_TONE: Record<string, string> = {
  draft: "bg-paper text-ink-3",
  sent: "bg-sea-soft text-sea",
  accepted: "bg-ok-soft text-ok",
  rejected: "bg-crit-soft text-crit",
  expired: "bg-warn-soft text-warn",
};
const STATUS_LABEL: Record<string, string> = {
  draft: "Brouillon", sent: "Envoyé", accepted: "Accepté", rejected: "Refusé", expired: "Expiré",
};

export default function QuotesPage() {
  const canCreate = useCan("quotes.create");
  const { data, isLoading } = useQuery({
    queryKey: ["quotes"],
    queryFn: async () => {
      const { data: response } = await rawApi.GET("/v1/quotes");
      return response as { data: Quote[] };
    },
  });

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-start">
        <div>
          <h1 className="text-xl font-bold">Cotations</h1>
          <p className="text-[13px] text-ink-3">Devis et simulations tarifaires</p>
        </div>
        {canCreate && (
          <Link href="/quotes/new" className={`ml-auto ${buttonPrimary}`}>+ Nouvelle cotation</Link>
        )}
      </div>
      <div className="overflow-x-auto rounded-xl border border-line bg-surface shadow-sm">
        <table className="w-full text-[13px]">
          <thead>
            <tr className="border-b border-line text-left text-[10px] uppercase tracking-wider text-ink-3">
              <th className="px-3 py-2.5">Numéro</th>
              <th className="px-3 py-2.5">Client</th>
              <th className="px-3 py-2.5">Trajet</th>
              <th className="px-3 py-2.5">Statut</th>
              <th className="px-3 py-2.5 text-right">Montant</th>
              <th className="px-3 py-2.5 text-right">Marge est.</th>
              <th className="px-3 py-2.5">Validité</th>
            </tr>
          </thead>
          <tbody>
            {isLoading && <tr><td colSpan={7} className="px-3 py-8 text-center text-ink-3">Chargement…</td></tr>}
            {data?.data.map((quote) => {
              const margin = quote.total_buy_amount !== null
                ? Number(quote.total_amount) - Number(quote.total_buy_amount)
                : null;
              return (
                <tr key={quote.id} className="border-b border-line last:border-0 hover:bg-sea/5">
                  <td className="mono px-3 py-2.5 font-semibold text-sea">{quote.number}</td>
                  <td className="px-3 py-2.5">{quote.party.name}</td>
                  <td className="mono px-3 py-2.5 text-ink-2">{quote.origin_locode} → {quote.destination_locode}</td>
                  <td className="px-3 py-2.5">
                    <span className={`rounded-full px-2.5 py-0.5 text-[11px] font-semibold ${STATUS_TONE[quote.status]}`}>
                      {STATUS_LABEL[quote.status]}
                    </span>
                  </td>
                  <td className="mono px-3 py-2.5 text-right">
                    {Number(quote.total_amount).toLocaleString("fr-FR")} {quote.currency_code}
                  </td>
                  <td className={`mono px-3 py-2.5 text-right ${margin !== null && margin > 0 ? "text-ok" : "text-ink-3"}`}>
                    {margin !== null ? `${margin.toLocaleString("fr-FR")}` : "—"}
                  </td>
                  <td className="mono px-3 py-2.5 text-ink-2">{new Date(quote.valid_until).toLocaleDateString("fr-FR")}</td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </div>
  );
}
