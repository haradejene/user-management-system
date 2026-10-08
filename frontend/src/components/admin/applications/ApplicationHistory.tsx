"use client";

import { useEffect, useState } from "react";
import { apiClient, getApiErrorMessage } from "@/services/api-client";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";

type History = { data: { id: number; action: string; actor_id: string | null; subject_id: string | null; client_id: string | null; created_at: string }[]; last_page: number };
export function ApplicationHistory({ applicationId }: { applicationId: string }) {
  const [open, setOpen] = useState(false);
  const [page, setPage] = useState(1);
  const [retry, setRetry] = useState(0);
  const [history, setHistory] = useState<History | null>(null);
  const [error, setError] = useState("");
  useEffect(() => {
    if (!open) return;
    let cancelled = false;
    apiClient.get<History>(`/api/admin/applications/${applicationId}/history`, { params: { page } }).then(({ data }) => { if (!cancelled) { setHistory(data); setError(""); } }).catch((e) => { if (!cancelled) setError(getApiErrorMessage(e)); });
    return () => { cancelled = true; };
  }, [applicationId, open, page, retry]);
  return <section className="mt-8 space-y-3 border-t pt-4" aria-label="Administrative history">
    <Button variant="secondary" aria-expanded={open} onClick={() => { setHistory(null); setOpen(!open); }}>Administrative history</Button>
    {open ? <>
      <Button variant="secondary" onClick={() => { setHistory(null); setRetry(retry + 1); }}>Refresh history</Button>
      <p className="text-sm text-slate-600">Stored IAM audit events. Historical client operations may be missing where auditing was previously unavailable. Client restore and secret rotation are not supported.</p>
      {error ? <><Alert>{error}</Alert><Button onClick={() => setRetry(retry + 1)}>Retry history</Button></> : history ? <>
        {!history.data.length ? <p>No recorded events.</p> : <ul className="space-y-2">{history.data.map((event) => <li key={event.id} className="rounded border p-3 text-sm"><p>{event.action}</p>{event.client_id ? <p>Client: {event.client_id}</p> : null}<p>{event.created_at}</p><p>Actor: {event.actor_id ?? "System"}; subject: {event.subject_id ?? "Unavailable"}</p></li>)}</ul>}
        <div className="flex gap-3"><Button disabled={page <= 1} onClick={() => { setHistory(null); setPage(page - 1); }}>Previous history</Button><span>Page {page}</span><Button disabled={page >= history.last_page} onClick={() => { setHistory(null); setPage(page + 1); }}>Next history</Button></div>
      </> : <p role="status">Loading history…</p>}
    </> : null}
  </section>;
}
