"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { AdminPage } from "@/components/admin/AdminPage";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { LoadingState } from "@/components/ui/LoadingState";
import { Modal } from "@/components/ui/Modal";
import { getApiErrorMessage } from "@/services/api-client";
import {
  oauthRequestsService,
  type OAuthRequestStats,
  type PaginatedOAuthRequests,
} from "@/services/oauth-requests.service";
import type { OAuthApplicationRequestDetail } from "@/types/developers";

export function OAuthRequestsAdmin() {
  const [requests, setRequests] = useState<OAuthApplicationRequestDetail[]>([]);
  const [stats, setStats] = useState<OAuthRequestStats | null>(null);
  const [statusFilter, setStatusFilter] = useState<string>("pending");
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);

  // Review modal state
  const [selectedRequest, setSelectedRequest] = useState<OAuthApplicationRequestDetail | null>(null);
  const [actionBusy, setActionBusy] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);
  const [adminNotes, setAdminNotes] = useState("");
  const [rejectMode, setRejectMode] = useState(false);
  const [approvedCredentials, setApprovedCredentials] = useState<{
    clientId: string;
    clientSecret?: string;
  } | null>(null);

  useEffect(() => {
    loadData();
  }, [statusFilter, search, page]);

  async function loadData() {
    setLoading(true);
    setError(null);
    try {
      const [listRes, statsRes] = await Promise.all([
        oauthRequestsService.list({
          status: statusFilter === "all" ? undefined : statusFilter,
          search: search.trim() || undefined,
          page,
          per_page: 15,
        }),
        oauthRequestsService.stats().catch(() => null),
      ]);

      setRequests(listRes.data);
      setTotalPages(listRes.last_page);
      if (statsRes) {
        setStats(statsRes);
      }
    } catch (err) {
      setError(getApiErrorMessage(err));
    } finally {
      setLoading(false);
    }
  }

  function handleOpenReview(req: OAuthApplicationRequestDetail) {
    setSelectedRequest(req);
    setActionError(null);
    setAdminNotes("");
    setRejectMode(false);
    setApprovedCredentials(null);
  }

  async function handleApprove() {
    if (!selectedRequest?.public_id && !selectedRequest?.id) return;
    const reqId = selectedRequest.public_id || selectedRequest.id!;

    setActionBusy(true);
    setActionError(null);
    try {
      const res = await oauthRequestsService.approve(reqId, adminNotes.trim() || undefined);
      setSuccess(`Application "${selectedRequest.name}" approved successfully!`);
      setSelectedRequest(res.data);
      if (res.client_secret || res.data.client_secret) {
        setApprovedCredentials({
          clientId: res.data.oauth_client_id || res.data.client_id || "",
          clientSecret: res.client_secret || res.data.client_secret || undefined,
        });
      }
      loadData();
    } catch (err) {
      setActionError(getApiErrorMessage(err));
    } finally {
      setActionBusy(false);
    }
  }

  async function handleReject() {
    if (!selectedRequest?.public_id && !selectedRequest?.id) return;
    const reqId = selectedRequest.public_id || selectedRequest.id!;

    setActionBusy(true);
    setActionError(null);
    try {
      const res = await oauthRequestsService.reject(reqId, adminNotes.trim() || undefined);
      setSuccess(`Request for "${selectedRequest.name}" rejected.`);
      setSelectedRequest(res.data);
      setRejectMode(false);
      loadData();
    } catch (err) {
      setActionError(getApiErrorMessage(err));
    } finally {
      setActionBusy(false);
    }
  }

  return (
    <AdminPage
      title="OAuth Application Requests"
      description="Review and authorize inbound OAuth 2.0 / OpenID Connect application requests submitted by developers."
    >
      {error && (
        <div className="mb-6">
          <Alert>{error}</Alert>
        </div>
      )}

      {success && (
        <div className="mb-6 rounded-md bg-emerald-50 p-4 border border-emerald-200 text-sm text-emerald-800 flex items-center justify-between">
          <span>{success}</span>
          <button
            type="button"
            onClick={() => setSuccess(null)}
            className="text-xs font-semibold text-emerald-700 hover:text-emerald-900"
          >
            ✕ Dismiss
          </button>
        </div>
      )}

      {/* Stats Cards */}
      {stats && (
        <div className="mb-8 grid grid-cols-2 gap-4 sm:grid-cols-4">
          <div
            onClick={() => {
              setStatusFilter("pending");
              setPage(1);
            }}
            className={`cursor-pointer rounded-xl border p-5 transition ${
              statusFilter === "pending"
                ? "border-amber-400 bg-amber-50/50 ring-2 ring-amber-400/30"
                : "border-slate-200 bg-white hover:border-slate-300"
            }`}
          >
            <p className="text-xs font-semibold uppercase tracking-wider text-amber-700">
              Pending Review
            </p>
            <p className="mt-2 text-2xl font-bold text-amber-900">{stats.pending}</p>
          </div>

          <div
            onClick={() => {
              setStatusFilter("approved");
              setPage(1);
            }}
            className={`cursor-pointer rounded-xl border p-5 transition ${
              statusFilter === "approved"
                ? "border-emerald-400 bg-emerald-50/50 ring-2 ring-emerald-400/30"
                : "border-slate-200 bg-white hover:border-slate-300"
            }`}
          >
            <p className="text-xs font-semibold uppercase tracking-wider text-emerald-700">
              Approved
            </p>
            <p className="mt-2 text-2xl font-bold text-emerald-900">{stats.approved}</p>
          </div>

          <div
            onClick={() => {
              setStatusFilter("rejected");
              setPage(1);
            }}
            className={`cursor-pointer rounded-xl border p-5 transition ${
              statusFilter === "rejected"
                ? "border-rose-400 bg-rose-50/50 ring-2 ring-rose-400/30"
                : "border-slate-200 bg-white hover:border-slate-300"
            }`}
          >
            <p className="text-xs font-semibold uppercase tracking-wider text-rose-700">
              Rejected
            </p>
            <p className="mt-2 text-2xl font-bold text-rose-900">{stats.rejected}</p>
          </div>

          <div
            onClick={() => {
              setStatusFilter("all");
              setPage(1);
            }}
            className={`cursor-pointer rounded-xl border p-5 transition ${
              statusFilter === "all"
                ? "border-indigo-400 bg-indigo-50/50 ring-2 ring-indigo-400/30"
                : "border-slate-200 bg-white hover:border-slate-300"
            }`}
          >
            <p className="text-xs font-semibold uppercase tracking-wider text-slate-500">
              Total Inbound
            </p>
            <p className="mt-2 text-2xl font-bold text-slate-900">{stats.total}</p>
          </div>
        </div>
      )}

      {/* Filter Tabs & Search */}
      <div className="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div className="flex border-b border-slate-200">
          {[
            { id: "pending", label: "Pending Review" },
            { id: "approved", label: "Approved" },
            { id: "rejected", label: "Rejected" },
            { id: "all", label: "All Requests" },
          ].map((tab) => (
            <button
              key={tab.id}
              onClick={() => {
                setStatusFilter(tab.id);
                setPage(1);
              }}
              className={`border-b-2 px-4 py-2.5 text-xs font-semibold transition ${
                statusFilter === tab.id
                  ? "border-indigo-600 text-indigo-600"
                  : "border-transparent text-slate-600 hover:border-slate-300 hover:text-slate-900"
              }`}
            >
              {tab.label}
            </button>
          ))}
        </div>

        <div className="w-full sm:w-72">
          <input
            type="text"
            value={search}
            onChange={(e) => {
              setSearch(e.target.value);
              setPage(1);
            }}
            placeholder="Search by name, email, code…"
            className="w-full rounded-md border border-slate-300 px-3 py-1.5 text-xs text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none"
          />
        </div>
      </div>

      {/* Requests Table */}
      {loading ? (
        <LoadingState label="Loading application requests…" />
      ) : requests.length === 0 ? (
        <div className="rounded-xl border border-slate-200 bg-white p-12 text-center shadow-sm">
          <p className="text-sm font-medium text-slate-700">No application requests found</p>
          <p className="mt-1 text-xs text-slate-500">
            {statusFilter === "pending"
              ? "All submitted developer requests have been reviewed."
              : "Try adjusting your filters or search keywords."}
          </p>
        </div>
      ) : (
        <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm text-slate-700">
              <thead className="bg-slate-50 text-xs uppercase tracking-wider text-slate-500 border-b border-slate-200">
                <tr>
                  <th className="px-4 py-3">Reference</th>
                  <th className="px-4 py-3">Application</th>
                  <th className="px-4 py-3">Developer</th>
                  <th className="px-4 py-3">Profile</th>
                  <th className="px-4 py-3">Redirect URIs</th>
                  <th className="px-4 py-3">Date</th>
                  <th className="px-4 py-3">Status</th>
                  <th className="px-4 py-3 text-right">Action</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {requests.map((req) => (
                  <tr key={req.id || req.reference_code} className="hover:bg-slate-50/50">
                    <td className="px-4 py-3 font-mono text-xs font-semibold text-indigo-600">
                      {req.reference_code}
                    </td>
                    <td className="px-4 py-3">
                      <div className="font-semibold text-slate-900">{req.name}</div>
                      {req.organization && (
                        <div className="text-xs text-slate-500">{req.organization}</div>
                      )}
                    </td>
                    <td className="px-4 py-3">
                      <div>{req.developer_name}</div>
                      <div className="text-xs text-slate-500">{req.developer_email}</div>
                    </td>
                    <td className="px-4 py-3">
                      <span className="inline-flex rounded px-2 py-0.5 text-xs font-medium bg-slate-100 text-slate-700">
                        {req.confidential ? "Confidential" : "Public"}
                      </span>
                    </td>
                    <td className="px-4 py-3 font-mono text-xs text-slate-500">
                      {req.redirect_uris.length}{" "}
                      {req.redirect_uris.length === 1 ? "URI" : "URIs"}
                    </td>
                    <td className="px-4 py-3 text-xs text-slate-500">
                      {req.created_at ? new Date(req.created_at).toLocaleDateString() : "—"}
                    </td>
                    <td className="px-4 py-3">
                      {req.status === "pending" && (
                        <span className="inline-flex rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-700 border border-amber-200">
                          Pending
                        </span>
                      )}
                      {req.status === "approved" && (
                        <span className="inline-flex rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 border border-emerald-200">
                          Approved
                        </span>
                      )}
                      {req.status === "rejected" && (
                        <span className="inline-flex rounded-full bg-rose-50 px-2.5 py-0.5 text-xs font-semibold text-rose-700 border border-rose-200">
                          Rejected
                        </span>
                      )}
                    </td>
                    <td className="px-4 py-3 text-right">
                      <button
                        type="button"
                        onClick={() => handleOpenReview(req)}
                        className="rounded-md bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-200 transition"
                      >
                        {req.status === "pending" ? "Review & Decide" : "View Details"}
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {totalPages > 1 && (
            <div className="flex items-center justify-between border-t border-slate-200 px-4 py-3 text-xs">
              <span className="text-slate-500">
                Page {page} of {totalPages}
              </span>
              <div className="flex gap-2">
                <Button
                  variant="secondary"
                  disabled={page <= 1}
                  onClick={() => setPage(page - 1)}
                >
                  Previous
                </Button>
                <Button
                  variant="secondary"
                  disabled={page >= totalPages}
                  onClick={() => setPage(page + 1)}
                >
                  Next
                </Button>
              </div>
            </div>
          )}
        </div>
      )}

      {/* Review Modal */}
      {selectedRequest && (
        <Modal
          open={Boolean(selectedRequest)}
          title={`Review Request: ${selectedRequest.name}`}
          onClose={() => setSelectedRequest(null)}
        >
          <div className="space-y-4 max-h-[75vh] overflow-y-auto pr-1">
            {actionError && <Alert>{actionError}</Alert>}

            {/* If approved just now, show credentials banner */}
            {approvedCredentials && (
              <div className="rounded-lg bg-emerald-50 p-4 border border-emerald-200 text-xs">
                <p className="font-bold text-emerald-900">
                  Application Created & Credentials Generated!
                </p>
                <div className="mt-2 space-y-1 font-mono">
                  <div>
                    <span className="text-emerald-700">Client ID:</span>{" "}
                    <strong>{approvedCredentials.clientId}</strong>
                  </div>
                  {approvedCredentials.clientSecret && (
                    <div>
                      <span className="text-emerald-700">Client Secret:</span>{" "}
                      <strong>{approvedCredentials.clientSecret}</strong>
                    </div>
                  )}
                </div>
              </div>
            )}

            {/* Request Summary */}
            <div className="rounded-lg bg-slate-50 p-3 border border-slate-200 text-xs space-y-2">
              <div className="flex justify-between">
                <span className="text-slate-500">Reference:</span>
                <span className="font-mono font-bold text-indigo-700">
                  {selectedRequest.reference_code}
                </span>
              </div>
              <div className="flex justify-between">
                <span className="text-slate-500">Developer:</span>
                <span className="font-semibold text-slate-800">
                  {selectedRequest.developer_name} ({selectedRequest.developer_email})
                </span>
              </div>
              {(selectedRequest.company || selectedRequest.organization) && (
                <div className="flex justify-between">
                  <span className="text-slate-500">Company:</span>
                  <span className="font-medium text-slate-800">
                    {selectedRequest.company?.name ?? selectedRequest.organization}
                  </span>
                </div>
              )}
              <div className="flex justify-between">
                <span className="text-slate-500">Security Profile:</span>
                <span className="font-medium text-slate-800">
                  {selectedRequest.confidential
                    ? "Confidential (Web/Server app with Client Secret)"
                    : "Public (Single-Page App / Mobile with PKCE)"}
                </span>
              </div>
              <div className="flex justify-between">
                <span className="text-slate-500">Status:</span>
                <span className="font-bold capitalize text-slate-800">
                  {selectedRequest.status}
                </span>
              </div>
            </div>

            {/* Description */}
            {selectedRequest.description && (
              <div>
                <label className="block text-xs font-semibold uppercase tracking-wider text-slate-500 mb-1">
                  Purpose / Description
                </label>
                <p className="rounded-md bg-white border border-slate-200 p-2.5 text-xs text-slate-700">
                  {selectedRequest.description}
                </p>
              </div>
            )}

            {/* Redirect URIs */}
            <div>
              <label className="block text-xs font-semibold uppercase tracking-wider text-slate-500 mb-1">
                Requested Redirect URIs
              </label>
              <ul className="space-y-1">
                {selectedRequest.redirect_uris.map((uri, idx) => (
                  <li
                    key={idx}
                    className="rounded bg-slate-100 p-2 font-mono text-xs text-slate-800 border border-slate-200 break-all"
                  >
                    {uri}
                  </li>
                ))}
              </ul>
            </div>

            {/* If approved, show linked Application info */}
            {selectedRequest.status === "approved" && selectedRequest.application && (
              <div className="rounded-lg bg-indigo-50 p-3 border border-indigo-200 text-xs">
                <span className="font-semibold text-indigo-900">
                  Linked Central IAM Application:
                </span>
                <div className="mt-1 flex items-center justify-between">
                  <span className="font-medium text-indigo-950">
                    {selectedRequest.application.name} ({selectedRequest.application.slug})
                  </span>
                  <Link
                    href={`/applications/${selectedRequest.application.id}`}
                    target="_blank"
                    className="font-bold text-indigo-700 underline hover:text-indigo-900"
                  >
                    Open Application ↗
                  </Link>
                </div>
              </div>
            )}

            {/* If rejected, show reason */}
            {selectedRequest.status === "rejected" && selectedRequest.admin_notes && (
              <div className="rounded-lg bg-rose-50 p-3 border border-rose-200 text-xs text-rose-900">
                <span className="font-semibold">Rejection Notes:</span>
                <p className="mt-1">{selectedRequest.admin_notes}</p>
              </div>
            )}

            {/* If Pending, Admin Action Controls */}
            {selectedRequest.status === "pending" && !approvedCredentials && (
              <div className="mt-4 pt-4 border-t border-slate-200 space-y-3">
                {rejectMode ? (
                  <div className="space-y-2">
                    <label className="block text-xs font-semibold uppercase tracking-wider text-rose-700">
                      Rejection Reason (Visible to Developer)
                    </label>
                    <textarea
                      rows={2}
                      value={adminNotes}
                      onChange={(e) => setAdminNotes(e.target.value)}
                      placeholder="e.g. Please use official production domain for redirect URIs."
                      className="w-full rounded-md border border-slate-300 p-2 text-xs text-slate-900 shadow-sm focus:border-rose-500 focus:outline-none"
                    />
                    <div className="flex justify-end gap-2">
                      <Button
                        variant="secondary"
                        onClick={() => setRejectMode(false)}
                      >
                        Cancel
                      </Button>
                      <Button
                        variant="danger"
                        isLoading={actionBusy}
                        onClick={handleReject}
                      >
                        Confirm Rejection
                      </Button>
                    </div>
                  </div>
                ) : (
                  <div>
                    <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1">
                      Administrator Approval Notes <span className="font-normal text-slate-400">(Optional)</span>
                    </label>
                    <input
                      type="text"
                      value={adminNotes}
                      onChange={(e) => setAdminNotes(e.target.value)}
                      placeholder="e.g. Approved for internal integration."
                      className="w-full rounded-md border border-slate-300 p-2 text-xs text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none mb-3"
                    />
                    <div className="flex justify-between items-center">
                      <Button
                        variant="danger"
                        onClick={() => setRejectMode(true)}
                      >
                        Reject Request…
                      </Button>
                      <Button
                        isLoading={actionBusy}
                        onClick={handleApprove}
                        className="bg-emerald-600 hover:bg-emerald-500 text-white"
                      >
                        ✓ Approve Application & Issue Client
                      </Button>
                    </div>
                  </div>
                )}
              </div>
            )}

            <div className="pt-2 flex justify-end">
              <Button variant="secondary" onClick={() => setSelectedRequest(null)}>
                Close
              </Button>
            </div>
          </div>
        </Modal>
      )}
    </AdminPage>
  );
}

