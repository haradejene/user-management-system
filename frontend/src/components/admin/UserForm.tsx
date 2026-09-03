"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { AdminPage } from "@/components/admin/AdminPage";
import { FormField } from "@/components/forms/FormField";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { LoadingState } from "@/components/ui/LoadingState";
import { getApiErrorMessage } from "@/services/api-client";
import { usersService } from "@/services/users.service";

export function UserForm({ id }: { id?: string }) {
  const router = useRouter(); const [name, setName] = useState(""); const [email, setEmail] = useState(""); const [password, setPassword] = useState(""); const [loading, setLoading] = useState(Boolean(id)); const [saving, setSaving] = useState(false); const [error, setError] = useState<string | null>(null);
  useEffect(() => { if (id) usersService.get(id).then((u) => { setName(u.name); setEmail(u.email); }).catch((e) => setError(getApiErrorMessage(e))).finally(() => setLoading(false)); }, [id]);
  async function submit(event: React.FormEvent) { event.preventDefault(); setSaving(true); setError(null); try { const input = { name, email, ...(password ? { password, password_confirmation: password } : {}) }; const user = id ? await usersService.update(id, input) : await usersService.create(input); router.push(`/users/${user.id}`); } catch (e) { setError(getApiErrorMessage(e)); } finally { setSaving(false); } }
  return <AdminPage title={id ? "Edit user" : "Create user"}>{loading ? <LoadingState /> : <form onSubmit={submit} className="max-w-xl space-y-5 rounded-lg border border-slate-200 bg-white p-6">{error ? <Alert>{error}</Alert> : null}<FormField name="name" label="Name" value={name} onChange={(e) => setName(e.target.value)} required /><FormField name="email" label="Email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} required /><FormField name="password" label={id ? "New password (optional)" : "Password"} type="password" value={password} onChange={(e) => setPassword(e.target.value)} required={!id} minLength={8} /><Button isLoading={saving}>Save user</Button></form>}</AdminPage>;
}
