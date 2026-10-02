"use client";

import { AxiosError } from "axios";
import { useEffect, useRef, useState } from "react";
import { ConfirmDialog } from "@/components/admin/ConfirmDialog";
import { FormField } from "@/components/forms/FormField";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { applicationsService } from "@/services/applications.service";
import { getApiErrorMessage } from "@/services/api-client";
import type { Application } from "@/types/application";
import type { ValidationErrorResponse } from "@/types/api";

export function ApplicationSettings({ application, onChanged }: { application: Application; onChanged: (application: Application) => void }) {
  const [name, setName] = useState(application.name);
  const [slug, setSlug] = useState(application.slug);
  const [description, setDescription] = useState(application.description ?? "");
  const [busy, setBusy] = useState(false);
  const [confirm, setConfirm] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const mounted = useRef(true);
  useEffect(() => { mounted.current = true; return () => { mounted.current = false; }; }, []);

  async function submit(event: React.FormEvent) {
    event.preventDefault(); setBusy(true); setErrors({}); setError(null); setSuccess(null);
    try {
      const updated = await applicationsService.update(application.id, { name, slug, description: description || null });
      if (!mounted.current) return;
      onChanged(updated); setName(updated.name); setSlug(updated.slug); setDescription(updated.description ?? "");
      setSuccess("Application settings saved.");
    } catch (e) {
      if (!mounted.current) return;
      if (e instanceof AxiosError && e.response?.status === 422) setErrors((e.response.data as ValidationErrorResponse).errors ?? {});
      setError(getApiErrorMessage(e));
    } finally { if (mounted.current) setBusy(false); }
  }

  async function changeStatus(action: "activate" | "deactivate") {
    setBusy(true); setError(null); setSuccess(null);
    try {
      const updated = await applicationsService.changeStatus(application.id, action);
      if (!mounted.current) return;
      onChanged(updated); setConfirm(false);
      setSuccess(action === "activate" ? "Application activated." : "Application deactivated.");
    } catch (e) { if (mounted.current) setError(getApiErrorMessage(e)); }
    finally { if (mounted.current) setBusy(false); }
  }

  return <div className="space-y-6">
    {error ? <Alert>{error}</Alert> : null}
    {success ? <p role="status" className="rounded-md bg-emerald-50 p-3 text-sm text-emerald-800">{success}</p> : null}
    <section className="rounded-xl border border-slate-200 bg-white p-6">
      <h2 className="mb-5 text-xl font-semibold">Settings</h2>
      <form onSubmit={submit}>
        <fieldset disabled={busy} className="max-w-xl space-y-5">
          <FormField name="name" label="Name" required maxLength={255} value={name} error={errors.name?.[0]} onChange={(e) => { setName(e.target.value); setSuccess(null); }} />
          <FormField name="slug" label="Slug" required maxLength={100} value={slug} error={errors.slug?.[0]} onChange={(e) => { setSlug(e.target.value); setSuccess(null); }} />
          <div className="space-y-1.5"><label htmlFor="description" className="block text-sm font-medium text-slate-700">Description</label><textarea id="description" name="description" maxLength={5000} value={description} aria-invalid={Boolean(errors.description)} aria-describedby={errors.description ? "description-error" : undefined} onChange={(e) => { setDescription(e.target.value); setSuccess(null); }} className="min-h-28 w-full rounded-md border border-slate-300 p-3" />{errors.description ? <p id="description-error" className="text-sm text-red-600">{errors.description[0]}</p> : null}</div>
          <Button type="submit" isLoading={busy}>Save application</Button>
        </fieldset>
      </form>
    </section>
    <section className="rounded-xl border border-slate-200 bg-white p-6">
      <h2 className="mb-2 text-xl font-semibold">Application status</h2>
      <p className="mb-4 text-sm text-slate-600">Inactive applications remain registered in IAM, but users have no effective access.</p>
      {application.status === "active" ? <Button variant="danger" disabled={busy} onClick={() => setConfirm(true)}>Deactivate application</Button> : <Button disabled={busy} onClick={() => void changeStatus("activate")}>Activate application</Button>}
    </section>
    <ConfirmDialog open={confirm} title="Deactivate application" message={`Deactivate ${application.name}? Users will lose effective access to this application.`} confirmLabel="Deactivate" busy={busy} onCancel={() => { if (!busy) setConfirm(false); }} onConfirm={() => void changeStatus("deactivate")} />
  </div>;
}
