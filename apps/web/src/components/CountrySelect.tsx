"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import { rawApi } from "@/lib/api";
import { inputClass } from "@/components/Field";

interface Country {
  code2: string;
  name_fr: string;
}

/** Drapeau emoji depuis le code ISO-2 (indicateurs régionaux Unicode). */
export function flagEmoji(code2: string): string {
  if (!/^[A-Za-z]{2}$/.test(code2)) return "";
  return String.fromCodePoint(...[...code2.toUpperCase()].map((c) => 0x1f1e6 + c.charCodeAt(0) - 65));
}

let countriesCache: Country[] | null = null;

/**
 * Liste déroulante des pays : liste complète au clic (drapeaux + noms),
 * champ de recherche intégré, valeur = code ISO-2.
 */
export function CountrySelect({
  value,
  onChange,
  required,
  placeholder = "Sélectionner un pays…",
}: {
  value: string;
  onChange: (code2: string) => void;
  required?: boolean;
  placeholder?: string;
}) {
  const [countries, setCountries] = useState<Country[]>(countriesCache ?? []);
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");
  const wrapperRef = useRef<HTMLDivElement>(null);
  const searchRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    if (countriesCache) return;
    rawApi.GET("/v1/referentials/countries", { params: { query: { per_page: 500 } } }).then(({ data }) => {
      const rows = (data as { data: Country[] } | undefined)?.data ?? [];
      countriesCache = rows.sort((a, b) => a.name_fr.localeCompare(b.name_fr, "fr"));
      setCountries(countriesCache);
    });
  }, []);

  useEffect(() => {
    function onClickOutside(event: MouseEvent) {
      if (!wrapperRef.current?.contains(event.target as Node)) setOpen(false);
    }
    document.addEventListener("mousedown", onClickOutside);
    return () => document.removeEventListener("mousedown", onClickOutside);
  }, []);

  useEffect(() => {
    if (open) setTimeout(() => searchRef.current?.focus(), 20);
  }, [open]);

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    if (q === "") return countries;
    return countries.filter((c) => c.name_fr.toLowerCase().includes(q) || c.code2.toLowerCase().includes(q));
  }, [countries, query]);

  const current = countries.find((c) => c.code2 === value);

  return (
    <div ref={wrapperRef} className="relative">
      <button
        type="button"
        onClick={() => { setOpen((o) => !o); setQuery(""); }}
        className={`${inputClass} flex items-center gap-2 text-left`}
      >
        {current ? (
          <>
            <span>{flagEmoji(current.code2)}</span>
            <span className="truncate">{current.name_fr}</span>
            <span className="mono ml-auto text-[10px] text-ink-3">{current.code2}</span>
          </>
        ) : (
          <span className="text-ink-3">{placeholder}</span>
        )}
      </button>
      {required && <input tabIndex={-1} className="sr-only" required value={value} onChange={() => undefined} />}
      {open && (
        <div className="absolute z-20 mt-1 w-full min-w-64 rounded-lg border border-line bg-surface shadow-lg">
          <input
            ref={searchRef}
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder="Rechercher…"
            className="w-full border-b border-line bg-transparent px-3 py-2 text-[13px] text-ink placeholder:text-ink-3 focus:outline-none"
          />
          <ul className="max-h-56 overflow-y-auto py-1">
            {filtered.map((country) => (
              <li key={country.code2}>
                <button
                  type="button"
                  onMouseDown={(e) => { e.preventDefault(); onChange(country.code2); setOpen(false); }}
                  className={`flex w-full items-center gap-2 px-3 py-1.5 text-left text-[13px] ${
                    country.code2 === value ? "bg-sea/10" : "hover:bg-paper"
                  }`}
                >
                  <span>{flagEmoji(country.code2)}</span>
                  <span className="truncate">{country.name_fr}</span>
                  <span className="mono ml-auto text-[10px] text-ink-3">{country.code2}</span>
                </button>
              </li>
            ))}
            {filtered.length === 0 && <li className="px-3 py-2 text-xs text-ink-3">Aucun pays trouvé.</li>}
          </ul>
        </div>
      )}
    </div>
  );
}

/** Indicatifs téléphoniques courants (Afrique de l'Ouest en tête). */
export const DIAL_CODES: { code2: string; dial: string }[] = [
  { code2: "CI", dial: "+225" }, { code2: "SN", dial: "+221" }, { code2: "ML", dial: "+223" },
  { code2: "BF", dial: "+226" }, { code2: "GN", dial: "+224" }, { code2: "GH", dial: "+233" },
  { code2: "NG", dial: "+234" }, { code2: "TG", dial: "+228" }, { code2: "BJ", dial: "+229" },
  { code2: "NE", dial: "+227" }, { code2: "MR", dial: "+222" }, { code2: "LR", dial: "+231" },
  { code2: "SL", dial: "+232" }, { code2: "GM", dial: "+220" }, { code2: "GW", dial: "+245" },
  { code2: "CM", dial: "+237" }, { code2: "GA", dial: "+241" }, { code2: "CG", dial: "+242" },
  { code2: "CD", dial: "+243" }, { code2: "MA", dial: "+212" }, { code2: "DZ", dial: "+213" },
  { code2: "TN", dial: "+216" }, { code2: "EG", dial: "+20" }, { code2: "ZA", dial: "+27" },
  { code2: "KE", dial: "+254" }, { code2: "ET", dial: "+251" }, { code2: "FR", dial: "+33" },
  { code2: "BE", dial: "+32" }, { code2: "DE", dial: "+49" }, { code2: "ES", dial: "+34" },
  { code2: "IT", dial: "+39" }, { code2: "GB", dial: "+44" }, { code2: "NL", dial: "+31" },
  { code2: "PT", dial: "+351" }, { code2: "US", dial: "+1" }, { code2: "CA", dial: "+1" },
  { code2: "BR", dial: "+55" }, { code2: "CN", dial: "+86" }, { code2: "IN", dial: "+91" },
  { code2: "AE", dial: "+971" }, { code2: "SA", dial: "+966" }, { code2: "TR", dial: "+90" },
];

/** Sélecteur d'indicatif téléphonique (drapeau + indicatif). */
export function DialCodeSelect({ value, onChange }: { value: string; onChange: (dial: string) => void }) {
  return (
    <select value={value} onChange={(e) => onChange(e.target.value)} className={`${inputClass} w-28`}>
      {DIAL_CODES.map(({ code2, dial }) => (
        <option key={code2 + dial} value={dial}>
          {flagEmoji(code2)} {dial}
        </option>
      ))}
    </select>
  );
}
