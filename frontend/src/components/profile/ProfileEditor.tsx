"use client";

import { useEffect, useState } from "react";
import { AdminPage } from "@/components/admin/AdminPage";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { LoadingState } from "@/components/ui/LoadingState";
import { getApiErrorMessage } from "@/services/api-client";
import { profileService } from "@/services/profile.service";
import type { ProfileInput, UserProfile } from "@/types/profile";

export function ProfileEditor({ userId }: { userId?: string }) {
  const [profile, setProfile] = useState<UserProfile | null>(null);
  const [fields, setFields] = useState<ProfileInput>({});
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState(false);

  useEffect(() => {
    let current = true;
    const request = userId ? profileService.forUser(userId) : profileService.me();
    request.then((value) => {
      if (!current) return;
      setProfile(value);
      setFields({ first_name: value.first_name, last_name: value.last_name, phone: value.phone, photo: value.photo });
    }).catch((requestError) => { if (current) setError(getApiErrorMessage(requestError)); })
      .finally(() => { if (current) setLoading(false); });
    return () => { current = false; };
  }, [userId]);

  function change(field: keyof ProfileInput, value: string): void {
    setFields((current) => ({ ...current, [field]: value }));
    setSuccess(false);
  }

  async function submit(event: React.FormEvent<HTMLFormElement>): Promise<void> {
    event.preventDefault();
    setSaving(true); setError(null); setSuccess(false);
    try {
      const updated = userId ? await profileService.updateUser(userId, fields) : await profileService.updateMe(fields);
      setProfile(updated);
      setFields({ first_name: updated.first_name, last_name: updated.last_name, phone: updated.phone, photo: updated.photo });
      setSuccess(true);
    } catch (requestError) { setError(getApiErrorMessage(requestError)); } finally { setSaving(false); }
  }

  if (loading) return <LoadingState label="Loading profile…" />;
  if (!profile) return <Alert>{error ?? "Unable to load profile."}</Alert>;
  return <form onSubmit={submit} className="space-y-5 rounded-xl border border-slate-200 bg-white p-6">
    {error ? <Alert>{error}</Alert> : null}
    {success ? <p role="status" className="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">Profile updated.</p> : null}
    {([['first_name', 'First name', 100], ['last_name', 'Last name', 100], ['phone', 'Phone', 30], ['photo', 'Photo URL', 2048]] as const).map(([field, label, max]) => <label key={field} className="block text-sm font-medium text-slate-700">{label}<input value={fields[field] ?? ""} onChange={(event) => change(field, event.target.value)} maxLength={max} className="mt-1 min-h-11 w-full rounded-md border border-slate-300 px-3 font-normal" /></label>)}
    <Button type="submit" isLoading={saving}>Save profile</Button>
  </form>;
}

export function OwnProfilePage() {
  return <main className="mx-auto max-w-2xl px-6 py-10"><h1 className="mb-2 text-3xl font-semibold text-slate-900">My profile</h1><p className="mb-8 text-sm text-slate-600">Manage your central IAM profile details.</p><ProfileEditor /></main>;
}

export function AdminProfilePage({ userId }: { userId: string }) {
  return <AdminPage title="User profile" description="Edit central profile details only; identity, lifecycle and access fields are managed separately."><ProfileEditor userId={userId} /></AdminPage>;
}
