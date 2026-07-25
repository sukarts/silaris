"use client";

import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { Suspense, useState } from "react";
import { problemMessage, rawApi } from "@/lib/api";

function ResetForm() {
  const params = useSearchParams();
  const [email, setEmail] = useState(params.get("email") ?? "");
  const [token, setToken] = useState(params.get("token") ?? "");
  const [password, setPassword] = useState("");
  const [confirm, setConfirm] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState(false);
  const [loading, setLoading] = useState(false);

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    if (password !== confirm) return setError("La confirmation ne correspond pas.");
    setLoading(true);
    setError(null);
    const { error: problem } = await rawApi.POST("/v1/auth/reset-password", {
      body: { email, token, password },
    });
    setLoading(false);
    if (problem) return setError(problemMessage(problem));
    setDone(true);
  }

  const inputCls = "mt-1 w-full rounded-lg border border-line-strong bg-paper px-3 py-2 text-sm font-normal normal-case tracking-normal text-ink focus:outline-2 focus:outline-accent";

  return (
    <main className="grid min-h-dvh place-items-center bg-paper p-4">
      <div className="w-full max-w-sm rounded-xl border border-line bg-surface p-8 shadow-sm">
        <div className="mb-1 text-center text-lg font-bold tracking-[0.16em]">
          SILA<span className="text-accent">RIS</span>
        </div>
        <p className="mb-6 text-center text-[13px] text-ink-3">Nouveau mot de passe</p>

        {done ? (
          <div className="flex flex-col gap-4">
            <p className="rounded-lg bg-ok-soft px-3 py-2 text-[13px] text-ok">Mot de passe réinitialisé.</p>
            <Link href="/login" className="rounded-lg bg-accent px-4 py-2 text-center text-sm font-semibold text-white hover:brightness-105">
              Se connecter
            </Link>
          </div>
        ) : (
          <form onSubmit={submit} className="flex flex-col gap-3">
            <label className="text-xs font-semibold uppercase tracking-wide text-ink-3">
              Email
              <input type="email" required value={email} onChange={(e) => setEmail(e.target.value)} className={inputCls} />
            </label>
            {params.get("token") === null && (
              <label className="text-xs font-semibold uppercase tracking-wide text-ink-3">
                Code de réinitialisation
                <input required value={token} onChange={(e) => setToken(e.target.value)} className={`${inputCls} mono`} />
              </label>
            )}
            <label className="text-xs font-semibold uppercase tracking-wide text-ink-3">
              Nouveau mot de passe
              <input type="password" required minLength={12} value={password} onChange={(e) => setPassword(e.target.value)} className={inputCls} />
            </label>
            <label className="text-xs font-semibold uppercase tracking-wide text-ink-3">
              Confirmation
              <input type="password" required value={confirm} onChange={(e) => setConfirm(e.target.value)} className={inputCls} />
            </label>
            {error && <p className="rounded-md bg-crit-soft px-3 py-2 text-xs text-crit">{error}</p>}
            <button disabled={loading} className="mt-2 rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-white hover:brightness-105 disabled:opacity-60">
              {loading ? "Réinitialisation…" : "Réinitialiser"}
            </button>
            <Link href="/login" className="text-center text-xs text-ink-3 hover:text-ink">← Retour à la connexion</Link>
          </form>
        )}
      </div>
    </main>
  );
}

export default function ResetPasswordPage() {
  return (
    <Suspense>
      <ResetForm />
    </Suspense>
  );
}
