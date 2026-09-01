"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { useForm } from "react-hook-form";

import { FormField } from "@/components/forms/FormField";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { useAuth } from "@/hooks/useAuth";
import { loginSchema, type LoginFormValues } from "@/schemas/auth";
import { getApiErrorMessage } from "@/services/api-client";

export function LoginForm() {
  const { login } = useAuth();
  const router = useRouter();
  const [requestError, setRequestError] = useState<string | null>(null);
  const {
    formState: { errors, isSubmitting },
    handleSubmit,
    register,
  } = useForm<LoginFormValues>({
    resolver: zodResolver(loginSchema),
    defaultValues: { email: "", password: "", remember: false },
  });

  async function onSubmit(values: LoginFormValues): Promise<void> {
    setRequestError(null);
    try {
      await login(values);
      router.replace("/dashboard");
      router.refresh();
    } catch (error) {
      setRequestError(getApiErrorMessage(error));
    }
  }

  return (
    <form className="space-y-4" onSubmit={handleSubmit(onSubmit)} noValidate>
      {requestError ? <Alert>{requestError}</Alert> : null}
      <FormField
        label="Email"
        type="email"
        autoComplete="email"
        error={errors.email?.message}
        {...register("email")}
      />
      <FormField
        label="Password"
        type="password"
        autoComplete="current-password"
        error={errors.password?.message}
        {...register("password")}
      />
      <label className="flex items-center gap-2 text-sm text-slate-600">
        <input
          type="checkbox"
          className="size-4 rounded border-slate-300"
          {...register("remember")}
        />
        Keep me signed in
      </label>
      <Button type="submit" className="w-full" isLoading={isSubmitting}>
        Login
      </Button>
    </form>
  );
}
