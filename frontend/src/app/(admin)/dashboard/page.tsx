"use client";

import { Alert } from "@/components/ui/Alert";
import { useAuth } from "@/hooks/useAuth";

export default function DashboardPage() {
  const { user, error } = useAuth();
  if (!user) return null;

  return (
    <main className="mx-auto max-w-6xl px-6 py-10">
      {error ? (
        <div className="mb-6">
          <Alert>{error}</Alert>
        </div>
      ) : null}
      <div className="mb-8">
        <p className="text-sm font-medium text-slate-500">
          Authenticated account
        </p>
        <h1 className="mt-1 text-3xl font-semibold text-slate-900">
          Welcome, {user.name}
        </h1>
      </div>
      <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <AccountDetail label="Email" value={user.email} />
        <AccountDetail label="Account status" value={user.status} />
        <AccountDetail
          label="IAM access"
          value={
            user.is_system_admin ? "System administrator" : "Standard user"
          }
        />
        <AccountDetail label="Stable identity ID" value={user.id} wide />
        <AccountDetail
          label="Email verification"
          value={user.email_verified_at ? "Verified" : "Not verified"}
        />
      </dl>
    </main>
  );
}

function AccountDetail({
  label,
  value,
  wide = false,
}: {
  label: string;
  value: string;
  wide?: boolean;
}) {
  return (
    <div
      className={`rounded-lg border border-slate-200 bg-white p-5 ${wide ? "sm:col-span-2" : ""}`}
    >
      <dt className="text-sm font-medium text-slate-500">{label}</dt>
      <dd className="mt-2 break-all text-sm text-slate-900">{value}</dd>
    </div>
  );
}
