import createOpenApiClient, { type Middleware } from "openapi-fetch";
import type { paths } from "./generated/types";

export type { paths, components } from "./generated/types";

export interface SilarisClientOptions {
  baseUrl: string;
  getToken: () => string | null;
  onUnauthorized?: () => void;
}

/** Erreur RFC 9457 renvoyée par l'API. */
export interface ProblemDetails {
  type: string;
  title: string;
  status: number;
  detail?: string;
  error_code?: string;
  errors?: Record<string, string[]>;
}

export function createSilarisClient(options: SilarisClientOptions) {
  const client = createOpenApiClient<paths>({ baseUrl: options.baseUrl });

  const auth: Middleware = {
    onRequest({ request }) {
      const token = options.getToken();
      if (token) request.headers.set("Authorization", `Bearer ${token}`);
      request.headers.set("Accept", "application/json");
      return request;
    },
    onResponse({ response }) {
      if (response.status === 401) options.onUnauthorized?.();
      return response;
    },
  };
  client.use(auth);

  return client;
}
