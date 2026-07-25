"use client";

import { useQuery } from "@tanstack/react-query";
import { rawApi } from "@/lib/api";
import { PortalShell } from "@/components/PortalShell";

interface PortalDocument {
  id: string;
  type: string;
  title: string;
  created_at: string;
}

export default function PortalDocumentsPage() {
  const { data } = useQuery({
    queryKey: ["portal", "documents"],
    queryFn: async () => {
      const { data: response } = await rawApi.GET("/v1/portal/documents");
      return (response as { data: PortalDocument[] }).data;
    },
  });

  async function download(documentId: string) {
    const { data: response } = await rawApi.GET(`/v1/portal/documents/${documentId}/download-url`);
    const { url } = response as { url: string };
    window.open(url, "_blank");
  }

  return (
    <PortalShell>
      <h1 className="pb-4 text-xl font-bold">Mes documents</h1>
      <div className="rounded-xl border border-line bg-surface shadow-sm">
        {data?.length === 0 && <p className="p-6 text-center text-[13px] text-ink-3">Aucun document disponible.</p>}
        <ul>
          {data?.map((document) => (
            <li key={document.id} className="flex items-center gap-3 border-b border-line px-4 py-3 text-[13px] last:border-0">
              <span>📄</span>
              <div>
                <div className="font-semibold">{document.title}</div>
                <div className="text-[11px] text-ink-3">{new Date(document.created_at).toLocaleDateString("fr-FR")}</div>
              </div>
              <button onClick={() => download(document.id)} className="ml-auto text-xs font-semibold text-sea hover:underline">
                Télécharger
              </button>
            </li>
          ))}
        </ul>
      </div>
    </PortalShell>
  );
}
