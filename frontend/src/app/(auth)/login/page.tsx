import { AuthCard } from "@/components/auth/AuthCard";
import { LoginForm } from "@/components/auth/LoginForm";
import { Suspense } from "react";

export const metadata = { referrer: "strict-origin-when-cross-origin" as const };

export default function LoginPage() {
  return (
    <AuthCard
      title="Sign in to Doxa"
      description="One account for your workplace. Sign in securely, then review what your application can access."
      linkHref="/register"
      linkLabel="Need an account? Register"
    >
      <Suspense fallback={<p role="status">Checking your session…</p>}><LoginForm /></Suspense>
    </AuthCard>
  );
}
