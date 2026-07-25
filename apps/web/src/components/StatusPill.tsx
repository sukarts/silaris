const VARIANTS: Record<string, string> = {
  transit: "bg-sea-soft text-sea",
  ok: "bg-ok-soft text-ok",
  warn: "bg-warn-soft text-warn",
  crit: "bg-crit-soft text-crit",
  muted: "bg-paper text-ink-3",
};

const STATUS_VARIANT: Record<string, keyof typeof VARIANTS> = {
  creation: "muted",
  booking: "muted",
  departure: "transit",
  transit: "transit",
  arrival: "transit",
  customs: "warn",
  delivery: "ok",
  closure: "ok",
};

const STATUS_LABEL: Record<string, string> = {
  creation: "Création",
  booking: "Booking",
  departure: "Départ",
  transit: "En transit",
  arrival: "Arrivée",
  customs: "Douane",
  delivery: "Livraison",
  closure: "Clôturé",
};

export function StatusPill({ status }: { status: string }) {
  const variant = VARIANTS[STATUS_VARIANT[status] ?? "muted"];
  return (
    <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-[11px] font-semibold ${variant}`}>
      <span className="size-1.5 rounded-full bg-current" />
      {STATUS_LABEL[status] ?? status}
    </span>
  );
}
