import { StatusBadge } from "@/components/admin/AdminPage";
import type { Application } from "@/types/application";

export function applicationDate(value: string | null): string {
  return value ? new Intl.DateTimeFormat("en", { dateStyle: "medium", timeZone: "UTC" }).format(new Date(value)) : "—";
}

export function ApplicationOverview({ application }: { application: Application }) {
  return <section aria-labelledby="application-overview" className="rounded-xl border border-slate-200 bg-white p-6">
    <h2 id="application-overview" className="mb-6 text-xl font-semibold">Overview</h2>
    <dl className="grid gap-6 sm:grid-cols-2">
      {[
        ["Application name", application.name], ["Slug", application.slug],
        ["Description", application.description || "No description."],
        ["Status", <StatusBadge key="status" status={application.status} />],
        ["Created", applicationDate(application.created_at)], ["Updated", applicationDate(application.updated_at)],
        ["Public application ID", application.id],
      ].map(([label, value]) => <div key={String(label)}><dt className="text-sm text-slate-500">{label}</dt><dd className="mt-1 break-words text-sm text-slate-900">{value}</dd></div>)}
    </dl>
  </section>;
}
