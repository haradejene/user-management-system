import { AuthCard } from "@/components/auth/AuthCard";
import { RegisterForm } from "@/components/auth/RegisterForm";

export default function RegisterPage() {
  return (
    <AuthCard
      title="Create your account"
      description="Register a development account in the central identity system."
      linkHref="/login"
      linkLabel="Already registered? Login"
    >
      <RegisterForm />
    </AuthCard>
  );
}
