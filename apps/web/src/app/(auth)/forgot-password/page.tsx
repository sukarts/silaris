"use client";

import Link from "next/link";
import { useState } from "react";
import { rawApi } from "@/lib/api";

export default function ForgotPasswordPage() {
  const [email, setEmail] = useState("");
  const [sent, setSent] = useState(false);
  const [loading, setLoading] = useState(false);

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    setLoading(true);
    await rawApi.POST("/v1/auth/forgot-password", { body: { email } }).catch(() => undefined);
    setLoading(false);
    setSent(true);
  }

  return (
    <main className="grid min-h-dvh place-items-center bg-paper p-4">
      <div className="w-full max-w-sm rounded-xl border border-line bg-surface p-8 shadow-sm">
        <div className="mb-1 text-center text-lg font-bold tracking-[0.16em]">
          SILA<span className="text-accent">RIS</span>
        </div>
        <p className="mb-6 text-center text-[13px] text-ink-3">Mot de passe oublié</p>

        {sent ? (
          <div className="flex flex-col gap-4">
            <p className="rounded-lg bg-ok-soft px-3 py-2 text-[13px] text-ok">
              Si un compte existe pour cette adresse, un email de réinitialisation vient d&apos;être envoyé. Le lien expire dans 60 minutes.
            </p>
            <Link href="/login" className="text-center text-xs text-ink-3 hover:text-ink">← Retour à la connexion</Link>
          </div>
        ) : (
          <form onSubmit={submit} className="flex flex-col gap-3">
            <label className="text-xs font-semibold uppercase tracking-wide text-ink-3">
              Email
              <input
                type="email" required autoFocus value={email}
                onChange={(e) => setEmail(e.target.value)}
                className="mt-1 w-full rounded-lg border border-line-strong bg-paper px-3 py-2 text-sm font-normal normal-case tracking-normal text-ink focus:outline-2 focus:outline-accent"
              />
            </label>
            <button disabled={loading} className="mt-2 rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-white hover:brightness-105 disabled:opacity-60">
              {loading ? "Envoi…" : "Envoyer le lien"}
            </button>
            <Link href="/login" className="text-center text-xs text-ink-3 hover:text-ink">← Retour à la connexion</Link>
          </form>
        )}
      </div>
    </main>
  );
}
