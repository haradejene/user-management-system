import Link from "next/link";

export type ApplicationTab = "overview" | "settings";

export function ApplicationTabs({ id, selected }: { id: string; selected: ApplicationTab }) {
  return <nav aria-label="Application sections" className="mb-6 flex flex-wrap gap-1 border-b border-slate-200">
    <Link href={`/applications/${id}`} aria-current={selected === "overview" ? "page" : undefined} className={`px-4 py-3 text-sm ${selected === "overview" ? "border-b-2 border-slate-900 font-semibold" : "text-slate-600"}`}>Overview</Link>
    <span aria-disabled="true" className="px-4 py-3 text-sm text-slate-400">OAuth Clients</span>
    <span aria-disabled="true" className="px-4 py-3 text-sm text-slate-400">User Access</span>
    <Link href={`/applications/${id}/edit`} aria-current={selected === "settings" ? "page" : undefined} className={`px-4 py-3 text-sm ${selected === "settings" ? "border-b-2 border-slate-900 font-semibold" : "text-slate-600"}`}>Settings</Link>
  </nav>;
}
