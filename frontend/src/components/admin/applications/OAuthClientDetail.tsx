"use client";

import { useEffect, useState } from "react";
import { StatusBadge } from "@/components/admin/AdminPage";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { LoadingState } from "@/components/ui/LoadingState";
import { Modal } from "@/components/ui/Modal";
import { applicationsService } from "@/services/applications.service";
import { getApiErrorMessage } from "@/services/api-client";
import type { OAuthClient } from "@/types/oauth-client";
import { OAuthClientIntegration, OAuthRedirectEditor } from "./OAuthClientIntegration";
import { applicationDate } from "./ApplicationOverview";

export function OAuthClientMetadata({ client }: { client: OAuthClient }) {
  return <dl className="space-y-3 text-sm">
    {[["Client name", client.name], ["Client ID", client.id], ["Client type", client.confidential ? "Confidential" : "Public"], ["Grant types", client.grant_types.join(", ")], ["PKCE", client.pkce_required === undefined ? "Unavailable" : client.pkce_required ? `Required; method ${client.pkce_method}` : "Not applicable"], ["Allowed scopes", client.allowed_scopes?.join(", ") ?? "Unavailable"], ["Created at", applicationDate(client.created_at)], ["Updated at", applicationDate(client.updated_at)]].map(([label, value]) => <div key={label}><dt className="text-slate-500">{label}</dt><dd className="break-all">{value}</dd></div>)}
    <div><dt className="text-slate-500">Status</dt><dd><StatusBadge status={client.revoked ? "revoked" : "active"} /></dd></div>
    <div><dt className="text-slate-500">Redirect URIs</dt><dd><ul>{client.redirect_uris.map((uri, index) => <li className="break-all" key={index}>{uri}</li>)}</ul></dd></div>
  </dl>;
}

export function OAuthClientDetail({ applicationId, clientId, onClose }: { applicationId: string; clientId: string; onClose: () => void }) {
  const [client, setClient] = useState<OAuthClient | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [retry, setRetry] = useState(0);
  useEffect(() => {
    let cancelled = false;
    applicationsService.oauthClient(applicationId, clientId).then((data) => { if (!cancelled) setClient(data); }).catch((e) => { if (!cancelled) setError(getApiErrorMessage(e)); });
    return () => { cancelled = true; };
  }, [applicationId, clientId, retry]);
  return <Modal open title="OAuth client details" onClose={onClose}>
    <div className="max-h-[65vh] overflow-y-auto">{error ? <><Alert>{error}</Alert><Button onClick={() => { setError(null); setRetry((n) => n + 1); }}>Retry</Button></> : client ? <div className="space-y-6"><OAuthClientMetadata client={client} /><OAuthRedirectEditor applicationId={applicationId} client={client} onChanged={setClient} /><OAuthClientIntegration client={client} /></div> : <LoadingState label="Loading OAuth client…" />}</div>
    <Button className="mt-4" variant="secondary" onClick={onClose}>Close</Button>
  </Modal>;
}
