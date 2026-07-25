"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useSearchParams } from "next/navigation";
import { Suspense, useEffect, useRef, useState } from "react";
import QRCode from "qrcode";
import { problemMessage, rawApi } from "@/lib/api";
import { Field, buttonPrimary, buttonSecondary, inputClass } from "@/components/Field";

interface Me {
  email: string;
  first_name: string;
  last_name: string;
  mfa_enabled: boolean;
  must_change_password: boolean;
}

function PasswordSection({ required }: { required: boolean }) {
  const [form, setForm] = useState({ current_password: "", new_password: "", confirm: "" });
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState(false);

  const change = useMutation({
    mutationFn: async () => {
      const { error: problem } = await rawApi.POST("/v1/auth/change-password", {
        body: { current_password: form.current_password, new_password: form.new_password },
      });
      if (problem) throw problem;
    },
    onSuccess: () => {
      setDone(true);
      setError(null);
      setForm({ current_password: "", new_password: "", confirm: "" });
    },
    onError: (problem) => setError(problemMessage(problem)),
  });

  return (
    <section className="rounded-xl border border-line bg-surface p-5 shadow-sm">
      <h2 className="text-sm font-bold">Mot de passe</h2>
      {required && !done && (
        <p className="mt-2 rounded-lg bg-warn-soft px-3 py-2 text-xs text-warn">
          Votre mot de passe provisoire doit être changé avant de continuer.
        </p>
      )}
      {done && <p className="mt-2 rounded-lg bg-ok-soft px-3 py-2 text-xs text-ok">Mot de passe mis à jour.</p>}
      <form
        onSubmit={(e) => {
          e.preventDefault();
          if (form.new_password !== form.confirm) return setError("La confirmation ne correspond pas.");
          change.mutate();
        }}
        className="mt-4 grid gap-4 md:grid-cols-3"
      >
        <Field label="Mot de passe actuel">
          <input type="password" required value={form.current_password} onChange={(e) => setForm({ ...form, current_password: e.target.value })} className={inputClass} />
        </Field>
        <Field label="Nouveau mot de passe">
          <input type="password" required minLength={12} value={form.new_password} onChange={(e) => setForm({ ...form, new_password: e.target.value })} className={inputClass} />
        </Field>
        <Field label="Confirmation">
          <input type="password" required value={form.confirm} onChange={(e) => setForm({ ...form, confirm: e.target.value })} className={inputClass} />
        </Field>
        {error && <p className="rounded-lg bg-crit-soft px-3 py-2 text-xs text-crit md:col-span-3">{error}</p>}
        <p className="text-xs text-ink-3 md:col-span-2">
          Au moins 12 caractères, avec majuscules, minuscules, chiffres et symboles.
        </p>
        <div className="text-right">
          <button disabled={change.isPending} className={buttonPrimary}>Changer le mot de passe</button>
        </div>
      </form>
    </section>
  );
}

function MfaSection({ me, onChanged }: { me: Me; onChanged: () => void }) {
  const [setup, setSetup] = useState<{ secret: string; otpauth_uri: string } | null>(null);
  const [code, setCode] = useState("");
  const [recoveryCodes, setRecoveryCodes] = useState<string[] | null>(null);
  const [disableForm, setDisableForm] = useState<{ password: string; code: string } | null>(null);
  const [error, setError] = useState<string | null>(null);
  const canvasRef = useRef<HTMLCanvasElement>(null);

  useEffect(() => {
    if (setup && canvasRef.current) {
      QRCode.toCanvas(canvasRef.current, setup.otpauth_uri, { width: 192, margin: 1 });
    }
  }, [setup]);

  const enable = useMutation({
    mutationFn: async () => {
      const { data, error: problem } = await rawApi.POST("/v1/auth/mfa/enable");
      if (problem) throw problem;
      return data as { secret: string; otpauth_uri: string };
    },
    onSuccess: (data) => { setSetup(data); setError(null); },
    onError: (problem) => setError(problemMessage(problem)),
  });

  const confirm = useMutation({
    mutationFn: async () => {
      const { data, error: problem } = await rawApi.POST("/v1/auth/mfa/confirm", { body: { code } });
      if (problem) throw problem;
      return data as { recovery_codes: string[] };
    },
    onSuccess: (data) => {
      setRecoveryCodes(data.recovery_codes);
      setSetup(null);
      setCode("");
      setError(null);
      onChanged();
    },
    onError: (problem) => setError(problemMessage(problem)),
  });

  const disable = useMutation({
    mutationFn: async () => {
      const { error: problem } = await rawApi.POST("/v1/auth/mfa/disable", { body: disableForm! });
      if (problem) throw problem;
    },
    onSuccess: () => { setDisableForm(null); setError(null); onChanged(); },
    onError: (problem) => setError(problemMessage(problem)),
  });

  return (
    <section className="rounded-xl border border-line bg-surface p-5 shadow-sm">
      <div className="flex items-center gap-3">
        <h2 className="text-sm font-bold">Double authentification (MFA)</h2>
        <span className={`rounded-full px-2.5 py-0.5 text-[11px] font-semibold ${me.mfa_enabled ? "bg-ok-soft text-ok" : "bg-warn-soft text-warn"}`}>
          {me.mfa_enabled ? "Activée" : "Désactivée"}
        </span>
      </div>

      {error && <p className="mt-3 rounded-lg bg-crit-soft px-3 py-2 text-xs text-crit">{error}</p>}

      {recoveryCodes && (
        <div className="mt-4 rounded-lg border border-line bg-paper p-4">
          <p className="text-xs font-semibold text-ok">MFA activée. Codes de récupération — conservez-les en lieu sûr, affichés une seule fois :</p>
          <div className="mono mt-3 grid grid-cols-2 gap-1.5 text-[13px] md:grid-cols-4">
            {recoveryCodes.map((c) => <span key={c}>{c}</span>)}
          </div>
          <button onClick={() => navigator.clipboard.writeText(recoveryCodes.join("\n"))} className={`mt-3 ${buttonSecondary}`}>
            Copier les codes
          </button>
        </div>
      )}

      {!me.mfa_enabled && !setup && !recoveryCodes && (
        <div className="mt-4">
          <p className="text-[13px] text-ink-2">
            Protégez votre compte avec un code à usage unique (Google Authenticator, Authy, 1Password…).
          </p>
          <button onClick={() => enable.mutate()} disabled={enable.isPending} className={`mt-3 ${buttonPrimary}`}>
            Activer la MFA
          </button>
        </div>
      )}

      {setup && (
        <div className="mt-4 grid gap-5 md:grid-cols-[auto_1fr]">
          <div className="rounded-lg border border-line bg-white p-2">
            <canvas ref={canvasRef} />
          </div>
          <div>
            <p className="text-[13px] text-ink-2">1. Scannez le QR code avec votre application d&apos;authentification.</p>
            <p className="mt-1 text-xs text-ink-3">Ou saisissez la clé manuellement : <span className="mono select-all">{setup.secret}</span></p>
            <p className="mt-3 text-[13px] text-ink-2">2. Entrez le code à 6 chiffres affiché :</p>
            <form onSubmit={(e) => { e.preventDefault(); confirm.mutate(); }} className="mt-2 flex gap-2">
              <input
                autoFocus required minLength={6} maxLength={6} inputMode="numeric" value={code}
                onChange={(e) => setCode(e.target.value.replace(/\D/g, ""))}
                className={`${inputClass} mono w-32 text-center text-lg tracking-widest`}
              />
              <button disabled={confirm.isPending || code.length !== 6} className={buttonPrimary}>Confirmer</button>
              <button type="button" onClick={() => { setSetup(null); setError(null); }} className={buttonSecondary}>Annuler</button>
            </form>
          </div>
        </div>
      )}

      {me.mfa_enabled && !disableForm && (
        <button onClick={() => setDisableForm({ password: "", code: "" })} className={`mt-4 ${buttonSecondary}`}>
          Désactiver la MFA
        </button>
      )}

      {disableForm && (
        <form onSubmit={(e) => { e.preventDefault(); disable.mutate(); }} className="mt-4 grid gap-4 md:grid-cols-3">
          <Field label="Mot de passe">
            <input type="password" required value={disableForm.password} onChange={(e) => setDisableForm({ ...disableForm, password: e.target.value })} className={inputClass} />
          </Field>
          <Field label="Code TOTP">
            <input required minLength={6} maxLength={6} inputMode="numeric" value={disableForm.code} onChange={(e) => setDisableForm({ ...disableForm, code: e.target.value.replace(/\D/g, "") })} className={`${inputClass} mono`} />
          </Field>
          <div className="flex items-end gap-2">
            <button disabled={disable.isPending} className={buttonPrimary}>Confirmer la désactivation</button>
            <button type="button" onClick={() => setDisableForm(null)} className={buttonSecondary}>Annuler</button>
          </div>
        </form>
      )}
    </section>
  );
}

function ProfileContent() {
  const queryClient = useQueryClient();
  const required = useSearchParams().get("required") === "1";

  const { data: me } = useQuery({
    queryKey: ["me"],
    queryFn: async () => {
      const { data } = await rawApi.GET("/v1/auth/me");
      return data as Me;
    },
  });

  if (!me) return <p className="py-8 text-center text-ink-3">Chargement…</p>;

  return (
    <div className="flex max-w-3xl flex-col gap-4">
      <div>
        <h1 className="text-xl font-bold">Mon profil</h1>
        <p className="text-[13px] text-ink-3">{me.first_name} {me.last_name} — {me.email}</p>
      </div>
      <PasswordSection required={required || me.must_change_password} />
      <MfaSection me={me} onChanged={() => queryClient.invalidateQueries({ queryKey: ["me"] })} />
    </div>
  );
}

export default function ProfilePage() {
  return (
    <Suspense>
      <ProfileContent />
    </Suspense>
  );
}
