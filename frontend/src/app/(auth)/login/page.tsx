import { AuthCard } from "@/components/auth/AuthCard";
import { LoginForm } from "@/components/auth/LoginForm";

export default function LoginPage() {
  return (
    <AuthCard
      title="Welcome back"
      description="Sign in with your central company identity."
      linkHref="/register"
      linkLabel="Need an account? Register"
    >
      <LoginForm />
    </AuthCard>
  );
}
