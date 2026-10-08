"use client";

import { useEffect, useState } from "react";
import { AdminPage, StatusBadge } from "@/components/admin/AdminPage";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { LoadingState } from "@/components/ui/LoadingState";
import { applicationsService } from "@/services/applications.service";
import { getApiErrorMessage } from "@/services/api-client";
import type { Application } from "@/types/application";
import { ApplicationHistory } from "./ApplicationHistory";
import { ApplicationOverview } from "./ApplicationOverview";
import { ApplicationOAuthClients } from "./ApplicationOAuthClients";
import { ApplicationUserAccess } from "./ApplicationUserAccess";
import { ApplicationSettings } from "./ApplicationSettings";
import { ApplicationTabs, type ApplicationTab } from "./ApplicationTabs";

export function ApplicationDetails({ id, tab = "overview" }: { id: string; tab?: ApplicationTab }) {
  return <ApplicationDetailState key={id} id={id} tab={tab} />;
}

function ApplicationDetailState({ id, tab }: { id: string; tab: ApplicationTab }) {
  const [application, setApplication] = useState<Application | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [retry, setRetry] = useState(0);

  useEffect(() => {
    let cancelled = false;
    applicationsService.get(id).then((next) => {
      if (!cancelled) { setApplication(next); setError(null); setLoading(false); }
    }).catch((e) => { if (!cancelled) { setError(getApiErrorMessage(e)); setLoading(false); } });
    return () => { cancelled = true; };
  }, [id, retry]);

  return <AdminPage title={application?.name ?? "Application details"} description={application?.description ?? undefined}>
    {loading ? <LoadingState label="Loading application…" /> : error ? <div className="space-y-4"><Alert>{error}</Alert><Button variant="secondary" onClick={() => { setError(null); setLoading(true); setRetry((value) => value + 1); }}>Retry</Button></div> : application ? <>
      <div className="mb-6"><StatusBadge status={application.status} /></div>
      <ApplicationTabs id={application.id} selected={tab} />
      {tab === "settings" ? <ApplicationSettings application={application} onChanged={setApplication} /> : tab === "oauth-clients" ? <ApplicationOAuthClients applicationId={id} /> : tab === "user-access" ? <ApplicationUserAccess applicationId={id} applicationName={application.name} /> : <ApplicationOverview application={application} />}
      <ApplicationHistory key={`${id}-${tab}`} applicationId={id} />
    </> : null}
  </AdminPage>;
}
