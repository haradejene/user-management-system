"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
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
  const usersSequence = useRef(0);
  const appsSequence = useRef(0);
  const assignmentsSequence = useRef(0);
  const profileSequence = useRef(0);
  const actionSequence = useRef(0);
  const mutationState = useRef({ version: 0, pending: false });
  const [users, setUsers] = useState<PaginatedResponse<User>>(emptyPage());
  const [apps, setApps] = useState<PaginatedResponse<Application>>(emptyPage());
  const [grants, setGrants] = useState<PaginatedResponse<ApplicationAccess>>(emptyPage());
  const [userId, setUserId] = useState(params.get("user") ?? "");
  const [userPage, setUserPage] = useState(1);
  const [appPage, setAppPage] = useState(1);
  const selectedId = useRef(userId);
  const [selectedUser, setSelectedUser] = useState<User | null>(null);
  const [assignmentScope, setAssignmentScope] = useState("");
  const [appsLoading, setAppsLoading] = useState(true);
  const [busy, setBusy] = useState<string | null>(null);
  const [pendingRevoke, setPendingRevoke] = useState<Application | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [overrides, setOverrides] = useState<Record<string, ApplicationAccess | null>>({});

  const selectUser = useCallback((id: string) => {
    if (selectedId.current === id) return;
    selectedId.current = id;
    ++actionSequence.current;
    ++assignmentsSequence.current;
    ++profileSequence.current;
    mutationState.current = { version: mutationState.current.version + 1, pending: false };
    setUserId(id); setSelectedUser(null); setAssignmentScope("");
    setOverrides({}); setPendingRevoke(null); setBusy(null); setError(null);
  }, []);

  useEffect(() => {
    const sequence = ++usersSequence.current;
    let cancelled = false;
    usersService.list("", userPage, 15).then((response) => {
      if (cancelled || sequence !== usersSequence.current) return;
      setUsers(response);
      if (!selectedId.current && response.data[0]) selectUser(response.data[0].id);
    }).catch((e) => { if (!cancelled && sequence === usersSequence.current) setError(getApiErrorMessage(e)); });
    return () => { cancelled = true; };
  }, [userPage, selectUser]);

  useEffect(() => {
    const sequence = ++appsSequence.current;
    let cancelled = false;
    applicationsService.list("", appPage, 15).then((response) => {
      if (!cancelled && sequence === appsSequence.current) setApps(response);
    }).catch((e) => { if (!cancelled && sequence === appsSequence.current) setError(getApiErrorMessage(e)); })
      .finally(() => { if (!cancelled && sequence === appsSequence.current) setAppsLoading(false); });
    return () => { cancelled = true; };
  }, [appPage]);

  const applicationIdsKey = JSON.stringify(apps.data.map((app) => app.id));
  const scope = `${userId}:${appPage}:${applicationIdsKey}`;

  useEffect(() => {
    if (!userId || apps.meta.current_page !== appPage || !apps.data.length) return;
    const sequence = ++assignmentsSequence.current;
    let cancelled = false;
    const mutationVersion = mutationState.current.version;
    const ids: string[] = JSON.parse(applicationIdsKey);
    applicationsService.forUser(userId, 1, ids.length, ids)
      .then((response) => {
        if (cancelled || sequence !== assignmentsSequence.current) return;
        if (response.meta.last_page > 1) throw new Error("Incomplete assignment lookup");
        setGrants(response); setAssignmentScope(scope);
        // Only a lookup begun after the last mutation can replace its local result.
        if (mutationVersion === mutationState.current.version && !mutationState.current.pending) {
          setOverrides((current) => Object.fromEntries(Object.entries(current).filter(([id]) => !ids.includes(id))));
        }
      })
      .catch((e) => { if (!cancelled && sequence === assignmentsSequence.current) setError(getApiErrorMessage(e)); });
    return () => { cancelled = true; };
  }, [userId, appPage, applicationIdsKey, apps.meta.current_page, apps.data.length, scope]);

  useEffect(() => {
    if (!userId) return;
    const sequence = ++profileSequence.current;
    let cancelled = false;
    usersService.get(userId).then((user) => {
      if (!cancelled && sequence === profileSequence.current) setSelectedUser(user);
    }).catch((e) => { if (!cancelled && sequence === profileSequence.current) setError(getApiErrorMessage(e)); });
    return () => { cancelled = true; };
  }, [userId]);

  const userOptions = selectedUser && !users.data.some((user) => user.id === selectedUser.id) ? [selectedUser, ...users.data] : users.data;
  const loading = appsLoading || (Boolean(userId) && (!selectedUser || (apps.data.length > 0 && assignmentScope !== scope)));
  const grantsByApplication = useMemo(() => new Map(grants.data.map((grant) => [grant.id, grant])), [grants.data]);
  // A null override is a successful deletion, not a missing cache entry.
  const assignmentFor = (application: Application) => Object.prototype.hasOwnProperty.call(overrides, application.id) ? overrides[application.id] : grantsByApplication.get(application.id) ?? null;

  async function grant(application: Application) {
    const selected = userId; const action = ++actionSequence.current;
    mutationState.current = { version: mutationState.current.version + 1, pending: true };
    setBusy(application.id); setError(null);
    try {
      const item = await applicationsService.grant(selected, application.id);
      if (action === actionSequence.current && selected === selectedId.current) setOverrides((current) => ({ ...current, [application.id]: item }));
    } catch (e) { if (action === actionSequence.current) setError(getApiErrorMessage(e)); }
    finally { if (action === actionSequence.current) { mutationState.current = { version: mutationState.current.version + 1, pending: false }; setBusy(null); } }
  }

  async function revoke() {
    if (!pendingRevoke) return;
    const application = pendingRevoke; const selected = userId; const action = ++actionSequence.current;
    mutationState.current = { version: mutationState.current.version + 1, pending: true };
    setBusy(application.id); setError(null);
    try {
      await applicationsService.revoke(selected, application.id);
      if (action === actionSequence.current && selected === selectedId.current) {
        setOverrides((current) => ({ ...current, [application.id]: null }));
        setPendingRevoke(null);
      }
    } catch (e) { if (action === actionSequence.current) setError(getApiErrorMessage(e)); }
    finally { if (action === actionSequence.current) { mutationState.current = { version: mutationState.current.version + 1, pending: false }; setBusy(null); } }
  }

  return <AdminPage title="Application access" description="Central IAM answers whether a user may enter an application—not what they may do inside it.">
    {error ? <div className="mb-4"><Alert>{error}</Alert></div> : null}
    <label className="mb-2 block text-sm font-medium" htmlFor="access-user">User</label>
    <select id="access-user" value={userId} onChange={(event) => selectUser(event.target.value)} className="mb-2 min-h-11 w-full max-w-lg rounded-md border border-slate-300 bg-white px-3"><option value="">Select a user</option>{userOptions.map((user) => <option key={user.id} value={user.id}>{user.name} — {user.email}</option>)}</select>
    <Pagination currentPage={users.meta.current_page} lastPage={users.meta.last_page} onPageChange={setUserPage} />
    {loading ? <LoadingState /> : !selectedUser ? <EmptyState message="Select a user to manage application access." /> : <section className="mt-8 rounded-xl border border-slate-200 bg-white p-6">
      <div className="mb-5"><p className="text-sm text-slate-500">User</p><h2 className="text-2xl font-semibold">{selectedUser.name}</h2><p className="text-sm text-slate-500">{selectedUser.status} account</p></div>
      <div className="space-y-2">{apps.data.map((application) => { const assignment = assignmentFor(application); const assigned = assignment?.assignment_exists ?? false; const effective = assignment?.effective_access ?? false; const blockedBy = assignment && !effective ? reasonLabel(assignment.ineffective_reason) : application.status !== "active" ? "application inactive" : selectedUser.status !== "active" ? `${selectedUser.status} user` : ""; return <label key={application.id} className={`flex items-center justify-between rounded-lg border p-4 ${!effective ? "bg-slate-50" : "bg-white"}`}><span className="flex items-center gap-3"><input type="checkbox" checked={assigned} disabled={Boolean(busy) || (!assigned && application.status !== "active")} onChange={() => assigned ? setPendingRevoke(application) : void grant(application)} className="size-5" /><span><span className="font-medium">{application.name}</span><span className="ml-2 text-xs text-slate-500">{application.slug}</span><span className="ml-2 text-xs text-slate-500">{assigned ? (effective ? "effective access" : `assigned · ineffective: ${blockedBy}`) : "not assigned"}</span></span></span><StatusBadge status={application.status} /></label>; })}</div>
      <Pagination currentPage={apps.meta.current_page} lastPage={apps.meta.last_page} onPageChange={(page) => { setAppsLoading(true); setAppPage(page); }} />
      <p className="mt-5 text-xs text-slate-500">Assignments remain global. Roles and permissions inside HRM, CRM, ERP, and other services are managed by those applications.</p>
    </section>}
    <ConfirmDialog open={Boolean(pendingRevoke)} title="Revoke application access" message={`Remove ${selectedUser?.name ?? "this user"}'s access to ${pendingRevoke?.name ?? "this application"}?`} confirmLabel="Revoke access" busy={Boolean(busy)} onCancel={() => setPendingRevoke(null)} onConfirm={revoke} />
  </AdminPage>;
}
