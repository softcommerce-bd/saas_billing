"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useQueryClient } from "@tanstack/react-query";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { useState } from "react";
import { useForm } from "react-hook-form";
import { api } from "@/lib/api/client";
import type { Me } from "@/lib/api/types";
import { applyApiErrors } from "@/lib/forms";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { CheckboxField, TextField } from "@/components/ui/Field";
import { AuthCard } from "./AuthCard";
import { loginSchema, type LoginValues } from "./schemas";

/** Only allow same-site relative redirects after login. */
export function safeNext(next: string | null): string {
  return next && next.startsWith("/") && !next.startsWith("//") ? next : "/app";
}

export function LoginForm() {
  const router = useRouter();
  const params = useSearchParams();
  const queryClient = useQueryClient();
  const [formError, setFormError] = useState<string | null>(null);
  const { register, handleSubmit, setError, formState } = useForm<LoginValues>({ resolver: zodResolver(loginSchema) });

  const verified = params.get("verified");

  async function onSubmit(values: LoginValues) {
    setFormError(null);
    try {
      const { data } = await api.post<{ data: Me }>("auth/login", values);
      queryClient.setQueryData(["me"], data);
      const fallback = data.memberships.length === 0 && data.platform_role ? "/admin" : "/app";
      router.replace(params.get("next") ? safeNext(params.get("next")) : fallback);
    } catch (error) {
      setFormError(applyApiErrors(error, setError));
    }
  }

  return (
    <AuthCard
      title="Sign in"
      subtitle="Welcome back. Sign in to manage your billing and collections."
      footer={
        <>
          New to the platform?{" "}
          <Link href="/register" className="font-medium text-brand-700 hover:underline">
            Create a company account
          </Link>
        </>
      }
    >
      {verified === "1" && <Alert tone="success" className="mb-4">Your email is verified. Please sign in.</Alert>}
      {verified === "invalid" && <Alert tone="danger" className="mb-4">This verification link is invalid or has expired.</Alert>}
      {formError && <Alert tone="danger" className="mb-4">{formError}</Alert>}
      <form onSubmit={handleSubmit(onSubmit)} noValidate className="space-y-4">
        <TextField label="Email" type="email" autoComplete="email" required error={formState.errors.email?.message} {...register("email")} />
        <TextField label="Password" type="password" autoComplete="current-password" required error={formState.errors.password?.message} {...register("password")} />
        <div className="flex items-center justify-between">
          <CheckboxField label="Keep me signed in" {...register("remember")} />
          <Link href="/forgot-password" className="text-sm font-medium text-brand-700 hover:underline">
            Forgot password?
          </Link>
        </div>
        <Button type="submit" size="lg" className="w-full" loading={formState.isSubmitting}>
          Sign in
        </Button>
      </form>
    </AuthCard>
  );
}
