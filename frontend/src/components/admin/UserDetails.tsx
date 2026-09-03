"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { AdminPage, StatusBadge } from "@/components/admin/AdminPage";
import { ConfirmDialog } from "@/components/admin/ConfirmDialog";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { LoadingState } from "@/components/ui/LoadingState";
import { getApiErrorMessage } from "@/services/api-client";
import { usersService } from "@/services/users.service";
import type { Company } from "@/types/company";
import type { User } from "@/types/user";

export function UserDetails({ id }: { id: string }) {
  const [user, setUser] = useState<User | null>(null); const [companies, setCompanies] = useState<Company[]>([]); const [error, setError] = useState<string | null>(null); const [action, setAction] = useState<"deactivate" | "suspend" | "reactivate" | null>(null); const [busy, setBusy] = useState(false);
  useEffect(() => { Promise.all([usersService.get(id), usersService.companies(id)]).then(([u, c]) => { setUser(u); setCompanies(c); }).catch((e) => setError(getApiErrorMessage(e))); }, [id]);
  async function confirm() { if (!action) return; setBusy(true); try { setUser(await usersService.changeStatus(id, action)); setAction(null); } catch (e) { setError(getApiErrorMessage(e)); } finally { setBusy(false); } }
  return <AdminPage title={user?.name ?? "User details"} action={{ href: `/users/${id}/edit`, label: "Edit user" }}>{error ? <Alert>{error}</Alert> : !user ? <LoadingState /> : <div className="space-y-6"><section className="rounded-lg border border-slate-200 bg-white p-6"><div className="flex flex-wrap items-center justify-between gap-4"><div><p className="text-sm text-slate-500">{user.email}</p><div className="mt-2"><StatusBadge status={user.status} /></div></div><div className="flex gap-2">{user.status !== "active" ? <Button onClick={() => setAction("reactivate")}>Reactivate</Button> : <><Button variant="secondary" onClick={() => setAction("deactivate")}>Deactivate</Button><Button variant="danger" onClick={() => setAction("suspend")}>Suspend</Button></>}</div></div><dl className="mt-6 grid gap-4 sm:grid-cols-2"><div><dt className="text-xs uppercase text-slate-500">Identity ID</dt><dd className="mt-1 break-all text-sm">{user.id}</dd></div><div><dt className="text-xs uppercase text-slate-500">IAM level</dt><dd className="mt-1 text-sm">{user.is_system_admin ? "Central administrator" : "Standard user"}</dd></div></dl></section><section><h2 className="mb-3 text-lg font-semibold">Companies</h2>{companies.length ? <ul className="grid gap-2 sm:grid-cols-2">{companies.map((c) => <li key={c.id} className="rounded-md border bg-white p-3"><Link href={`/companies/${c.id}`} className="font-medium">{c.name}</Link></li>)}</ul> : <p className="text-sm text-slate-500">No company memberships.</p>}</section><Link href={`/application-access?user=${id}`} className="inline-flex rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white">Manage application access</Link></div>}<ConfirmDialog open={Boolean(action)} title={`${action ?? "Change"} account`} message="This changes whether the user can authenticate. Continue?" confirmLabel={action ?? "Confirm"} busy={busy} onCancel={() => setAction(null)} onConfirm={confirm} /></AdminPage>;
}
