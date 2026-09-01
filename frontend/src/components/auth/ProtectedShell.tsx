"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useEffect, useState } from "react";

import { Button } from "@/components/ui/Button";
import { LoadingState } from "@/components/ui/LoadingState";
import { useAuth } from "@/hooks/useAuth";

export function ProtectedShell({
  children,
}: Readonly<{ children: React.ReactNode }>) {
  const { user, isLoading, logout } = useAuth();
  const router = useRouter();
  const pathname = usePathname();
  const [isLoggingOut, setIsLoggingOut] = useState(false);

  useEffect(() => {
    if (!isLoading && !isLoggingOut && !user) {
      router.replace(`/login?next=${encodeURIComponent(pathname)}`);
    }
  }, [isLoading, isLoggingOut, pathname, router, user]);

  if (isLoading || !user) {
    return <LoadingState label="Checking your session…" fullPage />;
  }

  async function handleLogout(): Promise<void> {
    setIsLoggingOut(true);
    await logout();
    router.replace("/login");
    router.refresh();
  }

  return (
    <div className="min-h-screen bg-slate-50">
      <header className="border-b border-slate-200 bg-white">
        <div className="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
          <Link href="/dashboard" className="font-semibold text-slate-900">
            Central IAM
          </Link>
          <div className="flex items-center gap-4">
            <span className="hidden text-sm text-slate-600 sm:inline">
              {user.name}
            </span>
            <Button
              variant="secondary"
              isLoading={isLoggingOut}
              onClick={handleLogout}
            >
              Logout
            </Button>
          </div>
        </div>
      </header>
      {children}
    </div>
  );
}
