"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { AdminPage } from "@/components/admin/AdminPage";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { FormField } from "@/components/forms/FormField";
import { LoadingState } from "@/components/ui/LoadingState";
import { getApiErrorMessage } from "@/services/api-client";
import { applicationsService } from "@/services/applications.service";

import { companiesService } from "@/services/companies.service";
import type { Company } from "@/types/company";

export { ApplicationsList } from "./applications/ApplicationsList";
export { ApplicationDetails } from "./applications/ApplicationDetails";

export function ApplicationForm({ id }: { id?: string }) {
  const router = useRouter();
  const [name, setName] = useState("");
  const [slug, setSlug] = useState("");
  const [description, setDescription] = useState("");
  const [companyId, setCompanyId] = useState("");
  const [companies, setCompanies] = useState<Company[]>([]);
  const [loading, setLoading] = useState(Boolean(id));
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    companiesService.list("", 1, 100).then((res) => setCompanies(res.data)).catch(() => {});
    if (id) {
      applicationsService.get(id).then((a) => {
        setName(a.name);
        setSlug(a.slug);
        setDescription(a.description ?? "");
        setCompanyId(a.company_id ?? "");
      }).catch((e) => setError(getApiErrorMessage(e))).finally(() => setLoading(false));
    }
  }, [id]);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    try {
      const input = {
        name,
        slug,
        description: description || null,
        company_id: companyId || null,
      };
      const a = id ? await applicationsService.update(id, input) : await applicationsService.create(input);
      router.push(`/applications/${a.id}`);
    } catch (err) {
      setError(getApiErrorMessage(err));
    } finally {
      setSaving(false);
    }
  }

  return (
    <AdminPage title={id ? "Edit application" : "Register application"}>
      {loading ? (
        <LoadingState />
      ) : (
        <form onSubmit={submit} className="max-w-xl space-y-5 rounded-lg border bg-white p-6">
          {error ? <Alert>{error}</Alert> : null}
          <FormField name="name" label="Name" value={name} onChange={(e) => setName(e.target.value)} required />
          <FormField name="slug" label="Slug" value={slug} onChange={(e) => setSlug(e.target.value)} required />
          <div>
            <label htmlFor="company_id" className="mb-1.5 block text-sm font-medium text-slate-700">
              Assigned Company
            </label>
            <select
              id="company_id"
              name="company_id"
              value={companyId}
              onChange={(e) => setCompanyId(e.target.value)}
              className="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900"
            >
              <option value="">No company assigned (Global application)</option>
              {companies.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </select>
            <p className="mt-1.5 text-xs text-slate-500">
              When assigned to a company, active company employees are granted access to this application by default. You can revoke access individually.
            </p>
          </div>
          <div>
            <label htmlFor="description" className="mb-1.5 block text-sm font-medium text-slate-700">
              Description
            </label>
            <textarea
              id="description"
              name="description"
              value={description}
              onChange={(e) => setDescription(e.target.value)}
              className="min-h-28 w-full rounded-md border border-slate-300 p-3"
            />
          </div>
          <Button isLoading={saving}>Save application</Button>
        </form>
      )}
    </AdminPage>
  );
}
