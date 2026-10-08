"use client";

import { AxiosError } from "axios";
import { useEffect, useRef, useState } from "react";
import { FormField } from "@/components/forms/FormField";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Modal } from "@/components/ui/Modal";
import { applicationsService } from "@/services/applications.service";
import { getApiErrorMessage } from "@/services/api-client";
import type { ValidationErrorResponse } from "@/types/api";
import type { OAuthClientCreation } from "@/types/oauth-client";
import { OAuthClientIntegration } from "./OAuthClientIntegration";
import { OAuthClientMetadata } from "./OAuthClientDetail";

/** This component alone owns the creation response, including its one-time secret.
 * Closing it or leaving the tab unmounts it and discards that response. */
export function OAuthClientForm({ applicationId, onCreated, onClose }: { applicationId: string; onCreated: () => void; onClose: () => void }) {
  const [name, setName] = useState("");
  const [uris, setUris] = useState("");
  const [confidential, setConfidential] = useState(false);
  const [review, setReview] = useState(false);
  const [busy, setBusy] = useState(false);
  const pending = useRef(false);
  const mounted = useRef(true);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [error, setError] = useState<string | null>(null);
  const [created, setCreated] = useState<OAuthClientCreation | null>(null);
  const [copyStatus, setCopyStatus] = useState("");
  const summary = useRef<HTMLDivElement>(null);
  const reviewHeading = useRef<HTMLElement>(null);
  useEffect(() => { if (Object.keys(errors).length || error) summary.current?.focus(); }, [errors, error]);
  useEffect(() => { if (review) reviewHeading.current?.focus(); }, [review]);
  useEffect(() => { mounted.current = true; return () => { mounted.current = false; }; }, []);

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    if (pending.current) return;
    const redirect_uris = uris.split(/\r?\n/).filter((uri) => uri !== "");
    const validation: Record<string, string[]> = {};
    if (!name.trim()) validation.name = ["Client name is required."];
    if (!redirect_uris.length) validation.redirect_uris = ["At least one redirect URI is required."];
    if (new Set(redirect_uris).size !== redirect_uris.length) validation.redirect_uris = ["Duplicate redirect URIs are not allowed."];
    setErrors(validation); setError(null);
    if (Object.keys(validation).length) return;
    if (!review) { setReview(true); return; }
    pending.current = true; setBusy(true);
    try {
      const response = await applicationsService.createOAuthClient(applicationId, { name: name.trim(), redirect_uris, confidential });
      if (!mounted.current) return;
      setCreated(response); onCreated();
    } catch (e) {
      if (!mounted.current) return;
      setReview(false);
      if (e instanceof AxiosError && e.response?.status === 422) setErrors((e.response.data as ValidationErrorResponse).errors ?? {});
      setError(getApiErrorMessage(e));
    } finally { pending.current = false; if (mounted.current) setBusy(false); }
  }

  async function copySecret() {
    if (!created?.client_secret) return;
    try { await navigator.clipboard.writeText(created.client_secret); if (mounted.current) setCopyStatus("Copied."); }
    catch { if (mounted.current) setCopyStatus("Unable to copy. Select and copy the secret manually."); }
  }

  return <Modal open title={created ? "Client Created" : "Register OAuth Client"} onClose={() => { if (!pending.current) onClose(); }}>
    <div className="max-h-[70vh] overflow-y-auto space-y-4">
      {created ? <>
        <OAuthClientMetadata client={created.data} />
        {created.data.confidential && created.client_secret ? <section className="space-y-3">
          <h3 className="font-semibold">Save Your Client Secret</h3>
          <Alert>This secret is shown once. Save it securely now; it cannot be retrieved later. Closing this view discards it.</Alert>
          <code className="block select-all break-all rounded bg-slate-100 p-3">{created.client_secret}</code>
          <Button onClick={() => void copySecret()}>Copy secret</Button>
          {copyStatus ? <p role="status" className="text-sm">{copyStatus}</p> : null}
        </section> : <p role="status">OAuth client registered successfully.</p>}
        <OAuthClientIntegration client={created.data} /><Button variant="secondary" onClick={onClose}>Close</Button>
      </> : <form noValidate onSubmit={submit} className="space-y-4">
        {error || Object.keys(errors).length ? <div ref={summary} tabIndex={-1} role="alert" className="rounded border border-red-300 p-3 text-sm"><p>{error ?? "Review the highlighted fields."}</p>{Object.keys(errors).map((field) => <a className="mr-3 underline" key={field} href={field === "name" ? "#oauth-name" : field.startsWith("redirect_uris") ? "#redirect-uris" : "#client-type"}>Review {field === "name" ? "client name" : field.startsWith("redirect_uris") ? "redirect URIs" : "client type"}</a>)}</div> : null}
        {review ? <section ref={reviewHeading} tabIndex={-1} aria-label="Review OAuth configuration" className="space-y-3 rounded border p-3"><h3 className="font-semibold">Review OAuth configuration</h3><p>{name}: {confidential ? "Confidential" : "Public"} client</p><ul>{uris.split(/\r?\n/).filter(Boolean).map((uri, index) => <li key={index} className="break-all">{uri}</li>)}</ul><p>PKCE: Required. Method: S256. OAuth grants and scopes are controlled by IAM.</p><Button type="button" variant="secondary" onClick={() => setReview(false)}>Edit configuration</Button></section> : null}
        <fieldset disabled={busy} className="space-y-4">
          <FormField name="oauth-name" label="Client name" disabled={review} required maxLength={255} value={name} onChange={(e) => setName(e.target.value)} error={errors.name?.join(" ")} />
          <div><label htmlFor="client-type" className="block text-sm font-medium">Client type</label><select id="client-type" disabled={review} value={confidential ? "confidential" : "public"} onChange={(e) => setConfidential(e.target.value === "confidential")} className="w-full rounded border p-2"><option value="public">Public</option><option value="confidential">Confidential</option></select></div>
          <p className="text-sm text-slate-600">Public clients cannot safely store a credential (for example browsers). Confidential clients can securely store a credential on a server, such as a Laravel application.</p><div><label htmlFor="redirect-uris" className="block text-sm font-medium">Redirect URIs</label><p id="uris-help" className="text-sm text-slate-500">Enter one exact URI per line. Values are sent unchanged. No wildcards or fragments. Production callbacks require HTTPS. The current backend accepts HTTP; use it only for local development.</p><textarea id="redirect-uris" disabled={review} required value={uris} onChange={(e) => setUris(e.target.value)} aria-invalid={Object.keys(errors).some((key) => key.startsWith("redirect_uris"))} aria-describedby="uris-help uris-errors" className="min-h-28 w-full rounded border p-2" /><div id="uris-errors" className="text-sm text-red-600">{Object.entries(errors).filter(([key]) => key.startsWith("redirect_uris")).flatMap(([key, values]) => values.map((message, index) => <p key={`${key}-${index}`}>{message}</p>))}</div></div>
          <p className="text-sm text-slate-500">Authorization code uses mandatory PKCE: Required, method S256. Grant types and allowed scopes are assigned by the server.</p>
          {Object.entries(errors).filter(([key]) => key !== "name" && !key.startsWith("redirect_uris")).flatMap(([key, values]) => values.map((message, index) => <p className="text-sm text-red-600" key={`${key}-${index}`}>{message}</p>))}
          <div className="flex gap-3"><Button type="submit" isLoading={busy}>{review ? "Create client" : "Register client"}</Button><Button type="button" variant="secondary" onClick={onClose}>Cancel</Button></div>
        </fieldset>
      </form>}
    </div>
  </Modal>;
}
