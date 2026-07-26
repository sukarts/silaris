"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { downloadFile, rawApi } from "@/lib/api";
import { PortalShell } from "@/components/PortalShell";
import { buttonPrimary, buttonSecondary } from "@/components/Field";

interface PortalQuote {
  id: string;
  number: string;
  status: string;
  origin_locode: string;
  destination_locode: string;
  currency_code: string;
  total_amount: string;
  valid_until: string;
  lines: { id: string; description: string; quantity: string; unit_price: string; line_total: string }[];
}

export default function PortalQuotesPage() {
  const queryClient = useQueryClient();
  const { data } = useQuery({
    queryKey: ["portal", "quotes"],
    queryFn: async () => {
      const { data: response } = await rawApi.GET("/v1/portal/quotes");
      return (response as { data: PortalQuote[] }).data;
    },
  });

  const act = useMutation({
    mutationFn: async ({ quoteId, action }: { quoteId: string; action: "accept" | "reject" }) => {
      const { error } = await rawApi.POST(`/v1/portal/quotes/${quoteId}/${action}`);
      if (error) throw error;
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["portal", "quotes"] }),
  });

  return (
    <PortalShell>
      <h1 className="pb-4 text-xl font-bold">Mes devis</h1>
      <div className="flex flex-col gap-4">
        {data?.length === 0 && (
          <p className="rounded-xl border border-line bg-surface p-6 text-center text-[13px] text-ink-3">Aucun devis.</p>
        )}
        {data?.map((quote) => (
          <div key={quote.id} className="rounded-xl border border-line bg-surface shadow-sm">
            <div className="flex flex-wrap items-center gap-3 border-b border-line px-4 py-3">
              <span className="mono text-[13px] font-bold text-sea">{quote.number}</span>
              <span className="mono text-xs text-ink-3">{quote.origin_locode} → {quote.destination_locode}</span>
              <span className="text-xs text-ink-3">valide jusqu'au {new Date(quote.valid_until).toLocaleDateString("fr-FR")}</span>
              <span className="ml-auto mono text-sm font-bold">
                {Number(quote.total_amount).toLocaleString("fr-FR")} {quote.currency_code}
              </span>
              <button
                onClick={() => downloadFile(`/v1/portal/quotes/${quote.id}/pdf`, "cotation.pdf").catch(() => undefined)}
                className="text-xs font-semibold text-sea hover:underline"
              >
                PDF
              </button>
            </div>
            <ul className="px-4 py-2 text-[13px]">
              {quote.lines.map((line) => (
                <li key={line.id} className="flex justify-between border-b border-line py-1.5 last:border-0">
                  <span>{line.description}</span>
                  <span className="mono text-ink-2">{Number(line.line_total).toLocaleString("fr-FR")}</span>
                </li>
              ))}
            </ul>
            {quote.status === "sent" ? (
              <div className="flex gap-2 border-t border-line px-4 py-3">
                <button
                  onClick={() => act.mutate({ quoteId: quote.id, action: "accept" })}
                  disabled={act.isPending}
                  className={buttonPrimary}
                >
                  Accepter le devis
                </button>
                <button
                  onClick={() => act.mutate({ quoteId: quote.id, action: "reject" })}
                  disabled={act.isPending}
                  className={buttonSecondary}
                >
                  Refuser
                </button>
              </div>
            ) : (
              <div className="border-t border-line px-4 py-2.5 text-xs font-semibold text-ink-2">
                {quote.status === "accepted" ? "✓ Accepté" : quote.status === "rejected" ? "✗ Refusé" : quote.status}
              </div>
            )}
          </div>
        ))}
      </div>
    </PortalShell>
  );
}
