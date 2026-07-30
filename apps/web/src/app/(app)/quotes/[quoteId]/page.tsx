"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { useMemo, useState } from "react";
import { downloadFile, problemMessage, rawApi } from "@/lib/api";
import { buttonPrimary, buttonSecondary, inputClass } from "@/components/Field";
import { ServiceCatalogDatalist, useServiceCatalog } from "@/components/ServiceCatalog";
import { useCan } from "@/stores/auth";

type LineCategory = "customs" | "other";

interface QuoteLine {
  id?: string;
  category: LineCategory;
  service_code: string;
  description: string;
  quantity: string;
  unit: string;
  unit_price: string;
  currency_code: string;
  line_total?: string;
}

interface Quote {
  id: string;
  number: string;
  revision: number;
  status: string;
  mode: string;
  direction: string;
  origin_locode: string;
  destination_locode: string;
  incoterm_code: string;
  currency_code: string;
  total_amount: string;
  valid_until: string;
  approved_at: string | null;
  sent_at: string | null;
  rejection_reason: string | null;
  party: { id: string; name: string; code: string };
  lines: QuoteLine[];
}

const MODE_LABEL: Record<string, string> = {
  sea_fcl: "Maritime FCL", sea_lcl: "Maritime LCL", air: "Aérien", road: "Terrestre", multimodal: "Multimodal",
};
const STATUS_TONE: Record<string, string> = {
  draft: "bg-paper text-ink-3", sent: "bg-sea-soft text-sea",
  accepted: "bg-ok-soft text-ok", rejected: "bg-crit-soft text-crit", expired: "bg-warn-soft text-warn",
};
const STATUS_LABEL: Record<string, string> = {
  draft: "Brouillon", sent: "Envoyé", accepted: "Accepté", rejected: "Refusé", expired: "Expiré",
};
const UNITS = ["container", "kg", "m3", "wm", "flat", "percent", "unit"];

const fmt = (n: number, cur: string) =>
  `${new Intl.NumberFormat("fr-FR", { maximumFractionDigits: 0 }).format(n)} ${cur}`;

export default function QuoteDetailPage() {
  const params = useParams();
  const router = useRouter();
  const queryClient = useQueryClient();
  const quoteId = String(params.quoteId);

  const canApprove = useCan("quotes.approve");
  const canSend = useCan("quotes.send");
  const canAccept = useCan("quotes.accept");
  const canUpdate = useCan("quotes.update");
  const canInvoice = useCan("invoices.create");

  const [error, setError] = useState<string | null>(null);
  const [editing, setEditing] = useState(false);
  const [draftLines, setDraftLines] = useState<QuoteLine[]>([]);

  const { data: quote, isLoading } = useQuery({
    queryKey: ["quote", quoteId],
    queryFn: async () => {
      const { data } = await rawApi.GET(`/v1/quotes/${quoteId}`);
      return data as Quote;
    },
  });

  const invalidate = () => {
    void queryClient.invalidateQueries({ queryKey: ["quote", quoteId] });
    void queryClient.invalidateQueries({ queryKey: ["quotes"] });
  };

  const action = (verb: string, body?: unknown) =>
    useMutation({
      mutationFn: async () => {
        const { error: problem } = await rawApi.POST(`/v1/quotes/${quoteId}/${verb}`, body ? { body } : undefined);
        if (problem) throw problem;
      },
      onSuccess: () => { setError(null); invalidate(); },
      onError: (problem) => setError(problemMessage(problem)),
    });

  const approve = action("approve");
  const send = action("send");
  const accept = action("accept");

  const reject = useMutation({
    mutationFn: async () => {
      const reason = window.prompt("Motif du refus (facultatif) :") ?? undefined;
      const { error: problem } = await rawApi.POST(`/v1/quotes/${quoteId}/reject`, { body: { reason } });
      if (problem) throw problem;
    },
    onSuccess: () => { setError(null); invalidate(); },
    onError: (problem) => setError(problemMessage(problem)),
  });

  // Déverser la cotation acceptée dans un brouillon de facture, à l'identique :
  // la facture transcrit l'accord client, elle ne le réinvente pas.
  const invoice = useMutation({
    mutationFn: async () => {
      const { data, error: problem } = await rawApi.POST(`/v1/invoices/from-quote/${quoteId}`);
      if (problem) throw problem;
      return data as { id: string };
    },
    onSuccess: () => { setError(null); router.push("/billing"); },
    onError: (problem) => setError(problemMessage(problem)),
  });

  const save = useMutation({
    mutationFn: async () => {
      const { error: problem } = await rawApi.PATCH(`/v1/quotes/${quoteId}`, {
        body: {
          lines: draftLines.map((line) => ({
            service_code: line.service_code || "DIVERS",
            description: line.description,
            quantity: Number(line.quantity) || 0,
            unit: line.unit,
            unit_price: Number(line.unit_price) || 0,
            currency_code: line.currency_code,
            category: line.category,
          })),
        },
      });
      if (problem) throw problem;
    },
    onSuccess: () => { setError(null); setEditing(false); invalidate(); },
    onError: (problem) => setError(problemMessage(problem)),
  });

  const groups = useMemo(() => {
    if (!quote) return [];
    const source = quote.lines;
    const byCat = (cat: LineCategory) => source.filter((l) => (l.category ?? "other") === cat);
    return ([
      { key: "customs" as const, title: "Débours douane", label: "Total débours douane" },
      { key: "other" as const, title: "Débours divers", label: "Total débours divers" },
    ]).map((g) => ({ ...g, lines: byCat(g.key) })).filter((g) => g.lines.length > 0);
  }, [quote]);

  if (isLoading) return <div className="text-ink-3">Chargement…</div>;
  if (!quote) return <div className="text-ink-3">Cotation introuvable.</div>;

  const isDraft = quote.status === "draft";
  const grouped = groups.length > 1 && !editing;

  const startEdit = () => {
    setDraftLines(quote.lines.map((l) => ({
      category: l.category ?? "other",
      service_code: l.service_code,
      description: l.description,
      quantity: String(l.quantity),
      unit: l.unit,
      unit_price: String(l.unit_price),
      currency_code: l.currency_code,
    })));
    setEditing(true);
    setError(null);
  };

  const editedTotal = draftLines.reduce((sum, l) => sum + (Number(l.quantity) || 0) * (Number(l.unit_price) || 0), 0);

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-start gap-3">
        <div>
          <Link href="/quotes" className="text-[12px] text-ink-3 hover:underline">← Cotations</Link>
          <h1 className="mono mt-1 text-xl font-bold">
            {quote.number}{quote.revision > 1 && <span className="text-ink-3"> rév. {quote.revision}</span>}
          </h1>
          <p className="text-[13px] text-ink-3">
            {quote.party.name} <span className="text-ink-3">({quote.party.code})</span> · {MODE_LABEL[quote.mode] ?? quote.mode} ·{" "}
            {quote.origin_locode} → {quote.destination_locode} · Incoterm {quote.incoterm_code}
          </p>
        </div>
        <span className={`ml-auto rounded-full px-2.5 py-1 text-[11px] font-semibold ${STATUS_TONE[quote.status]}`}>
          {STATUS_LABEL[quote.status]}
        </span>
      </div>

      {/* Bandeau d'état de validation : c'est lui qui donne à voir le workflow,
          invisible jusqu'ici faute d'écran de détail. */}
      <WorkflowBanner quote={quote} />

      {error && <div className="rounded-lg bg-crit-soft px-3 py-2 text-[13px] text-crit">{error}</div>}

      {!editing && (
        <div className="flex flex-wrap gap-2">
          {isDraft && !quote.approved_at && canApprove && (
            <button onClick={() => approve.mutate()} disabled={approve.isPending} className={buttonPrimary}>
              {approve.isPending ? "…" : "Valider la cotation"}
            </button>
          )}
          {isDraft && quote.approved_at && canSend && (
            <button onClick={() => send.mutate()} disabled={send.isPending} className={buttonPrimary}>
              {send.isPending ? "…" : "Transmettre au client"}
            </button>
          )}
          {quote.status === "sent" && canAccept && (
            <>
              <button onClick={() => accept.mutate()} disabled={accept.isPending} className={buttonPrimary}>
                Marquer acceptée
              </button>
              <button onClick={() => reject.mutate()} disabled={reject.isPending} className={buttonSecondary}>
                Marquer refusée
              </button>
            </>
          )}
          {quote.status === "accepted" && canInvoice && (
            <button onClick={() => invoice.mutate()} disabled={invoice.isPending} className={buttonPrimary}>
              {invoice.isPending ? "…" : "Facturer"}
            </button>
          )}
          {isDraft && canUpdate && (
            <button onClick={startEdit} className={buttonSecondary}>Modifier</button>
          )}
          <button
            onClick={() => downloadFile(`/v1/quotes/${quote.id}/pdf`, `${quote.number}.pdf`).catch(() => undefined)}
            className={buttonSecondary}
          >
            PDF
          </button>
        </div>
      )}

      {editing ? (
        <LineEditor
          lines={draftLines}
          currency={quote.currency_code}
          onChange={setDraftLines}
          total={editedTotal}
          saving={save.isPending}
          onSave={() => save.mutate()}
          onCancel={() => { setEditing(false); setError(null); }}
        />
      ) : (
        <div className="overflow-x-auto rounded-xl border border-line bg-surface shadow-sm">
          <table className="w-full text-[13px]">
            <thead>
              <tr className="border-b border-line text-left text-[10px] uppercase tracking-wider text-ink-3">
                <th className="px-3 py-2.5">Désignation</th>
                <th className="px-3 py-2.5 text-right">Quantité</th>
                <th className="px-3 py-2.5">Unité</th>
                <th className="px-3 py-2.5 text-right">P.U.</th>
                <th className="px-3 py-2.5 text-right">Total</th>
              </tr>
            </thead>
            <tbody>
              {(grouped ? groups : [{ key: "all", title: "", label: "", lines: quote.lines }]).map((group) => (
                <QuoteGroup key={group.key} group={group} grouped={grouped} currency={quote.currency_code} />
              ))}
              <tr className="border-t-2 border-ink">
                <td colSpan={4} className="px-3 py-3 text-right text-[15px] font-bold">
                  {grouped ? "Net à payer" : "Total"}
                </td>
                <td className="mono px-3 py-3 text-right text-[15px] font-bold">
                  {fmt(Number(quote.total_amount), quote.currency_code)}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}

function WorkflowBanner({ quote }: { quote: Quote }) {
  if (quote.status === "accepted") {
    return <Banner tone="ok">Cotation acceptée par le client — un dossier peut être ouvert dessus.</Banner>;
  }
  if (quote.status === "rejected") {
    return <Banner tone="crit">Cotation refusée{quote.rejection_reason ? ` : ${quote.rejection_reason}` : "."}</Banner>;
  }
  if (quote.status === "sent") {
    return <Banner tone="sea">Transmise au client{quote.sent_at ? ` le ${new Date(quote.sent_at).toLocaleDateString("fr-FR")}` : ""} — en attente de sa décision.</Banner>;
  }
  if (quote.approved_at) {
    return <Banner tone="ok">Validée en interne — prête à être transmise au client.</Banner>;
  }
  return <Banner tone="warn">En attente de validation par le directeur, l&apos;administration ou le responsable commercial, avant transmission au client.</Banner>;
}

function Banner({ tone, children }: { tone: "ok" | "warn" | "sea" | "crit"; children: React.ReactNode }) {
  const cls: Record<string, string> = {
    ok: "bg-ok-soft text-ok", warn: "bg-warn-soft text-warn", sea: "bg-sea-soft text-sea", crit: "bg-crit-soft text-crit",
  };
  return <div className={`rounded-lg px-3 py-2 text-[13px] ${cls[tone]}`}>{children}</div>;
}

function QuoteGroup({
  group, grouped, currency,
}: {
  group: { title: string; label: string; lines: QuoteLine[] };
  grouped: boolean;
  currency: string;
}) {
  const subtotal = group.lines.reduce((sum, l) => sum + Number(l.line_total ?? (Number(l.quantity) * Number(l.unit_price))), 0);
  return (
    <>
      {grouped && (
        <tr><td colSpan={5} className="bg-paper px-3 py-2 text-[10px] font-bold uppercase tracking-wider text-ink-2">{group.title}</td></tr>
      )}
      {group.lines.map((line, i) => (
        <tr key={line.id ?? i} className="border-b border-line last:border-0">
          <td className="px-3 py-2.5">{line.description}</td>
          <td className="mono px-3 py-2.5 text-right text-ink-2">{Number(line.quantity)}</td>
          <td className="px-3 py-2.5 text-ink-2">{line.unit}</td>
          <td className="mono px-3 py-2.5 text-right text-ink-2">{fmt(Number(line.unit_price), currency)}</td>
          <td className="mono px-3 py-2.5 text-right">{fmt(Number(line.line_total ?? Number(line.quantity) * Number(line.unit_price)), currency)}</td>
        </tr>
      ))}
      {grouped && (
        <tr className="border-b border-line bg-surface font-semibold">
          <td colSpan={4} className="px-3 py-2 text-right">{group.label}</td>
          <td className="mono px-3 py-2 text-right">{fmt(subtotal, currency)}</td>
        </tr>
      )}
    </>
  );
}

function LineEditor({
  lines, currency, onChange, total, saving, onSave, onCancel,
}: {
  lines: QuoteLine[];
  currency: string;
  onChange: (lines: QuoteLine[]) => void;
  total: number;
  saving: boolean;
  onSave: () => void;
  onCancel: () => void;
}) {
  const catalog = useServiceCatalog();
  const update = (index: number, patch: Partial<QuoteLine>) =>
    onChange(lines.map((line, i) => (i === index ? { ...line, ...patch } : line)));
  // Poste connu → code + famille renseignés ; ligne libre préservée.
  const setDescription = (index: number, value: string) => {
    const item = catalog.resolve(value);
    update(index, item ? { description: item.label, service_code: item.code, category: item.family } : { description: value });
  };
  const remove = (index: number) => onChange(lines.filter((_, i) => i !== index));
  const add = (category: LineCategory) =>
    onChange([...lines, { category, service_code: "", description: "", quantity: "1", unit: "flat", unit_price: "0", currency_code: currency }]);

  return (
    <div className="flex flex-col gap-3 rounded-xl border border-line bg-surface p-4 shadow-sm">
      <ServiceCatalogDatalist items={catalog.items} />
      <div className="overflow-x-auto">
        <table className="w-full text-[13px]">
          <thead>
            <tr className="border-b border-line text-left text-[10px] uppercase tracking-wider text-ink-3">
              <th className="px-2 py-2">Famille</th>
              <th className="px-2 py-2">Désignation</th>
              <th className="px-2 py-2 text-right">Quantité</th>
              <th className="px-2 py-2">Unité</th>
              <th className="px-2 py-2 text-right">P.U.</th>
              <th className="px-2 py-2" />
            </tr>
          </thead>
          <tbody>
            {lines.map((line, i) => (
              <tr key={i} className="border-b border-line last:border-0">
                <td className="px-2 py-1.5">
                  <select value={line.category} onChange={(e) => update(i, { category: e.target.value as LineCategory })} className={`${inputClass} !py-1`}>
                    <option value="customs">Douane</option>
                    <option value="other">Divers</option>
                  </select>
                </td>
                <td className="px-2 py-1.5">
                  <input list="service-catalog" value={line.description} onChange={(e) => setDescription(i, e.target.value)} className={`${inputClass} !py-1`} />
                </td>
                <td className="px-2 py-1.5">
                  <input type="number" min="0" step="0.001" value={line.quantity} onChange={(e) => update(i, { quantity: e.target.value })} className={`${inputClass} mono !py-1 text-right`} />
                </td>
                <td className="px-2 py-1.5">
                  <select value={line.unit} onChange={(e) => update(i, { unit: e.target.value })} className={`${inputClass} !py-1`}>
                    {UNITS.map((u) => <option key={u} value={u}>{u}</option>)}
                  </select>
                </td>
                <td className="px-2 py-1.5">
                  <input type="number" min="0" step="1" value={line.unit_price} onChange={(e) => update(i, { unit_price: e.target.value })} className={`${inputClass} mono !py-1 text-right`} />
                </td>
                <td className="px-2 py-1.5 text-right">
                  <button onClick={() => remove(i)} className="text-[12px] text-crit hover:underline">Retirer</button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div className="flex flex-wrap items-center gap-2">
        <button onClick={() => add("customs")} className="rounded-lg border border-line-strong px-2.5 py-1 text-[12px] hover:bg-paper">+ Ligne douane</button>
        <button onClick={() => add("other")} className="rounded-lg border border-line-strong px-2.5 py-1 text-[12px] hover:bg-paper">+ Ligne divers</button>
        <span className="ml-auto text-[13px]">Total <span className="mono font-bold">{fmt(total, currency)}</span></span>
      </div>

      <p className="text-[12px] text-warn">La modification annule la validation en cours : la cotation devra être re-validée avant d&apos;être transmise.</p>

      <div className="flex gap-2">
        <button onClick={onSave} disabled={saving || lines.length === 0} className={buttonPrimary}>
          {saving ? "Enregistrement…" : "Enregistrer"}
        </button>
        <button onClick={onCancel} className={buttonSecondary}>Annuler</button>
      </div>
    </div>
  );
}
