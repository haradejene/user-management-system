"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { AdminPage, StatusBadge, TableCell, TableHead } from "@/components/admin/AdminPage";
import { ConfirmDialog } from "@/components/admin/ConfirmDialog";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { DataTable } from "@/components/ui/DataTable";
import { EmptyState } from "@/components/ui/EmptyState";
import { FormField } from "@/components/forms/FormField";
import { LoadingState } from "@/components/ui/LoadingState";
import { getApiErrorMessage } from "@/services/api-client";
import { companiesService } from "@/services/companies.service";
import { applicationsService } from "@/services/applications.service";
import { usersService } from "@/services/users.service";
import type { Company, CompanyMember } from "@/types/company";
import type { Application } from "@/types/application";
import type { User } from "@/types/user";

export function CompaniesList() { const [items, setItems] = useState<Company[]>([]); const [loading, setLoading] = useState(true); const [error, setError] = useState<string | null>(null); useEffect(() => { companiesService.list().then((r) => setItems(r.data)).catch((e) => setError(getApiErrorMessage(e))).finally(() => setLoading(false)); }, []); return <AdminPage title="Companies" description="Manage organizations and their user memberships." action={{ href: "/companies/new", label: "Create company" }}>{error ? <Alert>{error}</Alert> : loading ? <LoadingState /> : !items.length ? <EmptyState message="No companies found." /> : <DataTable><thead><tr><TableHead>Name</TableHead><TableHead>Status</TableHead><TableHead>Actions</TableHead></tr></thead><tbody>{items.map((c) => <tr key={c.id}><TableCell>{c.name}</TableCell><TableCell><StatusBadge status={c.status} /></TableCell><TableCell><Link className="underline" href={`/companies/${c.id}`}>Manage</Link></TableCell></tr>)}</tbody></DataTable>}</AdminPage>; }

export function CompanyForm({ id }: { id?: string }) { const router = useRouter(); const [name, setName] = useState(""); const [loading, setLoading] = useState(Boolean(id)); const [saving, setSaving] = useState(false); const [error, setError] = useState<string | null>(null); useEffect(() => { if (id) companiesService.get(id).then((c) => setName(c.name)).catch((e) => setError(getApiErrorMessage(e))).finally(() => setLoading(false)); }, [id]); async function submit(e: React.FormEvent) { e.preventDefault(); setSaving(true); try { const c = id ? await companiesService.update(id, name) : await companiesService.create(name); router.push(`/companies/${c.id}`); } catch (err) { setError(getApiErrorMessage(err)); } finally { setSaving(false); } } return <AdminPage title={id ? "Edit company" : "Create company"}>{loading ? <LoadingState /> : <form onSubmit={submit} className="max-w-xl space-y-5 rounded-lg border bg-white p-6">{error ? <Alert>{error}</Alert> : null}<FormField name="name" label="Company name" value={name} onChange={(e) => setName(e.target.value)} required /><Button isLoading={saving}>Save company</Button></form>}</AdminPage>; }

export function CompanyDetails({ id }: { id: string }) {
  const [company, setCompany] = useState<Company | null>(null);
  const [members, setMembers] = useState<CompanyMember[]>([]);
  const [applications, setApplications] = useState<Application[]>([]);
  const [users, setUsers] = useState<User[]>([]);
  const [selected, setSelected] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [remove, setRemove] = useState<CompanyMember | null>(null);
  const [busy, setBusy] = useState(false);

  async function load() {
    const [c, m, u, appsRes] = await Promise.all([
      companiesService.get(id),
      companiesService.members(id),
      usersService.list(),
      applicationsService.list("", 1, 100),
    ]);
    setCompany(c);
    setMembers(m);
    setUsers(u.data);
    setApplications(appsRes.data.filter((a) => a.company_id === id || a.company?.id === id));
  }

  useEffect(() => {
    load().catch((e) => setError(getApiErrorMessage(e)));
  }, [id]);

  async function add() {
    if (!selected) return;
    setBusy(true);
    try {
      await companiesService.addMember(id, selected);
      setSelected("");
      await load();
    } catch (e) {
      setError(getApiErrorMessage(e));
    } finally {
      setBusy(false);
    }
  }

  async function removeMember() {
    if (!remove) return;
    setBusy(true);
    try {
      await companiesService.removeMember(id, remove.id);
      setRemove(null);
      await load();
    } catch (e) {
      setError(getApiErrorMessage(e));
    } finally {
      setBusy(false);
    }
  }

  async function toggleStatus() {
    if (!company) return;
    setBusy(true);
    try {
      setCompany(await companiesService.changeStatus(id, company.status === "active" ? "deactivate" : "reactivate"));
    } catch (e) {
      setError(getApiErrorMessage(e));
    } finally {
      setBusy(false);
    }
  }

  const available = users.filter((u) => !members.some((m) => m.id === u.id));

  return (
    <AdminPage title={company?.name ?? "Company"} action={{ href: `/companies/${id}/edit`, label: "Edit company" }}>
      {error ? <Alert>{error}</Alert> : !company ? <LoadingState /> : (
        <div className="space-y-6">
          <section className="flex items-center justify-between rounded-lg border bg-white p-5">
            <StatusBadge status={company.status} />
            <Button
              variant={company.status === "active" ? "danger" : "primary"}
              isLoading={busy}
              onClick={toggleStatus}
            >
              {company.status === "active" ? "Deactivate" : "Reactivate"}
            </Button>
          </section>

          {/* Assigned Applications Section */}
          <section className="rounded-lg border border-slate-200 bg-white p-6">
            <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
              <div>
                <h2 className="text-lg font-semibold">Assigned Applications</h2>
                <p className="text-xs text-slate-500">
                  Applications owned by this company. Company members receive access by default.
                </p>
              </div>
              <Link
                href="/applications/new"
                className="inline-flex rounded-md bg-slate-900 px-3 py-1.5 text-xs font-medium text-white hover:bg-slate-800"
              >
                Create new application
              </Link>
            </div>
            {!applications.length ? (
              <EmptyState message="No applications currently assigned to this company." />
            ) : (
              <DataTable>
                <thead>
                  <tr>
                    <TableHead>Application</TableHead>
                    <TableHead>Slug</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead>Actions</TableHead>
                  </tr>
                </thead>
                <tbody>
                  {applications.map((app) => (
                    <tr key={app.id}>
                      <TableCell>
                        <Link href={`/applications/${app.id}`} className="font-medium text-slate-900 underline">
                          {app.name}
                        </Link>
                        {app.description ? (
                          <div className="line-clamp-1 text-xs text-slate-500">{app.description}</div>
                        ) : null}
                      </TableCell>
                      <TableCell>
                        <code>{app.slug}</code>
                      </TableCell>
                      <TableCell>
                        <StatusBadge status={app.status} />
                      </TableCell>
                      <TableCell>
                        <div className="flex gap-2">
                          <Link
                            href={`/applications/${app.id}/user-access`}
                            className="inline-flex rounded-md border border-slate-300 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50"
                          >
                            Manage / Revoke Users
                          </Link>
                          <Link
                            href={`/applications/${app.id}`}
                            className="inline-flex rounded-md bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-200"
                          >
                            View
                          </Link>
                        </div>
                      </TableCell>
                    </tr>
                  ))}
                </tbody>
              </DataTable>
            )}
          </section>

          {/* Members Section */}
          <section className="rounded-lg border border-slate-200 bg-white p-6">
            <h2 className="mb-3 text-lg font-semibold">Members</h2>
            <div className="mb-4 flex gap-2">
              <select
                aria-label="User to add"
                value={selected}
                onChange={(e) => setSelected(e.target.value)}
                className="min-h-10 flex-1 rounded-md border border-slate-300 bg-white px-3"
              >
                <option value="">Select a user</option>
                {available.map((u) => (
                  <option key={u.id} value={u.id}>
                    {u.name} — {u.email}
                  </option>
                ))}
              </select>
              <Button disabled={!selected || company.status !== "active"} isLoading={busy} onClick={add}>
                Add member
              </Button>
            </div>
            {!members.length ? (
              <EmptyState message="No company members." />
            ) : (
              <DataTable>
                <thead>
                  <tr>
                    <TableHead>User</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead>Action</TableHead>
                  </tr>
                </thead>
                <tbody>
                  {members.map((m) => (
                    <tr key={m.id}>
                      <TableCell>
                        <Link href={`/users/${m.id}`} className="font-medium underline">
                          {m.name}
                        </Link>
                        <div className="text-slate-500">{m.email}</div>
                      </TableCell>
                      <TableCell>
                        <StatusBadge status={m.membership_status} />
                      </TableCell>
                      <TableCell>
                        <div className="flex gap-2">
                          <Link
                            href={`/users/${m.id}`}
                            className="inline-flex rounded-md border border-slate-200 px-2.5 py-1 text-xs text-slate-700 hover:bg-slate-50"
                          >
                            Application Access
                          </Link>
                          <Button variant="secondary" onClick={() => setRemove(m)}>
                            Remove from Company
                          </Button>
                        </div>
                      </TableCell>
                    </tr>
                  ))}
                </tbody>
              </DataTable>
            )}
          </section>
        </div>
      )}
      <ConfirmDialog
        open={Boolean(remove)}
        title="Remove company member"
        message={`Remove ${remove?.name ?? "this user"} from the company? This will also remove default access to this company's applications.`}
        confirmLabel="Remove"
        busy={busy}
        onCancel={() => setRemove(null)}
        onConfirm={removeMember}
      />
    </AdminPage>
  );
}

