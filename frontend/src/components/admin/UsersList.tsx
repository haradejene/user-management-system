"use client";

import Link from "next/link";
import { useEffect, useRef, useState } from "react";
import { AdminPage, StatusBadge, TableCell, TableHead } from "@/components/admin/AdminPage";
import { Alert } from "@/components/ui/Alert";
import { DataTable } from "@/components/ui/DataTable";
import { EmptyState } from "@/components/ui/EmptyState";
import { LoadingState } from "@/components/ui/LoadingState";
import { Pagination } from "@/components/ui/Pagination";
import { getApiErrorMessage } from "@/services/api-client";
import { usersService } from "@/services/users.service";
import type { PaginatedResponse } from "@/types/api";
import type { User } from "@/types/user";

const emptyPage: PaginatedResponse<User> = { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } };

export function UsersList() {
  const [result, setResult] = useState<PaginatedResponse<User>>(emptyPage);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("");
  const [page, setPage] = useState(1);
  const requestSequence = useRef(0);

  useEffect(() => {
    const sequence = ++requestSequence.current;
    const timer = setTimeout(() => {
      usersService.list(search, page, 25, status || undefined)
        .then((next) => { if (sequence === requestSequence.current) { setResult(next); setError(null); setLoading(false); } })
        .catch((requestError) => { if (sequence === requestSequence.current) { setError(getApiErrorMessage(requestError)); setLoading(false); } });
    }, 250);
    return () => { clearTimeout(timer); };
  }, [page, search, status]);

  function changeSearch(value: string): void {
    setSearch(value); setPage(1); setLoading(true);
  }

  function changeStatus(value: string): void {
    setStatus(value); setPage(1); setLoading(true);
  }

  function changePage(nextPage: number): void {
    setPage(nextPage); setLoading(true);
  }

  return <AdminPage title="Users" description="Manage Central IAM identities and account lifecycle." action={{ href: "/users/new", label: "Create user" }}>
    <div className="mb-5 flex flex-wrap gap-3">
      <input aria-label="Search users" value={search} onChange={(event) => changeSearch(event.target.value)} placeholder="Search name or email" className="w-full max-w-sm rounded-md border border-slate-300 px-3 py-2 text-sm" />
      <select aria-label="Filter users by status" value={status} onChange={(event) => changeStatus(event.target.value)} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm"><option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option><option value="suspended">Suspended</option></select>
    </div>
    {error ? <Alert>{error}</Alert> : loading ? <LoadingState label="Loading users…" /> : result.data.length === 0 ? <EmptyState message="No users found." /> : <><DataTable><thead><tr><TableHead>User</TableHead><TableHead>Status</TableHead><TableHead>Access</TableHead><TableHead>Actions</TableHead></tr></thead><tbody>{result.data.map((user) => <tr key={user.id}><TableCell><div className="font-medium text-slate-900">{user.name}</div><div className="text-slate-500">{user.email}</div></TableCell><TableCell><StatusBadge status={user.status} /></TableCell><TableCell>{user.is_system_admin ? "Central administrator" : "Standard user"}</TableCell><TableCell><Link className="font-medium text-slate-900 underline" href={`/users/${user.id}`}>View</Link></TableCell></tr>)}</tbody></DataTable><Pagination currentPage={result.meta.current_page} lastPage={result.meta.last_page} onPageChange={changePage} /></>}
  </AdminPage>;
}
