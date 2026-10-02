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

export { ApplicationsList } from "./applications/ApplicationsList";
export { ApplicationDetails } from "./applications/ApplicationDetails";

export function ApplicationForm({ id }: { id?: string }) { const router = useRouter(); const [name, setName] = useState(""); const [slug, setSlug] = useState(""); const [description, setDescription] = useState(""); const [loading, setLoading] = useState(Boolean(id)); const [saving, setSaving] = useState(false); const [error, setError] = useState<string | null>(null); useEffect(() => { if (id) applicationsService.get(id).then((a) => { setName(a.name); setSlug(a.slug); setDescription(a.description ?? ""); }).catch((e) => setError(getApiErrorMessage(e))).finally(() => setLoading(false)); }, [id]); async function submit(e: React.FormEvent) { e.preventDefault(); setSaving(true); try { const input = { name, slug, description: description || null }; const a = id ? await applicationsService.update(id, input) : await applicationsService.create(input); router.push(`/applications/${a.id}`); } catch (err) { setError(getApiErrorMessage(err)); } finally { setSaving(false); } } return <AdminPage title={id ? "Edit application" : "Register application"}>{loading ? <LoadingState /> : <form onSubmit={submit} className="max-w-xl space-y-5 rounded-lg border bg-white p-6">{error ? <Alert>{error}</Alert> : null}<FormField name="name" label="Name" value={name} onChange={(e) => setName(e.target.value)} required /><FormField name="slug" label="Slug" value={slug} onChange={(e) => setSlug(e.target.value)} required /><div><label htmlFor="description" className="mb-1.5 block text-sm font-medium text-slate-700">Description</label><textarea id="description" name="description" value={description} onChange={(e) => setDescription(e.target.value)} className="min-h-28 w-full rounded-md border border-slate-300 p-3" /></div><Button isLoading={saving}>Save application</Button></form>}</AdminPage>; }
