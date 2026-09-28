"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import { useSearchParams } from "next/navigation";
import { AdminPage, StatusBadge } from "@/components/admin/AdminPage";
import { ConfirmDialog } from "@/components/admin/ConfirmDialog";
import { Alert } from "@/components/ui/Alert";
import { EmptyState } from "@/components/ui/EmptyState";
import { LoadingState } from "@/components/ui/LoadingState";
import { Pagination } from "@/components/ui/Pagination";
import { getApiErrorMessage } from "@/services/api-client";
import { applicationsService } from "@/services/applications.service";
import { usersService } from "@/services/users.service";
import type { Application, ApplicationAccess } from "@/types/application";
import type { User } from "@/types/user";
import type { PaginatedResponse } from "@/types/api";

const emptyPage = <T,>(): PaginatedResponse<T> => ({ data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } });
const reasonLabel = (reason: string | null): string => reason?.replaceAll("_", " ") ?? "";

export function ApplicationAccessManager() {
  const params = useSearchParams();
  const requestSequence = useRef(0);
  const actionSequence = useRef(0);
  const [users, setUsers] = useState<PaginatedResponse<User>>(emptyPage());
  const [apps, setApps] = useState<PaginatedResponse<Application>>(emptyPage());
  const [grants, setGrants] = useState<PaginatedResponse<ApplicationAccess>>(emptyPage());
  const [userId, setUserId] = useState(params.get("user") ?? "");
  const [userPage, setUserPage] = useState(1);
  const [appPage, setAppPage] = useState(1);
  const [grantPage, setGrantPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState<string | null>(null);
  const [pendingRevoke, setPendingRevoke] = useState<Application | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [overrides, setOverrides] = useState<Record<string, ApplicationAccess | null>>({});

  useEffect(() => {
    const sequence = ++requestSequence.current;
    Promise.all([usersService.list("", userPage, 15), applicationsService.list("", appPage, 15)])
      .then(([usersResponse, applicationsResponse]) => {
        if (sequence !== requestSequence.current) return;
        setUsers(usersResponse); setApps(applicationsResponse);
        if (!userId && usersResponse.data[0]) setUserId(usersResponse.data[0].id);
      })
      .catch((e) => { if (sequence === requestSequence.current) setError(getApiErrorMessage(e)); })
      .finally(() => { if (sequence === requestSequence.current) setLoading(false); });
  }, [userId, userPage, appPage]);

  useEffect(() => {
    if (!userId) return;
    const sequence = ++requestSequence.current;
    applicationsService.forUser(userId, grantPage)
      .then((response) => { if (sequence === requestSequence.current) setGrants(response); })
      .catch((e) => { if (sequence === requestSequence.current) setError(getApiErrorMessage(e)); })
      .finally(() => { if (sequence === requestSequence.current) setLoading(false); });
  }, [userId, grantPage]);

  useEffect(() => {
    if (!userId || users.data.some((user) => user.id === userId) || userPage !== 1) return;
    const sequence = requestSequence.current;
    usersService.get(userId).then((user) => {
      if (sequence === requestSequence.current) {
        setUsers((current) => ({ ...current, data: [user, ...current.data.filter((item) => item.id !== user.id)] }));
      }
    }).catch(() => undefined);
  }, [userId, users.data, userPage]);

  const selectedUser = users.data.find((user) => user.id === userId);
  const grantsByApplication = useMemo(() => new Map(grants.data.map((grant) => [grant.id, grant])), [grants.data]);
  const assignmentFor = (application: Application) => overrides[application.id] ?? grantsByApplication.get(application.id) ?? null;

  function selectUser(id: string) {
    ++actionSequence.current;
    ++requestSequence.current;
    setUserId(id); setGrantPage(1); setOverrides({}); setPendingRevoke(null); setBusy(null); setLoading(true);
  }

  async function grant(application: Application) {
    const selected = userId; const action = ++actionSequence.current;
    setBusy(application.id); setError(null);
    try {
      const item = await applicationsService.grant(selected, application.id);
      if (action === actionSequence.current && selected === userId) setOverrides((current) => ({ ...current, [application.id]: item }));
    } catch (e) { if (action === actionSequence.current) setError(getApiErrorMessage(e)); }
    finally { if (action === actionSequence.current) setBusy(null); }
  }

  async function revoke() {
    if (!pendingRevoke) return;
    const application = pendingRevoke; const selected = userId; const action = ++actionSequence.current;
    setBusy(application.id); setError(null);
    try {
      await applicationsService.revoke(selected, application.id);
      if (action === actionSequence.current && selected === userId) setOverrides((current) => ({ ...current, [application.id]: null }));
      setPendingRevoke(null);
    } catch (e) { if (action === actionSequence.current) setError(getApiErrorMessage(e)); }
    finally { if (action === actionSequence.current) setBusy(null); }
  }

  return <AdminPage title="Application access" description="Central IAM answers whether a user may enter an application—not what they may do inside it.">
    {error ? <div className="mb-4"><Alert>{error}</Alert></div> : null}
    <label className="mb-2 block text-sm font-medium" htmlFor="access-user">User</label>
    <select id="access-user" value={userId} onChange={(event) => selectUser(event.target.value)} className="mb-2 min-h-11 w-full max-w-lg rounded-md border border-slate-300 bg-white px-3"><option value="">Select a user</option>{users.data.map((user) => <option key={user.id} value={user.id}>{user.name} — {user.email}</option>)}</select>
    <Pagination currentPage={users.meta.current_page} lastPage={users.meta.last_page} onPageChange={setUserPage} />
    {loading ? <LoadingState /> : !selectedUser ? <EmptyState message="Select a user to manage application access." /> : <section className="mt-8 rounded-xl border border-slate-200 bg-white p-6">
      <div className="mb-5"><p className="text-sm text-slate-500">User</p><h2 className="text-2xl font-semibold">{selectedUser.name}</h2><p className="text-sm text-slate-500">{selectedUser.status} account</p></div>
      <div className="space-y-2">{apps.data.map((application) => { const assignment = assignmentFor(application); const assigned = assignment?.assignment_exists ?? false; const effective = assignment?.effective_access ?? false; const blockedBy = assignment && !effective ? reasonLabel(assignment.ineffective_reason) : application.status !== "active" ? "application inactive" : selectedUser.status !== "active" ? `${selectedUser.status} user` : ""; return <label key={application.id} className={`flex items-center justify-between rounded-lg border p-4 ${!effective ? "bg-slate-50" : "bg-white"}`}><span className="flex items-center gap-3"><input type="checkbox" checked={assigned} disabled={busy === application.id || (!assigned && application.status !== "active")} onChange={() => assigned ? setPendingRevoke(application) : void grant(application)} className="size-5" /><span><span className="font-medium">{application.name}</span><span className="ml-2 text-xs text-slate-500">{application.slug}</span><span className="ml-2 text-xs text-slate-500">{assigned ? (effective ? "effective access" : `assigned · ineffective: ${blockedBy}`) : "not assigned"}</span></span></span><StatusBadge status={application.status} /></label>; })}</div>
      <Pagination currentPage={apps.meta.current_page} lastPage={apps.meta.last_page} onPageChange={setAppPage} />
      <p className="mt-5 text-xs text-slate-500">Assignments remain global. Roles and permissions inside HRM, CRM, ERP, and other services are managed by those applications.</p>
    </section>}
    <Pagination currentPage={grants.meta.current_page} lastPage={grants.meta.last_page} onPageChange={setGrantPage} />
    <ConfirmDialog open={Boolean(pendingRevoke)} title="Revoke application access" message={`Remove ${selectedUser?.name ?? "this user"}'s access to ${pendingRevoke?.name ?? "this application"}?`} confirmLabel="Revoke access" busy={Boolean(busy)} onCancel={() => setPendingRevoke(null)} onConfirm={revoke} />
  </AdminPage>;
}
