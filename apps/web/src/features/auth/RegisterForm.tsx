"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useQueryClient } from "@tanstack/react-query";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { useForm } from "react-hook-form";
import { api } from "@/lib/api/client";
import type { Me } from "@/lib/api/types";
import { applyApiErrors } from "@/lib/forms";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { TextField } from "@/components/ui/Field";
import { AuthCard } from "./AuthCard";
import { registerSchema, type RegisterValues } from "./schemas";

export function RegisterForm() {
  const router = useRouter();
  const queryClient = useQueryClient();
  const [formError, setFormError] = useState<string | null>(null);
  const { register, handleSubmit, setError, formState } = useForm<RegisterValues>({ resolver: zodResolver(registerSchema) });

  async function onSubmit(values: RegisterValues) {
    setFormError(null);
    try {
      const { data } = await api.post<{ data: Me }>("auth/register", values);
      queryClient.setQueryData(["me"], data);
      router.replace("/app");
    } catch (error) {
      setFormError(applyApiErrors(error, setError));
    }
  }

  const errors = formState.errors;

  return (
    <AuthCard
      title="Create your company account"
      subtitle="Set up billing for your business in a few minutes."
      footer={
        <>
          Already have an account?{" "}
          <Link href="/login" className="font-medium text-brand-700 hover:underline">
            Sign in
          </Link>
        </>
      }
    >
      {formError && <Alert tone="danger" className="mb-4">{formError}</Alert>}
      <form onSubmit={handleSubmit(onSubmit)} noValidate className="space-y-4">
        <TextField label="Your name" autoComplete="name" required error={errors.name?.message} {...register("name")} />
        <TextField label="Company name" autoComplete="organization" required error={errors.company_name?.message} {...register("company_name")} />
        <TextField label="Work email" type="email" autoComplete="email" required error={errors.email?.message} {...register("email")} />
        <TextField label="Mobile (optional)" type="tel" autoComplete="tel" placeholder="01XXX-XXXXXX" error={errors.phone?.message} {...register("phone")} />
        <TextField label="Password" type="password" autoComplete="new-password" required hint="At least 10 characters with letters and numbers." error={errors.password?.message} {...register("password")} />
        <TextField label="Confirm password" type="password" autoComplete="new-password" required error={errors.password_confirmation?.message} {...register("password_confirmation")} />
        <Button type="submit" size="lg" className="w-full" loading={formState.isSubmitting}>
          Create account
        </Button>
      </form>
    </AuthCard>
  );
}
