"use client";

import { useEffect, useState } from "react";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { getApiErrorMessage } from "@/services/api-client";
import { developersService } from "@/services/developers.service";
import type { DeveloperCompanyOption, OAuthApplicationRequestDetail } from "@/types/developers";

export function OAuthApplicationRequestForm({
  onSubmitted,
}: {
  onSubmitted?: (request: OAuthApplicationRequestDetail) => void;
}) {
  const [name, setName] = useState("");
  const [developerName, setDeveloperName] = useState("");
  const [developerEmail, setDeveloperEmail] = useState("");
  const [companySelection, setCompanySelection] = useState("");
  const [customOrganization, setCustomOrganization] = useState("");
  const [companies, setCompanies] = useState<DeveloperCompanyOption[]>([]);
  const [companiesLoading, setCompaniesLoading] = useState(false);
  const [description, setDescription] = useState("");
  const [confidential, setConfidential] = useState(true);
  const [redirectUris, setRedirectUris] = useState<string[]>([""]);

  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [successRequest, setSuccessRequest] = useState<OAuthApplicationRequestDetail | null>(null);
  const [copied, setCopied] = useState(false);

  useEffect(() => {
    let mounted = true;
    setCompaniesLoading(true);
    developersService
      .getCompanies()
      .then((items) => {
        if (mounted) setCompanies(items);
      })
      .catch(() => { })
      .finally(() => {
        if (mounted) setCompaniesLoading(false);
      });

    return () => {
      mounted = false;
    };
  }, []);

  function handleAddUri() {
    if (redirectUris.length < 10) {
      setRedirectUris([...redirectUris, ""]);
    }
  }

  function handleRemoveUri(index: number) {
    if (redirectUris.length > 1) {
      setRedirectUris(redirectUris.filter((_, i) => i !== index));
    }
  }

  function handleUriChange(index: number, value: string) {
    const updated = [...redirectUris];
    updated[index] = value;
    setRedirectUris(updated);
  }

  function copyReference(code: string) {
    navigator.clipboard.writeText(code);
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);

    // Validation
    if (!name.trim()) {
      setError("Application name is required.");
      return;
    }
    if (!developerName.trim()) {
      setError("Developer name is required.");
      return;
    }
    if (!developerEmail.trim() || !/^\S+@\S+\.\S+$/.test(developerEmail.trim())) {
      setError("A valid developer contact email is required.");
      return;
    }

    const cleanUris = redirectUris.map((u) => u.trim()).filter(Boolean);
    if (cleanUris.length === 0) {
      setError("At least one valid redirect URI is required.");
      return;
    }

    for (const uri of cleanUris) {
      try {
        const parsed = new URL(uri);
        if (parsed.protocol !== "http:" && parsed.protocol !== "https:") {
          setError(`Invalid redirect URI protocol: "${uri}". Must use http or https.`);
          return;
        }
        if (uri.includes("*")) {
          setError(`Redirect URIs cannot contain wildcards: "${uri}".`);
          return;
        }
        if (uri.includes("#")) {
          setError(`Redirect URIs cannot contain fragment identifiers (#): "${uri}".`);
          return;
        }
      } catch {
        setError(`Invalid redirect URI format: "${uri}".`);
        return;
      }
    }

    let companyId: string | undefined = undefined;
    let organizationName: string | undefined = undefined;

    if (companySelection === "other") {
      organizationName = customOrganization.trim() || undefined;
    } else if (companySelection) {
      const selectedCompany = companies.find((c) => c.id === companySelection);
      companyId = companySelection;
      organizationName = selectedCompany ? selectedCompany.name : undefined;
    }

    setLoading(true);

    try {
      const response = await developersService.submitOAuthRequest({
        name: name.trim(),
        developer_name: developerName.trim(),
        developer_email: developerEmail.trim(),
        company_id: companyId,
        organization: organizationName,
        description: description.trim() || undefined,
        redirect_uris: cleanUris,
        confidential,
      });

      setSuccessRequest(response.data);
    } catch (err) {
      setError(getApiErrorMessage(err));
    } finally {
      setLoading(false);
    }
  }

  function handleReset() {
    setName("");
    setDeveloperName("");
    setDeveloperEmail("");
    setCompanySelection("");
    setCustomOrganization("");
    setDescription("");
    setConfidential(true);
    setRedirectUris([""]);
    setError(null);
    setSuccessRequest(null);
  }

  if (successRequest) {
    return (
      <div className="rounded-xl border border-emerald-200 bg-emerald-50/40 p-6 shadow-sm">
        <div className="flex items-start gap-4">
          <div className="rounded-full bg-emerald-100 p-2 text-emerald-600">
            <svg className="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
            </svg>
          </div>
          <div className="flex-1">
            <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-800">
              Request Submitted
            </span>
            <h3 className="mt-2 text-xl font-bold text-slate-900">
              Application Request Submitted Successfully!
            </h3>
            <p className="mt-1 text-sm text-slate-600">
              Your OAuth 2.0 application request for{" "}
              <strong className="text-slate-900">{successRequest.name}</strong> has been
              submitted to the Central IAM administration team for review.
            </p>

            <div className="mt-5 rounded-lg border border-emerald-200 bg-white p-4">
              <label className="text-xs font-semibold uppercase tracking-wider text-slate-500">
                Your Request Reference Code
              </label>
              <div className="mt-1 flex items-center justify-between gap-3">
                <span className="font-mono text-xl font-bold text-indigo-600">
                  {successRequest.reference_code}
                </span>
                <button
                  type="button"
                  onClick={() => copyReference(successRequest.reference_code)}
                  className="rounded-md bg-slate-100 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-200 transition"
                >
                  {copied ? "Copied!" : "Copy Reference Code"}
                </button>
              </div>
              <p className="mt-2 text-xs text-slate-500">
                Save this code. You can use it in the <strong>Track Request Status</strong> tab anytime to verify
                approval and retrieve your generated client credentials.
              </p>
            </div>

            <div className="mt-6 flex flex-wrap items-center gap-3">
              {onSubmitted && (
                <Button
                  onClick={() => onSubmitted(successRequest)}
                  className="bg-indigo-600 hover:bg-indigo-500"
                >
                  Track Status Now →
                </Button>
              )}
              <Button variant="secondary" onClick={handleReset}>
                Submit Another Request
              </Button>
            </div>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
      <div className="border-b border-slate-100 pb-4">
        <h3 className="text-lg font-bold text-slate-900">
          Request Central IAM OAuth Application
        </h3>
        <p className="mt-1 text-sm text-slate-600">
          Register your downstream client application to obtain OpenID Connect / OAuth 2.0
          credentials. Requests are reviewed and authorized by Central IAM system administrators.
        </p>
      </div>

      {error && (
        <div className="mt-4">
          <Alert>{error}</Alert>
        </div>
      )}

      <form onSubmit={handleSubmit} className="mt-6 space-y-6">
        <div className="grid grid-cols-1 gap-6 sm:grid-cols-2">
          <div>
            <label className="block text-xs font-semibold uppercase tracking-wider text-slate-700">
              Application Name <span className="text-rose-500">*</span>
            </label>
            <input
              type="text"
              required
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="e.g. Acme Employee Portal"
              className="mt-1.5 w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
            />
            <p className="mt-1 text-xs text-slate-500">
              The human-readable name of your downstream client application.
            </p>
          </div>

          <div>
            <label htmlFor="company-select" className="block text-xs font-semibold uppercase tracking-wider text-slate-700">
              Company / Organization
            </label>
            <select
              id="company-select"
              value={companySelection}
              onChange={(e) => setCompanySelection(e.target.value)}
              className="mt-1.5 w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 bg-white"
            >
              <option value="">
                {companiesLoading ? "Loading companies…" : "Select a company…"}
              </option>
              {companies.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
              <option value="other">Other / Not Listed</option>
            </select>
            <p className="mt-1 text-xs text-slate-500">
              Select your registered company in Central IAM, or choose &quot;Other&quot; if not listed.
            </p>

            {companySelection === "other" && (
              <div className="mt-2.5">
                <input
                  type="text"
                  value={customOrganization}
                  onChange={(e) => setCustomOrganization(e.target.value)}
                  placeholder="Enter organization or company name (or leave empty)"
                  className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                />
              </div>
            )}
          </div>

          <div>
            <label className="block text-xs font-semibold uppercase tracking-wider text-slate-700">
              Developer / Contact Name <span className="text-rose-500">*</span>
            </label>
            <input
              type="text"
              required
              value={developerName}
              onChange={(e) => setDeveloperName(e.target.value)}
              placeholder="e.g. Alex Mercer"
              className="mt-1.5 w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
            />
          </div>

          <div>
            <label className="block text-xs font-semibold uppercase tracking-wider text-slate-700">
              Developer Contact Email <span className="text-rose-500">*</span>
            </label>
            <input
              type="email"
              required
              value={developerEmail}
              onChange={(e) => setDeveloperEmail(e.target.value)}
              placeholder="e.g. alex@example.com"
              className="mt-1.5 w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
            />
            <p className="mt-1 text-xs text-slate-500">
              Used for approval status notification and credential management.
            </p>
          </div>
        </div>

        <div>
          <label className="block text-xs font-semibold uppercase tracking-wider text-slate-700">
            Client Type & Security Profile
          </label>
          <div className="mt-2 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <label
              className={`flex cursor-pointer items-start gap-3 rounded-lg border p-3.5 transition ${confidential
                  ? "border-indigo-600 bg-indigo-50/30 ring-1 ring-indigo-600"
                  : "border-slate-200 bg-white hover:bg-slate-50"
                }`}
            >
              <input
                type="radio"
                name="clientType"
                checked={confidential}
                onChange={() => setConfidential(true)}
                className="mt-1 text-indigo-600 focus:ring-indigo-500"
              />
              <div>
                <span className="block text-sm font-semibold text-slate-900">
                  Confidential Client (Backend Server)
                </span>
                <span className="mt-0.5 block text-xs text-slate-500">
                  PHP/Laravel, Node.js, Python/Django backend apps. Central IAM issues a Client Secret
                  held securely on your server.
                </span>
              </div>
            </label>

            <label
              className={`flex cursor-pointer items-start gap-3 rounded-lg border p-3.5 transition ${!confidential
                  ? "border-indigo-600 bg-indigo-50/30 ring-1 ring-indigo-600"
                  : "border-slate-200 bg-white hover:bg-slate-50"
                }`}
            >
              <input
                type="radio"
                name="clientType"
                checked={!confidential}
                onChange={() => setConfidential(false)}
                className="mt-1 text-indigo-600 focus:ring-indigo-500"
              />
              <div>
                <span className="block text-sm font-semibold text-slate-900">
                  Public Client (SPA / Mobile)
                </span>
                <span className="mt-0.5 block text-xs text-slate-500">
                  React SPA, Vue, Mobile apps. Authenticates via S256 PKCE without client secret storage.
                </span>
              </div>
            </label>
          </div>
        </div>

        <div>
          <div className="flex items-center justify-between">
            <label className="block text-xs font-semibold uppercase tracking-wider text-slate-700">
              OAuth 2.0 Redirect URIs <span className="text-rose-500">*</span>
            </label>
            {redirectUris.length < 10 && (
              <button
                type="button"
                onClick={handleAddUri}
                className="text-xs font-semibold text-indigo-600 hover:text-indigo-500"
              >
                + Add Another URI
              </button>
            )}
          </div>
          <p className="mt-1 text-xs text-slate-500">
            Authorized callback URLs where Central IAM redirects users with the authorization code.
          </p>

          <div className="mt-2 space-y-2">
            {redirectUris.map((uri, idx) => (
              <div key={idx} className="flex items-center gap-2">
                <input
                  type="url"
                  required
                  value={uri}
                  onChange={(e) => handleUriChange(idx, e.target.value)}
                  placeholder="https://your-app.test/auth/doxa/callback"
                  className="flex-1 rounded-md border border-slate-300 px-3 py-2 text-sm font-mono text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                />
                {redirectUris.length > 1 && (
                  <button
                    type="button"
                    onClick={() => handleRemoveUri(idx)}
                    className="rounded-md border border-slate-200 px-2.5 py-2 text-xs font-medium text-slate-500 hover:bg-slate-100 hover:text-slate-800 transition"
                  >
                    Remove
                  </button>
                )}
              </div>
            ))}
          </div>
        </div>

        <div>
          <label className="block text-xs font-semibold uppercase tracking-wider text-slate-700">
            Description & Purpose <span className="text-slate-400 font-normal">(Optional)</span>
          </label>
          <textarea
            rows={3}
            value={description}
            onChange={(e) => setDescription(e.target.value)}
            placeholder="Describe what services and user cohorts this application integrates..."
            className="mt-1.5 w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
          />
        </div>

        <div className="pt-2 border-t border-slate-100 flex items-center justify-between">
          <p className="text-xs text-slate-500">
            Standard scopes: <code className="font-mono text-indigo-600">openid profile email</code>
          </p>
          <Button type="submit" isLoading={loading} className="bg-indigo-600 hover:bg-indigo-500">
            Submit Application Request
          </Button>
        </div>
      </form>
    </div>
  );
}
