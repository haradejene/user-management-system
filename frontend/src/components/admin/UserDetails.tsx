"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { AdminPage, StatusBadge, TableCell, TableHead } from "@/components/admin/AdminPage";
import { ConfirmDialog } from "@/components/admin/ConfirmDialog";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { DataTable } from "@/components/ui/DataTable";
import { EmptyState } from "@/components/ui/EmptyState";
import { LoadingState } from "@/components/ui/LoadingState";
import { getApiErrorMessage } from "@/services/api-client";
import { applicationsService } from "@/services/applications.service";
import { usersService } from "@/services/users.service";
import type { ApplicationAccess } from "@/types/application";
import type { Company } from "@/types/company";
import type { User } from "@/types/user";

export function UserDetails({ id }: { id: string }) {
  const [user, setUser] = useState<User | null>(null);
  const [companies, setCompanies] = useState<Company[]>([]);
  const [applications, setApplications] = useState<ApplicationAccess[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [action, setAction] = useState<"deactivate" | "suspend" | "reactivate" | null>(null);
  const [revokeApp, setRevokeApp] = useState<ApplicationAccess | null>(null);
  const [busy, setBusy] = useState(false);
  const [revokeBusy, setRevokeBusy] = useState(false);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);

  const loadData = useCallback(() => {
    Promise.all([
      usersService.get(id),
      usersService.companies(id),
      applicationsService.forUser(id, 1, 100),
    ])
      .then(([u, c, appRes]) => {
        setUser(u);
        setCompanies(c);
        setApplications(appRes.data);
      })
      .catch((e) => setError(getApiErrorMessage(e)));
  }, [id]);

  useEffect(() => {
    loadData();
  }, [loadData]);

  async function confirmStatusChange() {
    if (!action) return;
    setBusy(true);
    try {
      setUser(await usersService.changeStatus(id, action));
      setAction(null);
      setSuccessMessage(`Account status updated.`);
      loadData();
    } catch (e) {
      setError(getApiErrorMessage(e));
    } finally {
      setBusy(false);
    }
  }

  async function confirmRevoke() {
    if (!revokeApp) return;
    setRevokeBusy(true);
    setError(null);
    try {
      await applicationsService.revoke(id, revokeApp.id);
      setSuccessMessage(`Revoked access to ${revokeApp.name}.`);
      setRevokeApp(null);
      loadData();
    } catch (e) {
      setError(getApiErrorMessage(e));
    } finally {
      setRevokeBusy(false);
    }
  }

  return (
    <AdminPage title={user?.name ?? "User details"} action={{ href: `/users/${id}/edit`, label: "Edit user" }}>
      {error ? <Alert>{error}</Alert> : null}
      {successMessage ? (
        <p role="status" className="rounded-md bg-emerald-50 p-3 text-sm text-emerald-800">
          {successMessage}
        </p>
      ) : null}

      {!user ? (
        <LoadingState />
      ) : (
        <div className="space-y-6">
          <section className="rounded-lg border border-slate-200 bg-white p-6">
            <div className="flex flex-wrap items-center justify-between gap-4">
              <div>
                <p className="text-sm text-slate-500">{user.email}</p>
                <div className="mt-2">
                  <StatusBadge status={user.status} />
                </div>
              </div>
              <div className="flex gap-2">
                {user.status !== "active" ? (
                  <Button onClick={() => setAction("reactivate")}>Reactivate</Button>
                ) : (
                  <>
                    <Button variant="secondary" onClick={() => setAction("deactivate")}>
                      Deactivate
                    </Button>
                    <Button variant="danger" onClick={() => setAction("suspend")}>
                      Suspend
                    </Button>
                  </>
                )}
              </div>
            </div>
            <dl className="mt-6 grid gap-4 sm:grid-cols-2">
              <div>
                <dt className="text-xs uppercase text-slate-500">Identity ID</dt>
                <dd className="mt-1 break-all text-sm">{user.id}</dd>
              </div>
              <div>
                <dt className="text-xs uppercase text-slate-500">IAM level</dt>
                <dd className="mt-1 text-sm">{user.is_system_admin ? "Central administrator" : "Standard user"}</dd>
              </div>
            </dl>
          </section>

          {/* Companies Section */}
          <section className="rounded-lg border border-slate-200 bg-white p-6">
            <h2 className="mb-3 text-lg font-semibold">Companies</h2>
            {companies.length ? (
              <ul className="grid gap-2 sm:grid-cols-2">
                {companies.map((c) => (
                  <li key={c.id} className="rounded-md border bg-slate-50 p-3">
                    <Link href={`/companies/${c.id}`} className="font-medium text-slate-900 underline">
                      {c.name}
                    </Link>
                  </li>
                ))}
              </ul>
            ) : (
              <p className="text-sm text-slate-500">No company memberships.</p>
            )}
          </section>

          {/* Application Access Section */}
          <section className="rounded-lg border border-slate-200 bg-white p-6">
            <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
              <div>
                <h2 className="text-lg font-semibold">Application Access</h2>
                <p className="text-xs text-slate-500">Applications this user has permission to authenticate into.</p>
              </div>
              <Link
                href={`/application-access?user=${id}`}
                className="inline-flex rounded-md bg-slate-900 px-3 py-1.5 text-xs font-medium text-white hover:bg-slate-800"
              >
                Manage all application grants
              </Link>
            </div>

            {!applications.length ? (
              <EmptyState message="No application assignments found for this user." />
            ) : (
              <DataTable>
                <thead>
                  <tr>
                    <TableHead>Application</TableHead>
                    <TableHead>Slug</TableHead>
                    <TableHead>Assignment</TableHead>
                    <TableHead>Effective Access</TableHead>
                    <TableHead>Action</TableHead>
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
                        <StatusBadge status={app.access_status} />
                      </TableCell>
                      <TableCell>
                        {app.effective_access ? (
                          <span className="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700">
                            Granted
                          </span>
                        ) : (
                          <span className="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-medium text-amber-700">
                            Ineffective ({app.ineffective_reason?.replaceAll("_", " ") ?? "blocked"})
                          </span>
                        )}
                      </TableCell>
                      <TableCell>
                        <Button
                          variant="danger"
                          disabled={revokeBusy}
                          onClick={() => setRevokeApp(app)}
                        >
                          Revoke access
                        </Button>
                      </TableCell>
                    </tr>
                  ))}
                </tbody>
              </DataTable>
            )}
          </section>

          <div className="flex flex-wrap gap-3">
            <Link
              href={`/users/${id}/profile`}
              className="inline-flex rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700"
            >
              Edit profile
            </Link>
            <Link
              href={`/application-access?user=${id}`}
              className="inline-flex rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white"
            >
              Open Application Access Matrix
            </Link>
          </div>
        </div>
      )}

      {/* Account Status Confirm Dialog */}
      <ConfirmDialog
        open={Boolean(action)}
        title={`${action ?? "Change"} account`}
        message="This changes whether the user can authenticate. Continue?"
        confirmLabel={action ?? "Confirm"}
        busy={busy}
        onCancel={() => setAction(null)}
        onConfirm={confirmStatusChange}
      />

      {/* Revoke Application Access Confirm Dialog */}
      <ConfirmDialog
        open={Boolean(revokeApp)}
        title="Revoke application access"
        message={`Revoke ${user?.name ?? "this user"}'s access to ${revokeApp?.name ?? "this application"}? The user will no longer be able to log in or use this application.`}
        confirmLabel="Revoke access"
        busy={revokeBusy}
        onCancel={() => setRevokeApp(null)}
        onConfirm={confirmRevoke}
      />
    </AdminPage>
  );
}

