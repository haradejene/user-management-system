"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";
import { OwnProfilePage } from "@/components/profile/ProfileEditor";
import { LoadingState } from "@/components/ui/LoadingState";
import { useAuth } from "@/hooks/useAuth";

export default function ProfilePage() {
  const { user, isLoading } = useAuth();
  const router = useRouter();
  useEffect(() => { if (!isLoading && !user) router.replace("/login?next=%2Fprofile"); }, [isLoading, router, user]);
  if (isLoading || !user) return <LoadingState label="Checking your session…" fullPage />;
  return <OwnProfilePage />;
}
