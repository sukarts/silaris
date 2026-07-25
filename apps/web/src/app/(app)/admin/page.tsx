"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useState } from "react";
import { problemMessage, rawApi } from "@/lib/api";
import { Field, buttonPrimary, buttonSecondary, inputClass } from "@/components/Field";
import { useCan } from "@/stores/auth";

interface RoleRef {
  id: string;
  key: string;
  name: string;
}

interface BranchRef {
  id: string;
  code: string;
  name?: string;
}

interface AdminUser {
  id: string;
  email: string;
  first_name: string;
  last_name: string;
  phone: string | null;
  locale: string;
  is_active: boolean;
  roles?: RoleRef[];
  branches?: BranchRef[];
}

interface PermissionItem {
  key: string;
  module: string;
  description?: string | null;
}

interface Role {
  id: string;
  key: string;
  name: string;
  description: string | null;
  is_system: boolean;
  permissions?: PermissionItem[];
}

interface Company {
  id: string;
  legal_name: string;
  branches?: BranchRef[];
}

interface AuditLog {
  id: string;
  user_id: string | null;
  portal_account_id: string | null;
  action: string;
  entity_type: string;
  entity_id: string | null;
  occurred_at: string;
}

type Tab = "users" | "roles" | "audit";

function toggleValue(list: string[], value: string): string[] {
  return list.includes(value) ? list.filter((item) => item !== value) : [...list, value];
}

export default function AdminPage() {
  const [tab, setTab] = useState<Tab>("users");
  const canReadUsers = useCan("users.read");
  const canReadRoles = useCan("roles.read");
  const canReadAudit = useCan("audit.read");

  const TABS: { id: Tab; label: string; allowed: boolean }[] = [
    { id: "users", label: "Utilisateurs", allowed: canReadUsers },
    { id: "roles", label: "Rôles", allowed: canReadRoles },
    { id: "audit", label: "Journal d'audit", allowed: canReadAudit },
  ];

  return (
    <div className="flex flex-col gap-4">
      <div>
        <h1 className="text-xl font-bold">Administration</h1>
        <p className="text-[13px] text-ink-3">Utilisateurs, rôles et traçabilité</p>
      </div>

      <div className="flex flex-wrap gap-2">
        {TABS.filter((item) => item.allowed).map((item) => (
          <button
            key={item.id}
            onClick={() => setTab(item.id)}
            className={`rounded-full border px-3.5 py-1 text-xs font-semibold ${
              tab === item.id ? "border-ink bg-ink text-paper" : "border-line-strong text-ink-2 hover:bg-surface"
            }`}
          >
            {item.label}
          </button>
        ))}
      </div>

      {tab === "users" && canReadUsers && <UsersTab />}
      {tab === "roles" && canReadRoles && <RolesTab />}
      {tab === "audit" && canReadAudit && <AuditTab />}
    </div>
  );
}

function UsersTab() {
  const queryClient = useQueryClient();
  const canCreate = useCan("users.create");
  const canUpdate = useCan("users.update");
  const canResetMfa = useCan("users.reset_mfa");
  const canReadRoles = useCan("roles.read");
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState({ email: "", first_name: "", last_name: "", phone: "", locale: "fr" });
  const [roleIds, setRoleIds] = useState<string[]>([]);
  const [branchIds, setBranchIds] = useState<string[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [invitation, setInvitation] = useState<{ email: string; password: string } | null>(null);

  const { data, isLoading } = useQuery({
    queryKey: ["admin-users"],
    queryFn: async () => {
      const { data: response } = await rawApi.GET("/v1/admin/users");
      return response as { data: AdminUser[] };
    },
  });

  const { data: roles } = useQuery({
    queryKey: ["admin-roles"],
    queryFn: async () => {
      const { data: response } = await rawApi.GET("/v1/admin/roles");
      return response as Role[];
    },
    enabled: showForm && canReadRoles,
  });

  const { data: companies } = useQuery({
    queryKey: ["admin-companies"],
    queryFn: async () => {
      const { data: response } = await rawApi.GET("/v1/admin/companies");
      return response as Company[];
    },
    enabled: showForm,
  });

  const branches = (companies ?? []).flatMap((company) => company.branches ?? []);

  const invite = useMutation({
    mutationFn: async () => {
      const { data: response, error: problem } = await rawApi.POST("/v1/admin/users", {
        body: { ...form, phone: form.phone || null, role_ids: roleIds, branch_ids: branchIds },
      });
      if (problem) throw problem;
      return response as { user?: AdminUser; temporary_password?: string };
    },
    onSuccess: (response) => {
      setInvitation({ email: response?.user?.email ?? form.email, password: response?.temporary_password ?? "" });
      setShowForm(false);
      setForm({ email: "", first_name: "", last_name: "", phone: "", locale: "fr" });
      setRoleIds([]);
      setBranchIds([]);
      setError(null);
      queryClient.invalidateQueries({ queryKey: ["admin-users"] });
    },
    onError: (problem) => setError(problemMessage(problem)),
  });

  const toggleActive = useMutation({
    mutationFn: async (user: AdminUser) => {
      const { error: problem } = await rawApi.PATCH(`/v1/admin/users/${user.id}`, {
        body: { is_active: !user.is_active },
      });
      if (problem) throw problem;
    },
    onSuccess: () => {
      setError(null);
      queryClient.invalidateQueries({ queryKey: ["admin-users"] });
    },
    onError: (problem) => setError(problemMessage(problem)),
  });

  const resetMfa = useMutation({
    mutationFn: async (userId: string) => {
      const { error: problem } = await rawApi.POST(`/v1/admin/users/${userId}/reset-mfa`);
      if (problem) throw problem;
    },
    onSuccess: () => setError(null),
    onError: (problem) => setError(problemMessage(problem)),
  });

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-start">
        <p className="text-[13px] text-ink-3">Comptes internes du tenant</p>
        {canCreate && (
          <button onClick={() => setShowForm((value) => !value)} className={`ml-auto ${buttonPrimary}`}>
            + Inviter un utilisateur
          </button>
        )}
      </div>

      {invitation && (
        <p className="rounded-lg bg-ok-soft px-4 py-2.5 text-[13px] text-ok">
          Compte créé pour {invitation.email}. Mot de passe provisoire : <span className="mono font-semibold">{invitation.password}</span>{" "}
          (à transmettre de façon sécurisée — changement obligatoire au premier login).
          <button onClick={() => setInvitation(null)} className="ml-2 text-xs font-semibold underline">Masquer</button>
        </p>
      )}

      {showForm && (
        <form
          onSubmit={(event) => { event.preventDefault(); invite.mutate(); }}
          className="grid gap-4 rounded-xl border border-line bg-surface p-5 shadow-sm md:grid-cols-4"
        >
          <Field label="Email">
            <input required type="email" maxLength={255} value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} className={inputClass} />
          </Field>
          <Field label="Prénom">
            <input required maxLength={100} value={form.first_name} onChange={(e) => setForm({ ...form, first_name: e.target.value })} className={inputClass} />
          </Field>
          <Field label="Nom">
            <input required maxLength={100} value={form.last_name} onChange={(e) => setForm({ ...form, last_name: e.target.value })} className={inputClass} />
          </Field>
          <Field label="Téléphone">
            <input maxLength={32} value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} className={inputClass} />
          </Field>
          <Field label="Langue">
            <select value={form.locale} onChange={(e) => setForm({ ...form, locale: e.target.value })} className={inputClass}>
              <option value="fr">Français</option>
              <option value="en">Anglais</option>
            </select>
          </Field>
          <Field label="Rôles" className="md:col-span-3">
            <div className="flex flex-wrap gap-x-4 gap-y-1.5 rounded-lg border border-line-strong bg-paper px-3 py-2">
              {(roles ?? []).map((role) => (
                <label key={role.id} className="flex items-center gap-1.5 text-sm font-normal normal-case tracking-normal text-ink">
                  <input type="checkbox" checked={roleIds.includes(role.id)} onChange={() => setRoleIds(toggleValue(roleIds, role.id))} />
                  {role.name}
                </label>
              ))}
              {(roles ?? []).length === 0 && <span className="text-xs text-ink-3">Aucun rôle disponible.</span>}
            </div>
          </Field>
          <Field label="Agences" className="md:col-span-4">
            <div className="flex flex-wrap gap-x-4 gap-y-1.5 rounded-lg border border-line-strong bg-paper px-3 py-2">
              {branches.map((branch) => (
                <label key={branch.id} className="flex items-center gap-1.5 text-sm font-normal normal-case tracking-normal text-ink">
                  <input type="checkbox" checked={branchIds.includes(branch.id)} onChange={() => setBranchIds(toggleValue(branchIds, branch.id))} />
                  {branch.name ?? branch.code}
                </label>
              ))}
              {branches.length === 0 && <span className="text-xs text-ink-3">Aucune agence disponible.</span>}
            </div>
          </Field>
          {error && <p className="rounded-lg bg-crit-soft px-3 py-2 text-xs text-crit md:col-span-4">{error}</p>}
          <div className="flex gap-2 md:col-span-4">
            <button type="submit" disabled={invite.isPending || roleIds.length === 0 || branchIds.length === 0} className={buttonPrimary}>
              Inviter
            </button>
            <button type="button" onClick={() => setShowForm(false)} className={buttonSecondary}>Annuler</button>
          </div>
        </form>
      )}

      {error && !showForm && <p className="rounded-lg bg-crit-soft px-4 py-2.5 text-[13px] text-crit">{error}</p>}

      <div className="overflow-x-auto rounded-xl border border-line bg-surface shadow-sm">
        <table className="w-full text-[13px]">
          <thead>
            <tr className="border-b border-line text-left text-[10px] uppercase tracking-wider text-ink-3">
              <th className="px-3 py-2.5">Nom</th>
              <th className="px-3 py-2.5">Email</th>
              <th className="px-3 py-2.5">Rôles</th>
              <th className="px-3 py-2.5">Agences</th>
              <th className="px-3 py-2.5">Actif</th>
              <th className="px-3 py-2.5" />
            </tr>
          </thead>
          <tbody>
            {isLoading && <tr><td colSpan={6} className="px-3 py-8 text-center text-ink-3">Chargement…</td></tr>}
            {(data?.data ?? []).map((user) => (
              <tr key={user.id} className="border-b border-line last:border-0 hover:bg-sea/5">
                <td className="px-3 py-2.5 font-semibold">{user.last_name} {user.first_name}</td>
                <td className="mono px-3 py-2.5 text-ink-2">{user.email}</td>
                <td className="px-3 py-2.5">
                  <div className="flex flex-wrap gap-1">
                    {(user.roles ?? []).map((role) => (
                      <span key={role.id} className="rounded-full bg-sea-soft px-2 py-0.5 text-[11px] font-semibold text-sea">{role.name}</span>
                    ))}
                  </div>
                </td>
                <td className="mono px-3 py-2.5 text-ink-2">{(user.branches ?? []).map((branch) => branch.code).join(", ") || "—"}</td>
                <td className="px-3 py-2.5">
                  <span className={`rounded-full px-2.5 py-0.5 text-[11px] font-semibold ${user.is_active ? "bg-ok-soft text-ok" : "bg-crit-soft text-crit"}`}>
                    {user.is_active ? "Actif" : "Désactivé"}
                  </span>
                </td>
                <td className="px-3 py-2.5">
                  <div className="flex gap-3">
                    {canUpdate && (
                      <button
                        onClick={() => toggleActive.mutate(user)}
                        disabled={toggleActive.isPending}
                        className="text-xs font-semibold text-sea hover:underline"
                      >
                        {user.is_active ? "Désactiver" : "Activer"}
                      </button>
                    )}
                    {canResetMfa && (
                      <button
                        onClick={() => { if (window.confirm(`Réinitialiser la MFA de ${user.email} ?`)) resetMfa.mutate(user.id); }}
                        disabled={resetMfa.isPending}
                        className="text-xs font-semibold text-warn hover:underline"
                      >
                        Réinitialiser MFA
                      </button>
                    )}
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function RolesTab() {
  const queryClient = useQueryClient();
  const canCreate = useCan("roles.create");
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState({ key: "", name: "", description: "" });
  const [permissionKeys, setPermissionKeys] = useState<string[]>([]);
  const [error, setError] = useState<string | null>(null);

  const { data: roles, isLoading } = useQuery({
    queryKey: ["admin-roles"],
    queryFn: async () => {
      const { data: response } = await rawApi.GET("/v1/admin/roles");
      return response as Role[];
    },
  });

  const { data: permissions } = useQuery({
    queryKey: ["admin-permissions"],
    queryFn: async () => {
      const { data: response } = await rawApi.GET("/v1/admin/permissions");
      return response as Record<string, PermissionItem[]>;
    },
    enabled: showForm,
  });

  const create = useMutation({
    mutationFn: async () => {
      const { error: problem } = await rawApi.POST("/v1/admin/roles", {
        body: { key: form.key, name: form.name, description: form.description || null, permission_keys: permissionKeys },
      });
      if (problem) throw problem;
    },
    onSuccess: () => {
      setShowForm(false);
      setForm({ key: "", name: "", description: "" });
      setPermissionKeys([]);
      setError(null);
      queryClient.invalidateQueries({ queryKey: ["admin-roles"] });
    },
    onError: (problem) => setError(problemMessage(problem)),
  });

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-start">
        <p className="text-[13px] text-ink-3">Rôles système et rôles personnalisés du tenant</p>
        {canCreate && (
          <button onClick={() => setShowForm((value) => !value)} className={`ml-auto ${buttonPrimary}`}>
            + Nouveau rôle
          </button>
        )}
      </div>

      {showForm && (
        <form
          onSubmit={(event) => { event.preventDefault(); create.mutate(); }}
          className="grid gap-4 rounded-xl border border-line bg-surface p-5 shadow-sm md:grid-cols-4"
        >
          <Field label="Clé">
            <input
              required
              maxLength={64}
              pattern="[a-z0-9_]+"
              title="Minuscules, chiffres et underscore uniquement"
              value={form.key}
              onChange={(e) => setForm({ ...form, key: e.target.value.toLowerCase().replace(/[^a-z0-9_]/g, "_") })}
              className={`${inputClass} mono`}
            />
          </Field>
          <Field label="Nom" className="md:col-span-2">
            <input required maxLength={255} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className={inputClass} />
          </Field>
          <Field label="Description">
            <input maxLength={1000} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} className={inputClass} />
          </Field>
          <div className="flex flex-col gap-3 md:col-span-4">
            {Object.entries(permissions ?? {}).map(([module, items]) => (
              <div key={module}>
                <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-ink-3">{module}</p>
                <div className="flex flex-wrap gap-x-4 gap-y-1.5 rounded-lg border border-line bg-paper px-3 py-2">
                  {(items ?? []).map((permission) => (
                    <label key={permission.key} className="flex items-center gap-1.5 text-[13px] text-ink">
                      <input
                        type="checkbox"
                        checked={permissionKeys.includes(permission.key)}
                        onChange={() => setPermissionKeys(toggleValue(permissionKeys, permission.key))}
                      />
                      <span className="mono">{permission.key}</span>
                    </label>
                  ))}
                </div>
              </div>
            ))}
            {Object.keys(permissions ?? {}).length === 0 && (
              <p className="text-xs text-ink-3">Chargement du catalogue de permissions…</p>
            )}
          </div>
          {error && <p className="rounded-lg bg-crit-soft px-3 py-2 text-xs text-crit md:col-span-4">{error}</p>}
          <div className="flex gap-2 md:col-span-4">
            <button type="submit" disabled={create.isPending || permissionKeys.length === 0} className={buttonPrimary}>Créer le rôle</button>
            <button type="button" onClick={() => setShowForm(false)} className={buttonSecondary}>Annuler</button>
          </div>
        </form>
      )}

      <div className="overflow-x-auto rounded-xl border border-line bg-surface shadow-sm">
        <table className="w-full text-[13px]">
          <thead>
            <tr className="border-b border-line text-left text-[10px] uppercase tracking-wider text-ink-3">
              <th className="px-3 py-2.5">Clé</th>
              <th className="px-3 py-2.5">Nom</th>
              <th className="px-3 py-2.5">Origine</th>
              <th className="px-3 py-2.5 text-right">Permissions</th>
            </tr>
          </thead>
          <tbody>
            {isLoading && <tr><td colSpan={4} className="px-3 py-8 text-center text-ink-3">Chargement…</td></tr>}
            {(roles ?? []).map((role) => (
              <tr key={role.id} className="border-b border-line last:border-0 hover:bg-sea/5">
                <td className="mono px-3 py-2.5 font-semibold text-sea">{role.key}</td>
                <td className="px-3 py-2.5">
                  {role.name}
                  {role.description && <span className="block text-[11px] text-ink-3">{role.description}</span>}
                </td>
                <td className="px-3 py-2.5">
                  <span className={`rounded-full px-2.5 py-0.5 text-[11px] font-semibold ${role.is_system ? "bg-paper text-ink-3" : "bg-sea-soft text-sea"}`}>
                    {role.is_system ? "Système" : "Personnalisé"}
                  </span>
                </td>
                <td className="mono px-3 py-2.5 text-right">{role.permissions?.length ?? 0}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function AuditTab() {
  const [cursor, setCursor] = useState<string | null>(null);
  const [previousCursors, setPreviousCursors] = useState<(string | null)[]>([]);

  const { data, isLoading } = useQuery({
    queryKey: ["audit-logs", cursor],
    queryFn: async () => {
      const { data: response } = await rawApi.GET("/v1/admin/audit-logs", {
        params: { query: { ...(cursor ? { cursor } : {}) } },
      });
      return response as { data: AuditLog[]; next_cursor?: string | null; prev_cursor?: string | null };
    },
  });

  return (
    <div className="flex flex-col gap-4">
      <p className="text-[13px] text-ink-3">Journal immuable des actions — lecture seule</p>
      <div className="overflow-x-auto rounded-xl border border-line bg-surface shadow-sm">
        <table className="w-full text-[13px]">
          <thead>
            <tr className="border-b border-line text-left text-[10px] uppercase tracking-wider text-ink-3">
              <th className="px-3 py-2.5">Date</th>
              <th className="px-3 py-2.5">Utilisateur</th>
              <th className="px-3 py-2.5">Action</th>
              <th className="px-3 py-2.5">Entité</th>
            </tr>
          </thead>
          <tbody>
            {isLoading && <tr><td colSpan={4} className="px-3 py-8 text-center text-ink-3">Chargement…</td></tr>}
            {!isLoading && (data?.data ?? []).length === 0 && (
              <tr><td colSpan={4} className="px-3 py-8 text-center text-ink-3">Aucune entrée.</td></tr>
            )}
            {(data?.data ?? []).map((log) => (
              <tr key={log.id} className="border-b border-line last:border-0 hover:bg-sea/5">
                <td className="mono px-3 py-2.5 text-ink-2">
                  {log.occurred_at ? new Date(log.occurred_at).toLocaleString("fr-FR") : "—"}
                </td>
                <td className="mono px-3 py-2.5 text-ink-2">
                  {log.user_id ? log.user_id.slice(0, 8) : log.portal_account_id ? `portail ${log.portal_account_id.slice(0, 8)}` : "système"}
                </td>
                <td className="px-3 py-2.5">
                  <span className="mono rounded bg-paper px-1.5 py-0.5 text-[11px] font-semibold text-ink-2">{log.action}</span>
                </td>
                <td className="mono px-3 py-2.5 text-ink-2">
                  {log.entity_type}
                  {log.entity_id ? ` · ${log.entity_id.slice(0, 8)}` : ""}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <div className="flex items-center gap-2">
        <button
          onClick={() => {
            const previous = previousCursors[previousCursors.length - 1] ?? null;
            setPreviousCursors(previousCursors.slice(0, -1));
            setCursor(previous);
          }}
          disabled={previousCursors.length === 0}
          className={buttonSecondary}
        >
          ← Précédent
        </button>
        <button
          onClick={() => {
            if (data?.next_cursor) {
              setPreviousCursors([...previousCursors, cursor]);
              setCursor(data.next_cursor);
            }
          }}
          disabled={!data?.next_cursor}
          className={buttonSecondary}
        >
          Suivant →
        </button>
      </div>
    </div>
  );
}
