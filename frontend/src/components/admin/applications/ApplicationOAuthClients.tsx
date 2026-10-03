"use client";

import { useEffect, useRef, useState } from "react";
import { StatusBadge, TableCell, TableHead } from "@/components/admin/AdminPage";
import { ConfirmDialog } from "@/components/admin/ConfirmDialog";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { DataTable } from "@/components/ui/DataTable";
import { EmptyState } from "@/components/ui/EmptyState";
import { LoadingState } from "@/components/ui/LoadingState";
import { Pagination } from "@/components/ui/Pagination";
import { applicationsService } from "@/services/applications.service";
import { getApiErrorMessage } from "@/services/api-client";
import type { PaginatedResponse } from "@/types/api";
import type { OAuthClient } from "@/types/oauth-client";
import { applicationDate } from "./ApplicationOverview";
import { OAuthClientDetail } from "./OAuthClientDetail";
import { OAuthClientForm } from "./OAuthClientForm";

export function ApplicationOAuthClients({ applicationId }: { applicationId: string }) {
  return <OAuthClientsState key={applicationId} applicationId={applicationId} />;
}

function OAuthClientsState({ applicationId }: { applicationId: string }) {
  const [result, setResult] = useState<PaginatedResponse<OAuthClient> | null>(null);
  const [page, setPage] = useState(1);
  const [retry, setRetry] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [form, setForm] = useState(false);
  const [detail, setDetail] = useState<string | null>(null);
  const [target, setTarget] = useState<OAuthClient | null>(null);
  const [busy, setBusy] = useState(false);
  const [mutationError, setMutationError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const requests = useRef(0);
  const pending = useRef(false);
  const mounted = useRef(true);
  useEffect(() => { mounted.current = true; return () => { mounted.current = false; }; }, []);
  useEffect(() => {
    const sequence = ++requests.current;
    let cancelled = false;
    applicationsService.oauthClients(applicationId, page, 25).then((response) => {
      if (cancelled || sequence !== requests.current) return;
      setResult(response); setError(null); setLoading(false);
    }).catch((e) => { if (!cancelled && sequence === requests.current) { setError(getApiErrorMessage(e)); setLoading(false); } });
    return () => { cancelled = true; };
  }, [applicationId, page, retry]);

  function refresh(nextPage = 1) { ++requests.current; setLoading(true); setError(null); setPage(nextPage); setRetry((n) => n + 1); }
  async function revoke() {
    if (!target || pending.current) return;
    const client = target;
    pending.current = true; setBusy(true); setMutationError(null); setSuccess(null);
    try {
      await applicationsService.revokeOAuthClient(applicationId, client.id);
      if (!mounted.current) return;
      setTarget(null); setSuccess(`${client.name} revoked.`); refresh();
    } catch (e) { if (mounted.current) setMutationError(getApiErrorMessage(e)); }
    finally { pending.current = false; if (mounted.current) setBusy(false); }
  }

  return <section aria-label="OAuth Clients" className="space-y-4">
    <div className="flex flex-wrap items-center justify-between gap-3"><h2 className="text-xl font-semibold">OAuth Clients</h2><Button disabled={busy} onClick={() => { setDetail(null); setForm(true); }}>Register OAuth Client</Button></div>
    {mutationError ? <Alert>{mutationError}</Alert> : null}
    {success ? <p role="status" className="text-sm text-emerald-800">{success}</p> : null}
    {loading ? <LoadingState label="Loading OAuth clients…" /> : error ? <><Alert>{error}</Alert><Button variant="secondary" onClick={() => refresh(page)}>Retry</Button></> : !result?.data.length ? <EmptyState message="This application has no OAuth clients." /> : <>
      <DataTable><thead><tr>{["Client name", "Client ID", "Client type", "Status", "Grant types", "Redirect URIs", "Created at", "Updated at", "Actions"].map((heading) => <TableHead key={heading}>{heading}</TableHead>)}</tr></thead>
        <tbody>{result.data.map((client) => <tr key={client.id}>
          <TableCell>{client.name}</TableCell><TableCell><code>{client.id}</code></TableCell><TableCell>{client.confidential ? "Confidential" : "Public"}</TableCell><TableCell><StatusBadge status={client.revoked ? "revoked" : "active"} /></TableCell>
          <TableCell>{client.grant_types.join(", ")}</TableCell><TableCell><ul>{client.redirect_uris.map((uri, index) => <li className="min-w-48 break-all" key={index}>{uri}</li>)}</ul></TableCell><TableCell>{applicationDate(client.created_at)}</TableCell><TableCell>{applicationDate(client.updated_at)}</TableCell>
          <TableCell><div className="flex gap-2"><Button variant="secondary" disabled={busy} onClick={() => setDetail(client.id)}>View details</Button>{!client.revoked ? <Button variant="danger" disabled={busy} onClick={() => { setMutationError(null); setTarget(client); }}>Revoke</Button> : null}</div></TableCell>
        </tr>)}</tbody>
      </DataTable><Pagination currentPage={result.meta.current_page} lastPage={result.meta.last_page} onPageChange={(next) => refresh(next)} />
    </>}
    {form ? <OAuthClientForm applicationId={applicationId} onCreated={() => refresh()} onClose={() => setForm(false)} /> : null}
    {detail ? <OAuthClientDetail key={detail} applicationId={applicationId} clientId={detail} onClose={() => setDetail(null)} /> : null}
    <ConfirmDialog open={Boolean(target)} title="Revoke OAuth client" message={`Revoke ${target?.name ?? "this client"}? This security action disables the client. There is no restore action.`} confirmLabel="Revoke client" busy={busy} onCancel={() => { if (!pending.current) { setTarget(null); setMutationError(null); } }} onConfirm={() => void revoke()} />
  </section>;
}
