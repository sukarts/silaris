"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import { problemMessage, rawApi } from "@/lib/api";
import { buttonPrimary, inputClass } from "@/components/Field";
import { useAuth } from "@/stores/auth";

export default function PortalLoginPage() {
  const router = useRouter();
  const setSession = useAuth((state) => state.setSession);
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    setLoading(true);
    setError(null);
    const { data, error: problem } = await rawApi.POST("/v1/portal/auth/login", { body: { email, password } });
    setLoading(false);
    if (problem) return setError(problemMessage(problem));
    const response = data as { token: string; account: { id: string; name: string; email: string } };
    setSession(response.token, { id: response.account.id, email: response.account.email, first_name: response.account.name, last_name: "" }, "portal");
    router.replace("/portal");
  }

  return (
    <main className="grid min-h-dvh place-items-center bg-paper p-4">
      <div className="w-full max-w-sm rounded-xl border border-line bg-surface p-8 shadow-sm">
        <div className="mb-1 text-center text-lg font-bold tracking-[0.16em]">
          SILA<span className="text-accent">RIS</span>
        </div>
        <p className="mb-6 text-center text-[13px] text-ink-3">Espace client</p>
        <form onSubmit={submit} className="flex flex-col gap-3">
          <input type="email" required placeholder="Email" value={email} onChange={(e) => setEmail(e.target.value)} className={inputClass} />
          <input type="password" required placeholder="Mot de passe" value={password} onChange={(e) => setPassword(e.target.value)} className={inputClass} />
          {error && <p className="rounded-md bg-crit-soft px-3 py-2 text-xs text-crit">{error}</p>}
          <button disabled={loading} className={buttonPrimary}>{loading ? "Connexion…" : "Se connecter"}</button>
        </form>
      </div>
    </main>
  );
}
