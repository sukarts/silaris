"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useRef, useState } from "react";
import { problemMessage, rawApi } from "@/lib/api";
import { Field, buttonPrimary, buttonSecondary, inputClass } from "@/components/Field";
import { useAuth, useCan } from "@/stores/auth";

interface DocumentVersion {
  id: string;
  version: number;
  original_filename: string;
  mime_type: string;
  size_bytes: number;
  created_at: string;
}

interface DocumentItem {
  id: string;
  shipment_id: string | null;
  party_id: string | null;
  type: string;
  title: string;
  visibility: string;
  status: string;
  is_archived: boolean;
  created_at: string;
  versions?: DocumentVersion[];
}

interface ShipmentRef {
  id: string;
  reference: string;
}

const TYPE_LABEL: Record<string, string> = {
  bl: "BL",
  hbl: "HBL",
  mbl: "MBL",
  awb: "AWB",
  commercial_invoice: "Facture commerciale",
  packing_list: "Liste de colisage",
  certificate_origin: "Certificat d'origine",
  insurance: "Assurance",
  customs: "Douane",
  photo: "Photo",
  contract: "Contrat",
  other: "Autre",
};

const STATUS_LABEL: Record<string, [string, string]> = {
  missing: ["Manquant", "bg-warn-soft text-warn"],
  received: ["Reçu", "bg-sea-soft text-sea"],
  validated: ["Validé", "bg-ok-soft text-ok"],
};

const VISIBILITY_LABEL: Record<string, string> = {
  internal: "Interne",
  client: "Client",
  confidential: "Confidentiel",
};

function formatBytes(bytes?: number): string {
  if (!bytes && bytes !== 0) return "—";
  if (bytes < 1024) return `${bytes} o`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} Ko`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} Mo`;
}

export default function DocumentsPage() {
  const queryClient = useQueryClient();
  const canCreate = useCan("documents.create");
  const canDownload = useCan("documents.download");
  const canArchive = useCan("documents.archive");
  const [statusFilter, setStatusFilter] = useState("");
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState({ type: "bl", title: "", visibility: "internal", shipment_id: "" });
  const [error, setError] = useState<string | null>(null);
  const fileRef = useRef<HTMLInputElement>(null);

  const { data, isLoading } = useQuery({
    queryKey: ["documents", statusFilter],
    queryFn: async () => {
      const { data: response } = await rawApi.GET("/v1/documents", {
        params: { query: { ...(statusFilter ? { status: statusFilter } : {}) } },
      });
      return response as { data: DocumentItem[] };
    },
  });

  const { data: shipments } = useQuery({
    queryKey: ["shipments-refs"],
    queryFn: async () => {
      const { data: response } = await rawApi.GET("/v1/shipments", {
        params: { query: { per_page: 100 } },
      });
      return response as { data: ShipmentRef[] };
    },
  });

  const shipmentReference = new Map((shipments?.data ?? []).map((s) => [s.id, s.reference]));

  const upload = useMutation({
    mutationFn: async () => {
      const file = fileRef.current?.files?.[0];
      if (!file) throw { detail: "Sélectionnez un fichier." };
      const body = new FormData();
      body.append("type", form.type);
      body.append("title", form.title);
      body.append("visibility", form.visibility);
      if (form.shipment_id) body.append("shipment_id", form.shipment_id);
      body.append("file", file);
      const baseUrl = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8088/api";
      const response = await fetch(`${baseUrl}/v1/documents`, {
        method: "POST",
        headers: {
          Accept: "application/json",
          Authorization: `Bearer ${useAuth.getState().token ?? ""}`,
        },
        body,
      });
      if (!response.ok) {
        throw await response.json().catch(() => ({ detail: `Erreur HTTP ${response.status}` }));
      }
    },
    onSuccess: () => {
      setShowForm(false);
      setForm({ type: "bl", title: "", visibility: "internal", shipment_id: "" });
      if (fileRef.current) fileRef.current.value = "";
      setError(null);
      queryClient.invalidateQueries({ queryKey: ["documents"] });
    },
    onError: (problem) => setError(problemMessage(problem)),
  });

  const download = useMutation({
    mutationFn: async (documentId: string) => {
      const { data: response, error: problem } = await rawApi.GET(`/v1/documents/${documentId}/download-url`);
      if (problem) throw problem;
      const url = (response as { url?: string } | undefined)?.url;
      if (url) window.open(url, "_blank");
    },
    onSuccess: () => setError(null),
    onError: (problem) => setError(problemMessage(problem)),
  });

  const archive = useMutation({
    mutationFn: async (documentId: string) => {
      const { error: problem } = await rawApi.POST(`/v1/documents/${documentId}/archive`);
      if (problem) throw problem;
    },
    onSuccess: () => {
      setError(null);
      queryClient.invalidateQueries({ queryKey: ["documents"] });
    },
    onError: (problem) => setError(problemMessage(problem)),
  });

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-start">
        <div>
          <h1 className="text-xl font-bold">Documents</h1>
          <p className="text-[13px] text-ink-3">BL, factures, certificats — versions et téléchargements tracés</p>
        </div>
        {canCreate && (
          <button onClick={() => setShowForm((value) => !value)} className={`ml-auto ${buttonPrimary}`}>
            + Déposer un document
          </button>
        )}
      </div>

      {showForm && (
        <form
          onSubmit={(event) => { event.preventDefault(); upload.mutate(); }}
          className="grid gap-4 rounded-xl border border-line bg-surface p-5 shadow-sm md:grid-cols-6"
        >
          <Field label="Type">
            <select value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })} className={inputClass}>
              {Object.entries(TYPE_LABEL).map(([value, label]) => (
                <option key={value} value={value}>{label}</option>
              ))}
            </select>
          </Field>
          <Field label="Titre" className="md:col-span-2">
            <input required maxLength={255} value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} className={inputClass} />
          </Field>
          <Field label="Dossier lié">
            <select value={form.shipment_id} onChange={(e) => setForm({ ...form, shipment_id: e.target.value })} className={inputClass}>
              <option value="">— Aucun —</option>
              {(shipments?.data ?? []).map((shipment) => (
                <option key={shipment.id} value={shipment.id}>{shipment.reference}</option>
              ))}
            </select>
          </Field>
          <Field label="Visibilité">
            <select value={form.visibility} onChange={(e) => setForm({ ...form, visibility: e.target.value })} className={inputClass}>
              <option value="internal">Interne</option>
              <option value="client">Client</option>
              <option value="confidential">Confidentiel</option>
            </select>
          </Field>
          <Field label="Fichier (max 25 Mo)">
            <input ref={fileRef} required type="file" accept=".pdf,.png,.jpg,.jpeg,.xlsx,.xls,.docx,.doc,.txt,.csv" className={inputClass} />
          </Field>
          {error && <p className="rounded-lg bg-crit-soft px-3 py-2 text-xs text-crit md:col-span-6">{error}</p>}
          <div className="flex gap-2 md:col-span-6">
            <button type="submit" disabled={upload.isPending} className={buttonPrimary}>
              {upload.isPending ? "Envoi…" : "Déposer"}
            </button>
            <button type="button" onClick={() => setShowForm(false)} className={buttonSecondary}>Annuler</button>
          </div>
        </form>
      )}

      {error && !showForm && <p className="rounded-lg bg-crit-soft px-4 py-2.5 text-[13px] text-crit">{error}</p>}

      <div className="flex flex-wrap gap-2">
        {["", "missing", "received", "validated"].map((status) => (
          <button
            key={status}
            onClick={() => setStatusFilter(status)}
            className={`rounded-full border px-3.5 py-1 text-xs font-semibold ${
              statusFilter === status ? "border-ink bg-ink text-paper" : "border-line-strong text-ink-2 hover:bg-surface"
            }`}
          >
            {status === "" ? "Tous" : STATUS_LABEL[status]?.[0]}
          </button>
        ))}
      </div>

      <div className="overflow-x-auto rounded-xl border border-line bg-surface shadow-sm">
        <table className="w-full text-[13px]">
          <thead>
            <tr className="border-b border-line text-left text-[10px] uppercase tracking-wider text-ink-3">
              <th className="px-3 py-2.5">Nom</th>
              <th className="px-3 py-2.5">Type</th>
              <th className="px-3 py-2.5">Dossier</th>
              <th className="px-3 py-2.5 text-right">Taille</th>
              <th className="px-3 py-2.5 text-right">Version</th>
              <th className="px-3 py-2.5">Statut</th>
              <th className="px-3 py-2.5">Date</th>
              <th className="px-3 py-2.5" />
            </tr>
          </thead>
          <tbody>
            {isLoading && (
              <tr><td colSpan={8} className="px-3 py-8 text-center text-ink-3">Chargement…</td></tr>
            )}
            {!isLoading && (data?.data ?? []).length === 0 && (
              <tr><td colSpan={8} className="px-3 py-8 text-center text-ink-3">Aucun document.</td></tr>
            )}
            {(data?.data ?? []).map((doc) => {
              const latest = doc.versions?.[0];
              const [statusLabel, statusTone] = STATUS_LABEL[doc.status] ?? [doc.status, "bg-paper text-ink-3"];
              return (
                <tr key={doc.id} className="border-b border-line last:border-0 hover:bg-sea/5">
                  <td className="px-3 py-2.5">
                    <span className="font-semibold">{doc.title}</span>
                    {doc.is_archived && (
                      <span className="ml-1.5 rounded border border-line bg-paper px-1.5 py-px text-[10px] text-ink-3">archivé</span>
                    )}
                    {latest?.original_filename && (
                      <span className="block text-[11px] text-ink-3">{latest.original_filename}</span>
                    )}
                  </td>
                  <td className="px-3 py-2.5 text-ink-2">
                    {TYPE_LABEL[doc.type] ?? doc.type}
                    <span className="block text-[11px] text-ink-3">{VISIBILITY_LABEL[doc.visibility] ?? doc.visibility}</span>
                  </td>
                  <td className="mono px-3 py-2.5 text-ink-2">
                    {doc.shipment_id ? shipmentReference.get(doc.shipment_id) ?? doc.shipment_id.slice(0, 8) : "—"}
                  </td>
                  <td className="mono px-3 py-2.5 text-right">{formatBytes(latest?.size_bytes)}</td>
                  <td className="mono px-3 py-2.5 text-right">{latest?.version ?? "—"}</td>
                  <td className="px-3 py-2.5">
                    <span className={`rounded-full px-2.5 py-0.5 text-[11px] font-semibold ${statusTone}`}>{statusLabel}</span>
                  </td>
                  <td className="mono px-3 py-2.5 text-ink-2">
                    {doc.created_at ? new Date(doc.created_at).toLocaleDateString("fr-FR") : "—"}
                  </td>
                  <td className="px-3 py-2.5">
                    <div className="flex gap-3">
                      {canDownload && (
                        <button
                          onClick={() => download.mutate(doc.id)}
                          disabled={download.isPending}
                          className="text-xs font-semibold text-sea hover:underline"
                        >
                          Télécharger
                        </button>
                      )}
                      {canArchive && !doc.is_archived && (
                        <button
                          onClick={() => archive.mutate(doc.id)}
                          disabled={archive.isPending}
                          className="text-xs font-semibold text-ink-3 hover:underline"
                        >
                          Archiver
                        </button>
                      )}
                    </div>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </div>
  );
}
