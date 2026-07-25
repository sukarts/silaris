export function Field({
  label,
  children,
  className = "",
}: {
  label: string;
  children: React.ReactNode;
  className?: string;
}) {
  return (
    <label className={`flex flex-col gap-1 text-xs font-semibold uppercase tracking-wide text-ink-3 ${className}`}>
      {label}
      {children}
    </label>
  );
}

export const inputClass =
  "w-full rounded-lg border border-line-strong bg-paper px-3 py-2 text-sm font-normal normal-case tracking-normal text-ink placeholder:text-ink-3 focus:outline-2 focus:outline-accent disabled:opacity-50";

export const buttonPrimary =
  "rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-white hover:brightness-105 disabled:opacity-60";

export const buttonSecondary =
  "rounded-lg border border-line-strong bg-surface px-4 py-2 text-sm font-semibold text-ink-2 hover:bg-paper disabled:opacity-60";
