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
import type { ApplicationUser } from "@/types/application";
import { ApplicationUserAccessGrant } from "./ApplicationUserAccessGrant";

export function ApplicationUserAccess(props: { applicationId: string; applicationName: string }) {
  return <UserAccessState key={props.applicationId} {...props} />;
}

function UserAccessState({ applicationId, applicationName }: { applicationId: string; applicationName: string }) {
  const [result, setResult] = useState<PaginatedResponse<ApplicationUser> | null>(null);
  const [page, setPage] = useState(1);
  const [retry, setRetry] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [grant, setGrant] = useState(false);
  const [target, setTarget] = useState<ApplicationUser | null>(null);
  const [busy, setBusy] = useState(false);
  const [mutationError, setMutationError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const requests = useRef(0);
  const currentPage = useRef(1);
  const pending = useRef(false);
  const mounted = useRef(true);
  useEffect(() => { mounted.current = true; return () => { mounted.current = false; }; }, []);
  useEffect(() => {
    const sequence = ++requests.current;
    let cancelled = false;
    applicationsService.users(applicationId, page, 25).then((response) => {
      if (cancelled || sequence !== requests.current) return;
      // A detached last row can leave the current page outside the new range.
      if (page > response.meta.last_page) { currentPage.current = Math.max(1, response.meta.last_page); setPage(currentPage.current); return; }
      setResult(response); setError(null); setLoading(false);
    }).catch((e) => { if (!cancelled && sequence === requests.current) { setError(getApiErrorMessage(e)); setLoading(false); } });
    return () => { cancelled = true; };
  }, [applicationId, page, retry]);

  function refresh(nextPage = currentPage.current) { ++requests.current; currentPage.current = nextPage; setLoading(true); setError(null); setPage(nextPage); setRetry((n) => n + 1); }
  async function revoke() {
    if (!target || pending.current) return;
    const user = target;
    pending.current = true; setBusy(true); setMutationError(null); setSuccess(null);
    try {
      await applicationsService.revoke(user.id, applicationId);
      if (!mounted.current) return;
      setTarget(null); setSuccess(`Assignment removed for ${user.name} (${user.email}) from ${applicationName}.`); refresh();
    } catch (e) { if (mounted.current) setMutationError(getApiErrorMessage(e)); }
    finally { pending.current = false; if (mounted.current) setBusy(false); }
  }

  return <section aria-label="User Access" className="space-y-4">
    <div className="flex flex-wrap items-start justify-between gap-3"><div><p className="text-sm text-slate-500">Application: {applicationName}</p><h2 className="text-xl font-semibold">User Access</h2></div><Button disabled={busy} onClick={() => setGrant(true)}>Grant Access</Button></div>
    <p className="text-sm text-slate-600">Central IAM application assignments and effective access are shown separately. An assignment can remain active while access is not effective.</p>
    {mutationError ? <Alert>{mutationError}</Alert> : null}
    {success ? <p role="status" className="text-sm text-emerald-800">{success}</p> : null}
    {loading ? <LoadingState label="Loading user access…" /> : error ? <><Alert>{error}</Alert><Button variant="secondary" onClick={() => refresh()}>Retry</Button></> : !result?.data.length ? <EmptyState message="No users currently have an assignment for this application." /> : <>
      <DataTable><thead><tr>{["User name", "User email", "Account status", "Assignment status", "Effective access", "Ineffective reason", "Actions"].map((heading) => <TableHead key={heading}>{heading}</TableHead>)}</tr></thead>
        <tbody>{result.data.map((user) => <tr key={user.id}>
          <TableCell>{user.name}</TableCell><TableCell>{user.email}</TableCell><TableCell><StatusBadge status={user.status} /></TableCell>
          <TableCell>{user.assignment_exists ? <div>Assigned <StatusBadge status={user.access_status} /></div> : "Not assigned"}</TableCell>
          <TableCell>{user.effective_access ? "Yes — Granted" : "No — Not effective"}</TableCell><TableCell>{user.ineffective_reason ?? "—"}</TableCell>
          <TableCell>{user.assignment_exists ? <Button variant="danger" disabled={busy} onClick={() => { setMutationError(null); setTarget(user); }}>Revoke access</Button> : "—"}</TableCell>
        </tr>)}</tbody>
      </DataTable><Pagination currentPage={result.meta.current_page} lastPage={result.meta.last_page} onPageChange={(next) => refresh(next)} />
    </>}
    {grant ? <ApplicationUserAccessGrant applicationId={applicationId} applicationName={applicationName} onClose={() => setGrant(false)} onGranted={(user) => { setGrant(false); setSuccess(`Assignment granted for ${user.name} (${user.email}) to ${applicationName}.`); refresh(); }} /> : null}
    <ConfirmDialog open={Boolean(target)} title="Revoke application access" message={`Remove the assignment for ${target?.name ?? "this user"} (${target?.email ?? ""}) from ${applicationName}? This removes the application assignment and does not change the IAM user account.`} confirmLabel="Revoke assignment" busy={busy} onCancel={() => { if (!pending.current) { setTarget(null); setMutationError(null); } }} onConfirm={() => void revoke()} />
  </section>;
}
