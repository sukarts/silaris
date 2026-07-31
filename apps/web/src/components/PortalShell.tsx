"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { rawApi } from "@/lib/api";
import { useAuth } from "@/stores/auth";

const NAV = [
  { href: "/portal", label: "Mes expéditions", exact: true },
  { href: "/portal/documents", label: "Documents" },
  { href: "/portal/invoices", label: "Factures & règlements" },
  { href: "/portal/quotes", label: "Devis" },
];

export function PortalShell({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const pathname = usePathname();
  const { token, kind, user, clear } = useAuth();
  const [hydrated, setHydrated] = useState(false);
  const [branding, setBranding] = useState<{ name: string | null; logo_url: string | null } | null>(null);

  // Marque blanche : le client voit son transitaire, pas l'éditeur de la solution.
  useEffect(() => {
    rawApi.GET("/v1/portal/auth/me").then(({ data }) => {
      setBranding((data as { branding?: { name: string | null; logo_url: string | null } } | undefined)?.branding ?? null);
    });
  }, []);

  useEffect(() => {
    if (useAuth.persist.hasHydrated()) setHydrated(true);

    return useAuth.persist.onFinishHydration(() => setHydrated(true));
  }, []);

  useEffect(() => {
    if (!hydrated) return;
    if (!token || kind !== "portal") router.replace("/portal/login");
  }, [hydrated, token, kind, router]);

  if (!hydrated || !token || kind !== "portal") return null;

  async function logout() {
    await rawApi.POST("/v1/portal/auth/logout").catch(() => undefined);
    clear();
    router.replace("/portal/login");
  }

  return (
    <div className="min-h-dvh bg-paper">
      <header className="flex items-center gap-5 border-b border-line bg-surface px-6 py-3">
        <span className="flex items-center gap-2 text-sm font-bold">
          {branding?.logo_url ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={branding.logo_url} alt={branding.name ?? ""} className="h-7 max-w-40 object-contain" />
          ) : (
            <span className="tracking-[0.14em]">{branding?.name ?? "Espace client"}</span>
          )}
          <span className="font-normal text-ink-3">· Espace client</span>
        </span>
        <nav className="flex gap-4 text-[13px]">
          {NAV.map((item) => {
            const active = item.exact ? pathname === item.href : pathname.startsWith(item.href);
            return (
              <Link key={item.href} href={item.href} className={active ? "font-semibold text-accent-ink" : "text-ink-2 hover:text-ink"}>
                {item.label}
              </Link>
            );
          })}
        </nav>
        <div className="ml-auto flex items-center gap-3">
          <span className="text-[13px] text-ink-2">{user?.first_name}</span>
          <button onClick={logout} className="rounded-md border border-line-strong px-3 py-1 text-xs text-ink-2 hover:bg-paper">
            Déconnexion
          </button>
        </div>
      </header>
      <main className="mx-auto w-full max-w-5xl p-6">{children}</main>
    </div>
  );
}
