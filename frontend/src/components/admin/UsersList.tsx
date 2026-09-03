"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { AdminPage, StatusBadge, TableCell, TableHead } from "@/components/admin/AdminPage";
import { Alert } from "@/components/ui/Alert";
import { DataTable } from "@/components/ui/DataTable";
import { EmptyState } from "@/components/ui/EmptyState";
import { LoadingState } from "@/components/ui/LoadingState";
import { getApiErrorMessage } from "@/services/api-client";
import { usersService } from "@/services/users.service";
import type { User } from "@/types/user";

export function UsersList() {
  const [users, setUsers] = useState<User[]>([]); const [loading, setLoading] = useState(true); const [error, setError] = useState<string | null>(null); const [search, setSearch] = useState("");
  useEffect(() => { const timer = setTimeout(() => { setLoading(true); usersService.list(search).then((r) => { setUsers(r.data); setError(null); }).catch((e) => setError(getApiErrorMessage(e))).finally(() => setLoading(false)); }, 250); return () => clearTimeout(timer); }, [search]);
  return <AdminPage title="Users" description="Manage Central IAM identities and account lifecycle." action={{ href: "/users/new", label: "Create user" }}>
    <input aria-label="Search users" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search name or email" className="mb-5 w-full max-w-sm rounded-md border border-slate-300 px-3 py-2 text-sm" />
    {error ? <Alert>{error}</Alert> : loading ? <LoadingState label="Loading users…" /> : users.length === 0 ? <EmptyState message="No users found." /> : <DataTable><thead><tr><TableHead>User</TableHead><TableHead>Status</TableHead><TableHead>Access</TableHead><TableHead>Actions</TableHead></tr></thead><tbody>{users.map((user) => <tr key={user.id}><TableCell><div className="font-medium text-slate-900">{user.name}</div><div className="text-slate-500">{user.email}</div></TableCell><TableCell><StatusBadge status={user.status} /></TableCell><TableCell>{user.is_system_admin ? "Central administrator" : "Standard user"}</TableCell><TableCell><Link className="font-medium text-slate-900 underline" href={`/users/${user.id}`}>View</Link></TableCell></tr>)}</tbody></DataTable>}
  </AdminPage>;
}
