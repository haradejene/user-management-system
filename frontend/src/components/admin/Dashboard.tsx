"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { AdminPage } from "@/components/admin/AdminPage";
import { Alert } from "@/components/ui/Alert";
import { LoadingState } from "@/components/ui/LoadingState";
import { useAuth } from "@/hooks/useAuth";
import { getApiErrorMessage } from "@/services/api-client";
import { applicationsService } from "@/services/applications.service";
import { companiesService } from "@/services/companies.service";
import { usersService } from "@/services/users.service";

export function Dashboard() { const { user } = useAuth(); const [counts, setCounts] = useState<number[] | null>(null); const [error, setError] = useState<string | null>(null); useEffect(() => { Promise.all([usersService.list(), companiesService.list(), applicationsService.list()]).then((items) => setCounts(items.map((item) => item.meta.total))).catch((e) => setError(getApiErrorMessage(e))); }, []); return <AdminPage title={`Welcome, ${user?.name ?? "administrator"}`} description="Central IAM administration overview.">{error ? <Alert>{error}</Alert> : !counts ? <LoadingState label="Loading dashboard…" /> : <div className="grid gap-4 sm:grid-cols-3">{[["Users", counts[0], "/users"], ["Companies", counts[1], "/companies"], ["Applications", counts[2], "/applications"]].map(([label, count, href]) => <Link key={String(label)} href={String(href)} className="rounded-xl border border-slate-200 bg-white p-6 transition hover:border-slate-400"><p className="text-sm text-slate-500">{label}</p><p className="mt-2 text-3xl font-semibold">{count}</p></Link>)}</div>}<section className="mt-8 rounded-xl bg-slate-900 p-6 text-white"><h2 className="text-lg font-semibold">Access stays intentionally simple</h2><p className="mt-2 max-w-2xl text-sm text-slate-300">Central IAM grants entry to applications. HRM, CRM, ERP, and other downstream services own their internal roles and permissions.</p><Link href="/application-access" className="mt-4 inline-flex rounded-md bg-white px-4 py-2 text-sm font-medium text-slate-900">Manage application access</Link></section></AdminPage>; }
