"use client";

import { createSilarisClient, type ProblemDetails } from "@silaris/api-client";
import { useAuth } from "@/stores/auth";

export const api = createSilarisClient({
  baseUrl: process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8088/api",
  getToken: () => useAuth.getState().token,
  onUnauthorized: () => {
    const wasPortal = useAuth.getState().kind === "portal";
    useAuth.getState().clear();
    if (typeof window !== "undefined" && !window.location.pathname.includes("/login")) {
      window.location.href = wasPortal ? "/portal/login" : "/login";
    }
  },
});

/**
 * Accès non typé — utilisé tant que la spec Scramble ne décrit pas encore
 * tous les paramètres de requête. Les chemins restent vérifiés à l'exécution
 * par l'API ; la version typée (api) est préférée dès que possible.
 */
interface RawCall {
  (path: string, init?: { body?: unknown; params?: { query?: Record<string, unknown> } }): Promise<{
    data?: unknown;
    error?: unknown;
  }>;
}

export const rawApi = {
  GET: api.GET as unknown as RawCall,
  POST: api.POST as unknown as RawCall,
  PATCH: api.PATCH as unknown as RawCall,
  DELETE: api.DELETE as unknown as RawCall,
};

export function problemMessage(problem: unknown): string {
  const p = problem as ProblemDetails | undefined;
  if (!p) return "Erreur inattendue.";
  if (p.errors) return Object.values(p.errors).flat().join(" ");
  return p.detail ?? p.title ?? "Erreur inattendue.";
}

/** Télécharge un fichier authentifié (PDF…) : fetch avec Bearer puis déclenche le download. */
export async function downloadFile(path: string, fallbackName: string): Promise<void> {
  const base = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8088/api";
  const response = await fetch(`${base}${path}`, {
    headers: { Authorization: `Bearer ${useAuth.getState().token ?? ""}` },
  });
  if (!response.ok) throw new Error(`Téléchargement impossible (${response.status})`);
  const disposition = response.headers.get("Content-Disposition") ?? "";
  const match = /filename="?([^";]+)"?/.exec(disposition);
  const blob = await response.blob();
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = match?.[1] ?? fallbackName;
  link.click();
  URL.revokeObjectURL(url);
}
