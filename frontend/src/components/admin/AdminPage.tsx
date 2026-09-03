import Link from "next/link";
import type { ReactNode } from "react";

export function AdminPage({ title, description, action, children }: { title: string; description?: string; action?: { href: string; label: string }; children: ReactNode }) {
  return <main className="mx-auto max-w-6xl px-6 py-10"><div className="mb-8 flex flex-wrap items-start justify-between gap-4"><div><h1 className="text-3xl font-semibold text-slate-900">{title}</h1>{description ? <p className="mt-2 text-sm text-slate-600">{description}</p> : null}</div>{action ? <Link href={action.href} className="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">{action.label}</Link> : null}</div>{children}</main>;
}

export function TableHead({ children }: { children: ReactNode }) { return <th className="border-b border-slate-200 bg-slate-50 px-4 py-3 font-medium text-slate-600">{children}</th>; }
export function TableCell({ children }: { children: ReactNode }) { return <td className="border-b border-slate-100 px-4 py-3 text-slate-700">{children}</td>; }
export function StatusBadge({ status }: { status: string }) { const active = status === "active"; return <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-medium ${active ? "bg-emerald-50 text-emerald-700" : status === "suspended" ? "bg-amber-50 text-amber-700" : "bg-slate-100 text-slate-600"}`}>{status}</span>; }
