"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { rawApi } from "@/lib/api";
import { useAuth, useCan } from "@/stores/auth";

// `soon: true` = écran dédié prévu en passe 2 UI (backlog P2) : entrée visible
// mais désactivée — jamais de lien mort. Les données correspondantes restent
// accessibles via le détail Dossier (segments, conteneurs, documents).
const NAV = [
  { section: null, items: [{ href: "/dashboard", label: "Tableau de bord", perm: "dashboard.read" }] },
  {
    section: "Opérations",
    items: [
      { href: "/shipments", label: "Dossiers", perm: "shipments.read" },
      { href: "/bookings", label: "Bookings", perm: "bookings.read", soon: true },
      { href: "/containers", label: "Conteneurs", perm: "containers.read", soon: true },
      { href: "/air", label: "Aérien", perm: "awb.read", soon: true },
      { href: "/road", label: "Routier", perm: "road.read", soon: true },
    ],
  },
  {
    section: "Commercial",
    items: [
      { href: "/crm", label: "CRM", perm: "crm.read" },
      { href: "/quotes", label: "Cotations", perm: "quotes.read" },
      { href: "/billing", label: "Facturation", perm: "invoices.read" },
    ],
  },
  {
    section: "Ressources",
    items: [
      { href: "/documents", label: "Documents", perm: "documents.read", soon: true },
      { href: "/admin", label: "Administration", perm: "users.read", soon: true },
    ],
  },
];

function NavLink({ href, label, perm, soon }: { href: string; label: string; perm: string; soon?: boolean }) {
  const pathname = usePathname();
  const allowed = useCan(perm);
  if (!allowed) return null;
  if (soon) {
    return (
      <span
        title="Disponible prochainement"
        className="block cursor-default rounded-md px-3 py-1.5 text-[13px] text-nav-ink/40"
      >
        {label}
        <span className="ml-1.5 rounded border border-white/10 px-1 py-px text-[9px] uppercase tracking-wide text-nav-ink/50">
          bientôt
        </span>
      </span>
    );
  }
  const active = pathname.startsWith(href);
  return (
    <Link
      href={href}
      className={`block rounded-md px-3 py-1.5 text-[13px] transition-colors ${
        active
          ? "bg-white/10 font-semibold text-nav-active shadow-[inset_2px_0_0_var(--accent)]"
          : "text-nav-ink hover:bg-white/5"
      }`}
    >
      {label}
    </Link>
  );
}

export function AppShell({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const { token, user, clear, setPermissions } = useAuth();
  // Attend la réhydratation du store persisté avant toute décision d'auth (client uniquement).
  const [hydrated, setHydrated] = useState(false);

  useEffect(() => {
    if (useAuth.persist.hasHydrated()) setHydrated(true);

    return useAuth.persist.onFinishHydration(() => setHydrated(true));
  }, []);

  useEffect(() => {
    if (!hydrated) return;
    if (!token) {
      router.replace("/login");
      return;
    }
    rawApi.GET("/v1/auth/me").then(({ data }) => {
      const me = data as { permissions?: string[] } | undefined;
      if (me?.permissions) setPermissions(me.permissions);
    });
  }, [hydrated, token, router, setPermissions]);

  if (!hydrated || !token) return null;

  async function logout() {
    await rawApi.POST("/v1/auth/logout").catch(() => undefined);
    clear();
    router.replace("/login");
  }

  return (
    <div className="grid min-h-dvh grid-cols-[216px_1fr]">
      <nav className="flex flex-col gap-0.5 bg-nav-bg p-3">
        <div className="px-3 pb-4 pt-1 text-sm font-bold tracking-[0.16em] text-nav-active">
          SILA<span className="text-accent">RIS</span>
        </div>
        {NAV.map((group) => (
          <div key={group.section ?? "root"}>
            {group.section && (
              <div className="px-3 pb-1 pt-4 text-[10px] uppercase tracking-[0.12em] text-nav-ink/60">
                {group.section}
              </div>
            )}
            {group.items.map((item) => (
              <NavLink key={item.href} {...item} />
            ))}
          </div>
        ))}
      </nav>
      <div className="flex min-w-0 flex-col">
        <header className="flex items-center gap-4 border-b border-line bg-surface px-6 py-2.5">
          <input
            placeholder="Rechercher dossier, BL, conteneur, client…"
            className="w-full max-w-md rounded-lg border border-line bg-paper px-3 py-1.5 text-[13px] text-ink placeholder:text-ink-3 focus:outline-2 focus:outline-accent"
          />
          <div className="ml-auto flex items-center gap-3">
            <span className="text-[13px] text-ink-2">
              {user?.first_name} {user?.last_name}
            </span>
            <button
              onClick={logout}
              className="rounded-md border border-line-strong px-3 py-1 text-xs text-ink-2 hover:bg-paper"
            >
              Déconnexion
            </button>
          </div>
        </header>
        <main className="flex-1 p-6">{children}</main>
      </div>
    </div>
  );
}
