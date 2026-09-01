"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { useForm } from "react-hook-form";

import { FormField } from "@/components/forms/FormField";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { useAuth } from "@/hooks/useAuth";
import { registerSchema, type RegisterFormValues } from "@/schemas/auth";
import { getApiErrorMessage } from "@/services/api-client";

export function RegisterForm() {
  const { register: registerUser } = useAuth();
  const router = useRouter();
  const [requestError, setRequestError] = useState<string | null>(null);
  const {
    formState: { errors, isSubmitting },
    handleSubmit,
    register,
  } = useForm<RegisterFormValues>({
    resolver: zodResolver(registerSchema),
    defaultValues: {
      name: "",
      email: "",
      password: "",
      password_confirmation: "",
    },
  });

  async function onSubmit(values: RegisterFormValues): Promise<void> {
    setRequestError(null);
    try {
      await registerUser(values);
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
        label="Name"
        autoComplete="name"
        error={errors.name?.message}
        {...register("name")}
      />
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
        autoComplete="new-password"
        error={errors.password?.message}
        {...register("password")}
      />
      <FormField
        label="Confirm password"
        type="password"
        autoComplete="new-password"
        error={errors.password_confirmation?.message}
        {...register("password_confirmation")}
      />
      <Button type="submit" className="w-full" isLoading={isSubmitting}>
        Create account
      </Button>
    </form>
  );
}
