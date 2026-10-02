"use client";

import Link from "next/link";
import { useEffect, useRef, useState } from "react";
import { AdminPage, StatusBadge, TableCell, TableHead } from "@/components/admin/AdminPage";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { DataTable } from "@/components/ui/DataTable";
import { EmptyState } from "@/components/ui/EmptyState";
import { LoadingState } from "@/components/ui/LoadingState";
import { Pagination } from "@/components/ui/Pagination";
import { getApiErrorMessage } from "@/services/api-client";
import { applicationsService } from "@/services/applications.service";
import type { Application, ApplicationStatus } from "@/types/application";
import type { PaginatedResponse } from "@/types/api";
import { applicationDate } from "./ApplicationOverview";

const emptyPage: PaginatedResponse<Application> = { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } };

export function ApplicationsList() {
  const [result, setResult] = useState(emptyPage);
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState<ApplicationStatus | "">("");
  const [page, setPage] = useState(1);
  const [retry, setRetry] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const requests = useRef(0);

  useEffect(() => {
    const sequence = ++requests.current;
    let cancelled = false;
    const timer = setTimeout(() => {
      applicationsService.list(search, page, 25, status || undefined).then((response) => {
        if (cancelled || sequence !== requests.current) return;
        setResult(response); setError(null); setLoading(false);
      }).catch((e) => {
        if (cancelled || sequence !== requests.current) return;
        setError(getApiErrorMessage(e)); setLoading(false);
      });
    }, 250);
    return () => { cancelled = true; clearTimeout(timer); };
  }, [search, status, page, retry]);

  function startRequest() { ++requests.current; setLoading(true); setError(null); }

  return <AdminPage title="Applications" description="Manage products registered with Central IAM." action={{ href: "/applications/new", label: "Create application" }}>
    <div className="mb-5 flex flex-wrap gap-3">
      <input aria-label="Search applications" placeholder="Search name or slug" maxLength={100} value={search} onChange={(e) => { startRequest(); setSearch(e.target.value); setPage(1); }} className="w-full max-w-sm rounded-md border border-slate-300 px-3 py-2 text-sm" />
      <select aria-label="Filter applications by status" value={status} onChange={(e) => { startRequest(); setStatus(e.target.value as ApplicationStatus | ""); setPage(1); }} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm"><option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option></select>
    </div>
    {loading ? <LoadingState label="Loading applications…" /> : error ? <div className="space-y-4"><Alert>{error}</Alert><Button variant="secondary" onClick={() => { startRequest(); setRetry((value) => value + 1); }}>Retry</Button></div> : !result.data.length ? <EmptyState message="No applications found." /> : <>
      <DataTable><thead><tr><TableHead>Application</TableHead><TableHead>Slug</TableHead><TableHead>Status</TableHead><TableHead>Created</TableHead><TableHead>Action</TableHead></tr></thead>
        <tbody>{result.data.map((application) => <tr key={application.id}><TableCell><div className="font-medium text-slate-900">{application.name}</div>{application.description ? <div className="mt-1 line-clamp-2 max-w-md text-sm text-slate-500">{application.description}</div> : null}</TableCell><TableCell><code>{application.slug}</code></TableCell><TableCell><StatusBadge status={application.status} /></TableCell><TableCell>{applicationDate(application.created_at)}</TableCell><TableCell><Link className="font-medium underline" href={`/applications/${application.id}`}>View details</Link></TableCell></tr>)}</tbody>
      </DataTable>
      <Pagination currentPage={result.meta.current_page} lastPage={result.meta.last_page} onPageChange={(next) => { startRequest(); setPage(next); }} />
    </>}
  </AdminPage>;
}
