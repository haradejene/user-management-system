"use client";

import Link from "next/link";
import { AxiosError } from "axios";
import { useEffect, useRef, useState } from "react";
import { Button } from "@/components/ui/Button";
import { Alert } from "@/components/ui/Alert";
import { applicationsService } from "@/services/applications.service";
import { getApiErrorMessage } from "@/services/api-client";
import type { OAuthClient } from "@/types/oauth-client";

export function OAuthClientIntegration({ client }: { client: OAuthClient }) {
  const [status, setStatus] = useState("");
  async function copy(value: string) {
    try { await navigator.clipboard.writeText(value); setStatus("Copied."); }
    catch { setStatus("Unable to copy. Select and copy the value manually."); }
  }
  const example = [
    "# Laravel SDK server-side environment",
    `DOXA_ISSUER=${JSON.stringify(client.issuer ?? "<issuer>")}`,
    `# Discovery URL: ${client.discovery_url ?? "<discovery URL>"}`,
    `DOXA_CLIENT_ID=${JSON.stringify(client.id)}`,
    `DOXA_REDIRECT_URI=${JSON.stringify(client.redirect_uris[0] ?? "<exact redirect URI>")}`,
    "# Grant: authorization_code",
    `# Allowed scopes: ${(client.allowed_scopes ?? []).join(" ")}`,
    "# Laravel SDK defaults to openid; set scopes in config/doxa.php as needed.",
    ...(client.confidential ? ["DOXA_CLIENT_SECRET=<credential saved securely at creation>"] : []),
    "# PKCE is required: S256. Generate a fresh verifier for every authorization.",
  ].join("\n");
  return <section aria-label="Developer integration" className="space-y-3 border-t pt-4">
    <h3 className="font-semibold">Developer integration</h3><Button variant="secondary" onClick={() => void copy(client.id)}>Copy Client ID</Button>
    {client.revoked ? <Alert>This client is revoked and cannot be used for sign-in.</Alert> : null}
    {[["Issuer", client.issuer], ["Discovery URL", client.discovery_url], ...client.redirect_uris.map((uri, index) => [`Exact redirect URI ${index + 1}`, uri])].map(([label, value]) => <div key={label} className="space-y-1"><p className="text-sm text-slate-600">{label}</p><code className="block break-all select-all">{value ?? "Unavailable"}</code>{value ? <Button variant="secondary" onClick={() => void copy(value)}>Copy {label}</Button> : null}</div>)}
    <p className="text-sm">Client type: {client.confidential ? "Confidential" : "Public"}. Grant: authorization_code. PKCE: {client.pkce_required ? `Required (${client.pkce_method})` : "Unavailable"}. Allowed scopes: {client.allowed_scopes?.join(", ") ?? "Unavailable"}. profile/email require openid. OIDC also requires a fresh nonce.</p>
    <pre className="overflow-x-auto rounded bg-slate-100 p-3 text-xs">{example}</pre>
    <Button variant="secondary" onClick={() => void copy(example)}>Copy environment example</Button>
    <p role="status" className="text-sm">{status}</p>
    <p className="text-sm">Keep credentials in the consuming server environment. Never use public frontend build variables for secrets. Choose the exact callback URI your application uses.</p>
    <Link className="underline" href={`/applications/${client.application_id}/user-access`}>Manage application access</Link>
  </section>;
}

export function OAuthRedirectEditor({ applicationId, client, onChanged }: { applicationId: string; client: OAuthClient; onChanged: (client: OAuthClient) => void }) {
  const [uris, setUris] = useState(client.redirect_uris);
  const [busy, setBusy] = useState(false);
  const pending = useRef(false);
  const [error, setError] = useState("");
  const [success, setSuccess] = useState("");
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const summary = useRef<HTMLDivElement>(null);
  useEffect(() => { if (error) summary.current?.focus(); }, [error]);
  async function save(event: React.FormEvent) {
    event.preventDefault();
    if (pending.current) return;
    setError(""); setSuccess(""); setFieldErrors({});
    if (!uris.length || uris.some((uri) => !uri)) { setError("At least one complete redirect URI is required."); return; }
    if (new Set(uris).size !== uris.length) { setError("Duplicate redirect URIs are not allowed."); return; }
    pending.current = true; setBusy(true);
    try { const next = await applicationsService.updateOAuthRedirects(applicationId, client.id, uris, client.updated_at); onChanged(next); setUris(next.redirect_uris); setSuccess("Redirect URIs saved."); }
    catch (e) {
      setError(getApiErrorMessage(e));
      if (e instanceof AxiosError && e.response?.status === 422) setFieldErrors(e.response.data.errors ?? {});
    }
    finally { pending.current = false; setBusy(false); }
  }
  return <form onSubmit={save} className="space-y-3 border-t pt-4">
    <h3 className="font-semibold">Manage exact redirect URIs</h3>
    <p className="text-sm text-slate-600">Values are sent unchanged and must exactly match the callback. No wildcards or fragments. Production callbacks require HTTPS. The current backend accepts HTTP; use it only for local development.</p>
    {error ? <div ref={summary} tabIndex={-1}><Alert>{error}</Alert>{Object.entries(fieldErrors).map(([field, messages]) => <p className="text-sm text-red-600" key={field}><a href={`#redirect-${field.split(".")[1] ?? "0"}`} className="underline">{field.startsWith("redirect_uris.") ? `Redirect URI ${Number(field.split(".")[1]) + 1}` : "Redirect configuration"}</a>: {messages.join(" ")}</p>)}</div> : null}
    <fieldset disabled={busy || client.revoked} className="space-y-3">
      {uris.map((uri, index) => <div key={index} className="flex flex-wrap gap-2"><label className="w-full text-sm" htmlFor={`redirect-${index}`}>Redirect URI {index + 1}</label><input id={`redirect-${index}`} aria-invalid={Boolean(fieldErrors[`redirect_uris.${index}`])} aria-describedby={fieldErrors[`redirect_uris.${index}`] ? `redirect-error-${index}` : undefined} value={uri} className="min-w-0 flex-1 rounded border p-2" onChange={(e) => setUris(uris.map((old, at) => at === index ? e.target.value : old))} /><Button type="button" variant="secondary" onClick={() => setUris(uris.filter((_, at) => at !== index))}>Remove URI {index + 1}</Button>{fieldErrors[`redirect_uris.${index}`] ? <p id={`redirect-error-${index}`} className="w-full text-sm text-red-600">{fieldErrors[`redirect_uris.${index}`].join(" ")}</p> : null}</div>)}
      <Button type="button" variant="secondary" onClick={() => setUris([...uris, ""])}>Add URI</Button>
      <Button type="submit" isLoading={busy}>Save redirect URIs</Button>
    </fieldset>
    {success ? <p role="status">{success}</p> : null}
  </form>;
}
