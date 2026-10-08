"use client";

import { AxiosError } from "axios";
import { useEffect, useRef, useState } from "react";
import { StatusBadge } from "@/components/admin/AdminPage";
import { FormField } from "@/components/forms/FormField";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { EmptyState } from "@/components/ui/EmptyState";
import { LoadingState } from "@/components/ui/LoadingState";
import { Modal } from "@/components/ui/Modal";
import { Pagination } from "@/components/ui/Pagination";
import { applicationsService } from "@/services/applications.service";
import { usersService } from "@/services/users.service";
import { getApiErrorMessage } from "@/services/api-client";
import type { PaginatedResponse, ValidationErrorResponse } from "@/types/api";
import type { User } from "@/types/user";

/** Follows the global access manager's paginated name/email selection and
 * authoritative selected-user lookup, with independent search/error state. */
export function ApplicationUserAccessGrant({ applicationId, applicationName, onGranted, onClose }: { applicationId: string; applicationName: string; onGranted: (user: User) => void; onClose: () => void }) {
  const [result, setResult] = useState<PaginatedResponse<User> | null>(null);
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [retry, setRetry] = useState(0);
  const [loading, setLoading] = useState(true);
  const [listError, setListError] = useState<string | null>(null);
  const [userId, setUserId] = useState("");
  const [user, setUser] = useState<User | null>(null);
  const [profileRetry, setProfileRetry] = useState(0);
  const [profileError, setProfileError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<string[]>([]);
  const listRequests = useRef(0);
  const profileRequests = useRef(0);
  const pending = useRef(false);
  const mounted = useRef(true);
  useEffect(() => { mounted.current = true; return () => { mounted.current = false; }; }, []);

  useEffect(() => {
    const sequence = ++listRequests.current;
    let cancelled = false;
    const timer = setTimeout(() => {
      usersService.list(search, page, 15).then((response) => {
        if (cancelled || sequence !== listRequests.current) return;
        setResult(response); setListError(null); setLoading(false);
      }).catch((e) => { if (!cancelled && sequence === listRequests.current) { setListError(getApiErrorMessage(e)); setLoading(false); } });
    }, 250);
    return () => { cancelled = true; clearTimeout(timer); };
  }, [search, page, retry]);

  useEffect(() => {
    if (!userId) return;
    const sequence = ++profileRequests.current;
    let cancelled = false;
    usersService.get(userId).then((response) => { if (!cancelled && sequence === profileRequests.current) setUser(response); })
      .catch((e) => { if (!cancelled && sequence === profileRequests.current) setProfileError(getApiErrorMessage(e)); });
    return () => { cancelled = true; };
  }, [userId, profileRetry]);

  function requestList(nextPage: number, nextSearch = search) {
    ++listRequests.current; setLoading(true); setListError(null); setPage(nextPage); setSearch(nextSearch); setRetry((n) => n + 1);
  }
  function selectUser(id: string) {
    if (pending.current) return;
    ++profileRequests.current; setUserId(id); setUser(null); setProfileError(null); setErrors([]);
  }
  async function grant(event: React.FormEvent) {
    event.preventDefault();
    if (!user || user.status !== "active" || pending.current) return;
    const selected = user;
    pending.current = true; setBusy(true); setErrors([]);
    try {
      // Existing backend grant route requires the context ID in its body.
      // It comes only from ApplicationDetails, never from a form control.
      await applicationsService.grant(selected.id, applicationId);
      if (mounted.current) onGranted(selected);
    } catch (e) {
      if (!mounted.current) return;
      const validation = e instanceof AxiosError && e.response?.status === 422 ? (e.response.data as ValidationErrorResponse).errors : undefined;
      setErrors(validation ? Object.values(validation).flat() : [getApiErrorMessage(e)]);
    } finally { pending.current = false; if (mounted.current) setBusy(false); }
  }

  const options = user && !result?.data.some((item) => item.id === user.id) ? [user, ...(result?.data ?? [])] : result?.data ?? [];
  return <Modal open title="Grant application access" onClose={() => { if (!pending.current) onClose(); }}>
    <form onSubmit={grant} className="max-h-[70vh] overflow-y-auto space-y-4">
      <p className="text-sm">Application: <strong>{applicationName}</strong></p>
      {errors.length ? <Alert><ul>{errors.map((message, index) => <li key={index}>{message}</li>)}</ul></Alert> : null}
      <fieldset disabled={busy} className="space-y-4">
        <FormField name="grant-user-search" label="Search users to grant access" value={search} maxLength={100} placeholder="Search name or email" onChange={(e) => requestList(1, e.target.value)} />
        {loading ? <LoadingState label="Loading users…" /> : listError ? <><Alert>{listError}</Alert><Button type="button" variant="secondary" onClick={() => requestList(page)}>Retry user search</Button></> : <>
          {!result?.data.length ? <EmptyState message="No users found." /> : null}
          <div><label htmlFor="grant-user" className="block text-sm font-medium">User</label><select id="grant-user" value={userId} onChange={(e) => selectUser(e.target.value)} className="min-h-11 w-full rounded-md border border-slate-300 bg-white px-3"><option value="">Select a user</option>{options.map((item) => <option key={item.id} value={item.id}>{item.name} — {item.email}</option>)}</select></div>
          {result ? <Pagination currentPage={result.meta.current_page} lastPage={result.meta.last_page} onPageChange={(next) => requestList(next)} /> : null}
        </>}
        {userId ? profileError ? <><Alert>{profileError}</Alert><Button type="button" variant="secondary" onClick={() => { ++profileRequests.current; setProfileError(null); setProfileRetry((n) => n + 1); }}>Retry selected user</Button></> : user ? <div className="rounded border p-3 text-sm"><p className="font-medium">Selected user: {user.name}</p><p>{user.email}</p><p>Doxa user ID: <code className="break-all">{user.id}</code></p><p>Account status: <StatusBadge status={user.status} /></p></div> : <LoadingState label="Loading selected user…" /> : null}
        <p className="text-sm text-slate-500">Select an existing active Doxa identity explicitly. This grants IAM application access only; it does not create an HRM user, link by email, or assign business roles. Effective access requires an active user, application, and assignment.</p>
        <div className="flex gap-3"><Button type="submit" disabled={!user || user.status !== "active"} isLoading={busy}>Grant assignment</Button><Button type="button" variant="secondary" onClick={onClose}>Cancel</Button></div>
      </fieldset>
    </form>
  </Modal>;
}
