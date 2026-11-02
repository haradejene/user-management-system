"use client";

import { useEffect, useState } from "react";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { getApiErrorMessage } from "@/services/api-client";
import { developersService } from "@/services/developers.service";
import type { OAuthApplicationRequestDetail } from "@/types/developers";

export function OAuthRequestStatusTracker({
  initialCode = "",
  onApplyCredentials,
}: {
  initialCode?: string;
  onApplyCredentials?: (client: {
    clientId: string;
    clientSecret?: string;
    redirectUri: string;
    appName: string;
  }) => void;
}) {
  const [code, setCode] = useState(initialCode);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [requestDetail, setRequestDetail] = useState<OAuthApplicationRequestDetail | null>(null);
  const [showSecret, setShowSecret] = useState(false);
  const [copiedKey, setCopiedKey] = useState<string | null>(null);

  useEffect(() => {
    if (initialCode && initialCode.trim()) {
      setCode(initialCode.trim());
      handleLookup(initialCode.trim());
    }
  }, [initialCode]);

  async function handleLookup(lookupCode?: string) {
    const target = (lookupCode ?? code).trim();
    if (!target) {
      setError("Please enter a valid request reference code.");
      return;
    }

    setLoading(true);
    setError(null);
    try {
      const data = await developersService.getOAuthRequestStatus(target);
      setRequestDetail(data);
    } catch (err) {
      setRequestDetail(null);
      setError(getApiErrorMessage(err));
    } finally {
      setLoading(false);
    }
  }

  function copyText(text: string, key: string) {
    navigator.clipboard.writeText(text);
    setCopiedKey(key);
    setTimeout(() => setCopiedKey(null), 2000);
  }

  return (
    <div className="space-y-6">
      {/* Search Input Box */}
      <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <h3 className="text-lg font-bold text-slate-900">
          Track OAuth Application Approval Status
        </h3>
        <p className="mt-1 text-sm text-slate-600">
          Enter the Reference Code provided when you submitted your OAuth application request.
        </p>

        <form
          onSubmit={(e) => {
            e.preventDefault();
            handleLookup();
          }}
          className="mt-4 flex flex-col sm:flex-row gap-3"
        >
          <div className="relative flex-1">
            <input
              type="text"
              value={code}
              onChange={(e) => setCode(e.target.value)}
              placeholder="e.g. REQ-A1B2C3D4"
              className="w-full rounded-md border border-slate-300 px-4 py-2.5 font-mono text-sm uppercase text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
            />
          </div>
          <Button
            type="submit"
            isLoading={loading}
            className="bg-indigo-600 hover:bg-indigo-500 shrink-0"
          >
            Check Status
          </Button>
        </form>

        {error && (
          <div className="mt-4">
            <Alert>{error}</Alert>
          </div>
        )}
      </div>

      {/* Result Card */}
      {requestDetail && (
        <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
          <div className="flex flex-wrap items-center justify-between gap-4 border-b border-slate-100 pb-4">
            <div>
              <div className="flex items-center gap-2">
                <span className="font-mono text-xs font-semibold text-slate-500">
                  {requestDetail.reference_code}
                </span>
                <span className="text-slate-300">•</span>
                <span className="text-sm font-bold text-slate-900">
                  {requestDetail.name}
                </span>
              </div>
              <p className="mt-1 text-xs text-slate-500">
                Submitted by {requestDetail.developer_name} ({requestDetail.developer_email})
                {requestDetail.organization ? ` • ${requestDetail.organization}` : ""}
              </p>
            </div>

            <div>
              {requestDetail.status === "pending" && (
                <span className="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-800 border border-amber-200">
                  <span className="h-2 w-2 rounded-full bg-amber-500 animate-pulse" />
                  Pending Review
                </span>
              )}
              {requestDetail.status === "approved" && (
                <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-800 border border-emerald-200">
                  <span className="h-2 w-2 rounded-full bg-emerald-500" />
                  Approved & Ready
                </span>
              )}
              {requestDetail.status === "rejected" && (
                <span className="inline-flex items-center gap-1.5 rounded-full bg-rose-50 px-3 py-1 text-xs font-semibold text-rose-800 border border-rose-200">
                  <span className="h-2 w-2 rounded-full bg-rose-500" />
                  Request Rejected
                </span>
              )}
            </div>
          </div>

          {/* Pending state notice */}
          {requestDetail.status === "pending" && (
            <div className="mt-6 rounded-lg bg-amber-50/60 p-4 border border-amber-200 text-sm text-amber-900">
              <h4 className="font-semibold text-amber-950 flex items-center gap-2">
                <svg className="w-5 h-5 text-amber-600" fill="currentColor" viewBox="0 0 20 20">
                  <path fillRule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clipRule="evenodd" />
                </svg>
                Under Administrator Review
              </h4>
              <p className="mt-1 text-xs text-amber-800 leading-relaxed">
                Your request has been received by Central IAM and is awaiting verification by an
                authorized system administrator. Once approved, your Client ID and Client Secret
                will be displayed here.
              </p>
            </div>
          )}

          {/* Rejected state notice */}
          {requestDetail.status === "rejected" && (
            <div className="mt-6 rounded-lg bg-rose-50/60 p-4 border border-rose-200 text-sm text-rose-900">
              <h4 className="font-semibold text-rose-950 flex items-center gap-2">
                <svg className="w-5 h-5 text-rose-600" fill="currentColor" viewBox="0 0 20 20">
                  <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clipRule="evenodd" />
                </svg>
                Request Rejected by Administrator
              </h4>
              {requestDetail.admin_notes && (
                <div className="mt-2 rounded bg-white p-3 border border-rose-200 text-xs font-medium text-slate-800">
                  {requestDetail.admin_notes}
                </div>
              )}
            </div>
          )}

          {/* Approved state credentials */}
          {requestDetail.status === "approved" && (
            <div className="mt-6 space-y-4">
              <div className="rounded-lg bg-emerald-50/50 p-4 border border-emerald-200">
                <h4 className="font-semibold text-emerald-950 flex items-center gap-2">
                  <svg className="w-5 h-5 text-emerald-600" fill="currentColor" viewBox="0 0 20 20">
                    <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
                  </svg>
                  Application Approved & Authorized
                </h4>
                <p className="mt-1 text-xs text-emerald-800">
                  Your OAuth credentials are ready for integration with the Central IAM SDKs.
                </p>
              </div>

              {/* Client ID */}
              <div>
                <label className="block text-xs font-semibold uppercase tracking-wider text-slate-500">
                  Client ID (UUID)
                </label>
                <div className="mt-1 flex items-center justify-between rounded-lg bg-slate-900 p-3 font-mono text-sm text-slate-100">
                  <span>{requestDetail.client_id || requestDetail.oauth_client_id || "—"}</span>
                  <button
                    type="button"
                    onClick={() =>
                      copyText(
                        requestDetail.client_id || requestDetail.oauth_client_id || "",
                        "clientId"
                      )
                    }
                    className="ml-3 rounded bg-slate-800 px-2.5 py-1 text-xs text-slate-300 hover:bg-slate-700 transition"
                  >
                    {copiedKey === "clientId" ? "Copied!" : "Copy"}
                  </button>
                </div>
              </div>

              {/* Client Secret */}
              {requestDetail.confidential && requestDetail.client_secret && (
                <div>
                  <div className="flex items-center justify-between">
                    <label className="block text-xs font-semibold uppercase tracking-wider text-slate-500">
                      Client Secret
                    </label>
                    <button
                      type="button"
                      onClick={() => setShowSecret(!showSecret)}
                      className="text-xs font-semibold text-indigo-600 hover:text-indigo-500"
                    >
                      {showSecret ? "Hide Secret" : "Show Secret"}
                    </button>
                  </div>
                  <div className="mt-1 flex items-center justify-between rounded-lg bg-slate-900 p-3 font-mono text-sm text-slate-100">
                    <span>
                      {showSecret
                        ? requestDetail.client_secret
                        : "••••••••••••••••••••••••••••••••••••••••"}
                    </span>
                    <button
                      type="button"
                      onClick={() => copyText(requestDetail.client_secret || "", "clientSecret")}
                      className="ml-3 rounded bg-slate-800 px-2.5 py-1 text-xs text-slate-300 hover:bg-slate-700 transition"
                    >
                      {copiedKey === "clientSecret" ? "Copied!" : "Copy"}
                    </button>
                  </div>
                  <p className="mt-1 text-[11px] text-amber-700">
                    ⚠️ Keep this client secret secure. Never commit it to public version control or client-side bundles.
                  </p>
                </div>
              )}

              {/* Redirect URIs */}
              <div>
                <label className="block text-xs font-semibold uppercase tracking-wider text-slate-500">
                  Authorized Redirect URIs
                </label>
                <ul className="mt-1 space-y-1">
                  {requestDetail.redirect_uris.map((uri, idx) => (
                    <li
                      key={idx}
                      className="flex items-center justify-between rounded-md bg-slate-50 border border-slate-200 px-3 py-2 font-mono text-xs text-slate-700"
                    >
                      <span>{uri}</span>
                      <button
                        type="button"
                        onClick={() => copyText(uri, `uri_${idx}`)}
                        className="text-xs text-indigo-600 hover:text-indigo-500 font-medium"
                      >
                        {copiedKey === `uri_${idx}` ? "Copied!" : "Copy"}
                      </button>
                    </li>
                  ))}
                </ul>
              </div>

              {/* One-click Apply to .env Generator */}
              {onApplyCredentials && (
                <div className="pt-2 border-t border-slate-100">
                  <Button
                    onClick={() =>
                      onApplyCredentials({
                        clientId: requestDetail.client_id || requestDetail.oauth_client_id || "",
                        clientSecret: requestDetail.client_secret || undefined,
                        redirectUri: requestDetail.redirect_uris[0] || "",
                        appName: requestDetail.name,
                      })
                    }
                    className="w-full bg-slate-900 hover:bg-slate-800 text-white"
                  >
                    ⚡ Configure in SDK .env Generator
                  </Button>
                </div>
              )}
            </div>
          )}
        </div>
      )}
    </div>
  );
}

