"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import { problemMessage, rawApi } from "@/lib/api";
import { useAuth } from "@/stores/auth";

type Step = { kind: "credentials" } | { kind: "mfa"; challenge: string };

export default function LoginPage() {
  const router = useRouter();
  const setSession = useAuth((state) => state.setSession);
  const [step, setStep] = useState<Step>({ kind: "credentials" });
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [code, setCode] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  async function submitCredentials(event: React.FormEvent) {
    event.preventDefault();
    setLoading(true);
    setError(null);
    const { data, error: problem } = await rawApi.POST("/v1/auth/login", {
      body: { email, password },
    });
    setLoading(false);
    if (problem) return setError(problemMessage(problem));
    const response = data as
      | { mfa_required: true; challenge: string }
      | { token: string; user: { id: string; email: string; first_name: string; last_name: string } };
    if ("mfa_required" in response) {
      setStep({ kind: "mfa", challenge: response.challenge });
    } else {
      setSession(response.token, response.user);
      router.replace("/dashboard");
    }
  }

  async function submitMfa(event: React.FormEvent) {
    event.preventDefault();
    if (step.kind !== "mfa") return;
    setLoading(true);
    setError(null);
    const { data, error: problem } = await rawApi.POST("/v1/auth/mfa/verify", {
      body: { challenge: step.challenge, code },
    });
    setLoading(false);
    if (problem) return setError(problemMessage(problem));
    const response = data as { token: string; user: { id: string; email: string; first_name: string; last_name: string } };
    setSession(response.token, response.user);
    router.replace("/dashboard");
  }

  return (
    <main className="grid min-h-dvh place-items-center bg-paper p-4">
      <div className="w-full max-w-sm rounded-xl border border-line bg-surface p-8 shadow-sm">
        <div className="mb-1 text-center text-lg font-bold tracking-[0.16em]">
          SILA<span className="text-accent">RIS</span>
        </div>
        <p className="mb-6 text-center text-[13px] text-ink-3">
          {step.kind === "credentials" ? "Connexion à votre espace" : "Vérification en deux étapes"}
        </p>

        {step.kind === "credentials" ? (
          <form onSubmit={submitCredentials} className="flex flex-col gap-3">
            <label className="text-xs font-semibold uppercase tracking-wide text-ink-3">
              Email
              <input
                type="email"
                required
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                className="mt-1 w-full rounded-lg border border-line-strong bg-paper px-3 py-2 text-sm font-normal normal-case tracking-normal text-ink focus:outline-2 focus:outline-accent"
              />
            </label>
            <label className="text-xs font-semibold uppercase tracking-wide text-ink-3">
              Mot de passe
              <input
                type="password"
                required
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                className="mt-1 w-full rounded-lg border border-line-strong bg-paper px-3 py-2 text-sm font-normal text-ink focus:outline-2 focus:outline-accent"
              />
            </label>
            {error && <p className="rounded-md bg-crit-soft px-3 py-2 text-xs text-crit">{error}</p>}
            <button
              disabled={loading}
              className="mt-2 rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-white hover:brightness-105 disabled:opacity-60"
            >
              {loading ? "Connexion…" : "Se connecter"}
            </button>
          </form>
        ) : (
          <form onSubmit={submitMfa} className="flex flex-col gap-3">
            <label className="text-xs font-semibold uppercase tracking-wide text-ink-3">
              Code de vérification
              <input
                autoFocus
                required
                value={code}
                onChange={(e) => setCode(e.target.value)}
                placeholder="Code TOTP ou code de récupération"
                className="mono mt-1 w-full rounded-lg border border-line-strong bg-paper px-3 py-2 text-center text-lg tracking-widest text-ink focus:outline-2 focus:outline-accent"
              />
            </label>
            {error && <p className="rounded-md bg-crit-soft px-3 py-2 text-xs text-crit">{error}</p>}
            <button
              disabled={loading}
              className="mt-2 rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-white hover:brightness-105 disabled:opacity-60"
            >
              Vérifier
            </button>
            <button
              type="button"
              onClick={() => setStep({ kind: "credentials" })}
              className="text-xs text-ink-3 hover:text-ink"
            >
              ← Retour
            </button>
          </form>
        )}
      </div>
    </main>
  );
}
