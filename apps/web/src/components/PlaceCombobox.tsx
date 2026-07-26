"use client";

import { useEffect, useRef, useState } from "react";
import { rawApi } from "@/lib/api";
import { inputClass } from "@/components/Field";

interface Suggestion {
  code: string;
  label: string;
  sub: string;
}

interface PortRow {
  locode: string;
  name: string;
  country_code: string;
}

interface AirportRow {
  iata: string;
  name: string;
  country_code: string;
}

/**
 * Combobox ports/aéroports : suggestions dès 2 caractères (code ou nom),
 * navigation clavier, saisie libre conservée (un code absent du référentiel
 * reste accepté — l'API valide en dernier ressort).
 */
export function PlaceCombobox({
  referential,
  value,
  onChange,
  placeholder,
  required,
  maxLength,
}: {
  referential: "ports" | "airports";
  value: string;
  onChange: (code: string) => void;
  placeholder?: string;
  required?: boolean;
  maxLength?: number;
}) {
  const [suggestions, setSuggestions] = useState<Suggestion[]>([]);
  const [open, setOpen] = useState(false);
  const [selected, setSelected] = useState(0);
  const timer = useRef<ReturnType<typeof setTimeout>>(undefined);
  const wrapperRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    function onClickOutside(event: MouseEvent) {
      if (!wrapperRef.current?.contains(event.target as Node)) setOpen(false);
    }
    document.addEventListener("mousedown", onClickOutside);
    return () => document.removeEventListener("mousedown", onClickOutside);
  }, []);

  function search(term: string) {
    clearTimeout(timer.current);
    if (term.trim().length < 2) {
      setSuggestions([]);
      setOpen(false);
      return;
    }
    timer.current = setTimeout(async () => {
      const { data } = await rawApi.GET(`/v1/referentials/${referential}`, {
        params: { query: { search: term.trim(), per_page: 8 } },
      });
      const rows = (data as { data: (PortRow | AirportRow)[] } | undefined)?.data ?? [];
      const mapped = rows.map((row) =>
        referential === "ports"
          ? { code: (row as PortRow).locode, label: row.name, sub: row.country_code }
          : { code: (row as AirportRow).iata, label: row.name, sub: row.country_code },
      );
      setSuggestions(mapped);
      setSelected(0);
      setOpen(mapped.length > 0);
    }, 200);
  }

  function pick(suggestion: Suggestion) {
    onChange(suggestion.code);
    setOpen(false);
  }

  return (
    <div ref={wrapperRef} className="relative">
      <input
        required={required}
        value={value}
        maxLength={maxLength}
        placeholder={placeholder}
        onChange={(e) => {
          onChange(e.target.value.toUpperCase());
          search(e.target.value);
        }}
        onFocus={(e) => search(e.target.value)}
        onKeyDown={(e) => {
          if (!open) return;
          if (e.key === "ArrowDown") { e.preventDefault(); setSelected((i) => Math.min(i + 1, suggestions.length - 1)); }
          if (e.key === "ArrowUp") { e.preventDefault(); setSelected((i) => Math.max(i - 1, 0)); }
          if (e.key === "Enter" && suggestions[selected]) { e.preventDefault(); pick(suggestions[selected]); }
          if (e.key === "Escape") setOpen(false);
        }}
        className={`${inputClass} mono uppercase`}
        autoComplete="off"
      />
      {open && (
        <ul className="absolute z-20 mt-1 max-h-56 w-full min-w-56 overflow-y-auto rounded-lg border border-line bg-surface py-1 shadow-lg">
          {suggestions.map((suggestion, index) => (
            <li key={suggestion.code}>
              <button
                type="button"
                onMouseDown={(e) => { e.preventDefault(); pick(suggestion); }}
                className={`flex w-full items-baseline gap-2 px-3 py-1.5 text-left text-[13px] ${
                  index === selected ? "bg-sea/10" : "hover:bg-paper"
                }`}
              >
                <span className="mono font-semibold text-sea">{suggestion.code}</span>
                <span className="truncate">{suggestion.label}</span>
                <span className="ml-auto text-[10px] text-ink-3">{suggestion.sub}</span>
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
