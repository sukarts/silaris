"use client";

import { useRouter } from "next/navigation";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { rawApi } from "@/lib/api";

interface Hit {
  id: string;
  label: string | null;
  sub: string | null;
  url: string;
}

type Groups = Record<string, Hit[]>;

const GROUP_LABELS: Record<string, string> = {
  shipments: "Dossiers",
  parties: "Clients & fournisseurs",
  containers: "Conteneurs",
  bookings: "Bookings",
  invoices: "Factures",
};

export function SearchPalette({ open, onClose }: { open: boolean; onClose: () => void }) {
  const router = useRouter();
  const [query, setQuery] = useState("");
  const [groups, setGroups] = useState<Groups>({});
  const [loading, setLoading] = useState(false);
  const [selected, setSelected] = useState(0);
  const inputRef = useRef<HTMLInputElement>(null);
  const timer = useRef<ReturnType<typeof setTimeout>>(undefined);

  const flat = useMemo(
    () => Object.entries(groups).flatMap(([type, hits]) => hits.map((hit) => ({ ...hit, type }))),
    [groups],
  );

  useEffect(() => {
    if (open) {
      setQuery("");
      setGroups({});
      setSelected(0);
      setTimeout(() => inputRef.current?.focus(), 30);
    }
  }, [open]);

  useEffect(() => {
    clearTimeout(timer.current);
    if (query.trim().length < 2) {
      setGroups({});
      return;
    }
    timer.current = setTimeout(async () => {
      setLoading(true);
      const { data } = await rawApi.GET("/v1/search", { params: { query: { q: query.trim() } } });
      setLoading(false);
      const response = data as { groups: Groups } | undefined;
      setGroups(response?.groups ?? {});
      setSelected(0);
    }, 250);
    return () => clearTimeout(timer.current);
  }, [query]);

  const go = useCallback(
    (hit: Hit) => {
      onClose();
      router.push(hit.url);
    },
    [onClose, router],
  );

  useEffect(() => {
    if (!open) return;
    function onKey(event: KeyboardEvent) {
      if (event.key === "Escape") onClose();
      if (event.key === "ArrowDown") { event.preventDefault(); setSelected((i) => Math.min(i + 1, flat.length - 1)); }
      if (event.key === "ArrowUp") { event.preventDefault(); setSelected((i) => Math.max(i - 1, 0)); }
      if (event.key === "Enter" && flat[selected]) { event.preventDefault(); go(flat[selected]); }
    }
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [open, flat, selected, go, onClose]);

  if (!open) return null;

  let cursor = -1;

  return (
    <div className="fixed inset-0 z-50 bg-black/40 p-4 pt-[12vh]" onClick={onClose}>
      <div
        className="mx-auto w-full max-w-xl overflow-hidden rounded-xl border border-line bg-surface shadow-xl"
        onClick={(event) => event.stopPropagation()}
      >
        <input
          ref={inputRef}
          value={query}
          onChange={(event) => setQuery(event.target.value)}
          placeholder="Rechercher dossier, client, conteneur, booking, facture…"
          className="w-full border-b border-line bg-transparent px-4 py-3.5 text-sm text-ink placeholder:text-ink-3 focus:outline-none"
        />
        <div className="max-h-[50vh] overflow-y-auto p-2">
          {loading && <p className="px-3 py-4 text-center text-xs text-ink-3">Recherche…</p>}
          {!loading && query.trim().length >= 2 && flat.length === 0 && (
            <p className="px-3 py-4 text-center text-xs text-ink-3">Aucun résultat pour « {query.trim()} »</p>
          )}
          {Object.entries(groups).map(([type, hits]) =>
            hits.length === 0 ? null : (
              <div key={type} className="mb-1">
                <p className="px-3 pb-1 pt-2 text-[10px] uppercase tracking-[0.12em] text-ink-3">
                  {GROUP_LABELS[type] ?? type}
                </p>
                {hits.map((hit) => {
                  cursor++;
                  const active = cursor === selected;
                  return (
                    <button
                      key={hit.id}
                      onClick={() => go(hit)}
                      className={`flex w-full items-baseline gap-2 rounded-lg px-3 py-2 text-left text-[13px] ${
                        active ? "bg-sea/10 text-ink" : "text-ink-2 hover:bg-paper"
                      }`}
                    >
                      <span className="mono font-semibold text-sea">{hit.label ?? "—"}</span>
                      {hit.sub && <span className="text-xs text-ink-3">{hit.sub}</span>}
                    </button>
                  );
                })}
              </div>
            ),
          )}
        </div>
        <div className="border-t border-line px-4 py-2 text-[10px] text-ink-3">
          ↑↓ naviguer · Entrée ouvrir · Échap fermer
        </div>
      </div>
    </div>
  );
}
