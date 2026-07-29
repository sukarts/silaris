"use client";

import { create } from "zustand";
import { persist } from "zustand/middleware";

interface AuthUser {
  id: string;
  email: string;
  first_name: string;
  last_name: string;
}

type AccountKind = "internal" | "portal";

interface AuthState {
  token: string | null;
  user: AuthUser | null;
  permissions: string[];
  kind: AccountKind;
  setSession: (token: string, user: AuthUser, kind?: AccountKind) => void;
  setPermissions: (permissions: string[]) => void;
  clear: () => void;
}

export const useAuth = create<AuthState>()(
  persist(
    (set) => ({
      token: null,
      user: null,
      permissions: [],
      kind: "internal",
      setSession: (token, user, kind = "internal") => set({ token, user, kind }),
      setPermissions: (permissions) => set({ permissions }),
      clear: () => set({ token: null, user: null, permissions: [], kind: "internal" }),
    }),
    { name: "silaris-auth" },
  ),
);

/** Garde RBAC côté UI — la vraie autorisation reste côté API. */
export function useCan(permission: string): boolean {
  return useAuth((state) => state.permissions.includes(permission));
}

/**
 * Un écran servant plusieurs rôles s'ouvre au premier droit reconnu — la file
 * de validation, par exemple, réunit chefs de service et direction, qui n'y
 * viennent pas pour la même chose.
 */
export function useCanAny(permissions: string[]): boolean {
  return useAuth((state) => permissions.some((permission) => state.permissions.includes(permission)));
}
