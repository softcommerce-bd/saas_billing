"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { useState } from "react";
import { useForm } from "react-hook-form";
import { z } from "zod";
import { api } from "@/lib/api/client";
import { applyApiErrors } from "@/lib/forms";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { TextField } from "@/components/ui/Field";
import { AuthCard } from "./AuthCard";
import { forgotSchema, resetSchema } from "./schemas";

export function ForgotPasswordForm() {
  const [message, setMessage] = useState<string | null>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const { register, handleSubmit, setError, formState } = useForm<z.infer<typeof forgotSchema>>({ resolver: zodResolver(forgotSchema) });

  async function onSubmit(values: z.infer<typeof forgotSchema>) {
    setFormError(null);
    try {
      const { data } = await api.post<{ data: { message: string } }>("auth/forgot-password", values);
      setMessage(data.message);
    } catch (error) {
      setFormError(applyApiErrors(error, setError));
    }
  }

  return (
    <AuthCard title="Reset your password" subtitle="We'll email you a link to set a new password." footer={<Link href="/login" className="font-medium text-brand-700 hover:underline">Back to sign in</Link>}>
      {message ? (
        <Alert tone="success">{message}</Alert>
      ) : (
        <>
          {formError && <Alert tone="danger" className="mb-4">{formError}</Alert>}
          <form onSubmit={handleSubmit(onSubmit)} noValidate className="space-y-4">
            <TextField label="Email" type="email" autoComplete="email" required error={formState.errors.email?.message} {...register("email")} />
            <Button type="submit" size="lg" className="w-full" loading={formState.isSubmitting}>
              Send reset link
            </Button>
          </form>
        </>
      )}
    </AuthCard>
  );
}

export function ResetPasswordForm() {
  const params = useSearchParams();
  const [done, setDone] = useState<string | null>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const { register, handleSubmit, setError, formState } = useForm<z.infer<typeof resetSchema>>({ resolver: zodResolver(resetSchema) });
  const token = params.get("token");
  const email = params.get("email");

  async function onSubmit(values: z.infer<typeof resetSchema>) {
    setFormError(null);
    try {
      const { data } = await api.post<{ data: { message: string } }>("auth/reset-password", { ...values, token, email });
      setDone(data.message);
    } catch (error) {
      setFormError(applyApiErrors(error, setError));
    }
  }

  if (!token || !email) {
    return (
      <AuthCard title="Invalid link">
        <Alert tone="danger">This password reset link is incomplete. Please request a new one.</Alert>
      </AuthCard>
    );
  }

  return (
    <AuthCard title="Choose a new password" subtitle={email}>
      {done ? (
        <Alert tone="success" action={<Link href="/login" className="font-medium underline">Sign in</Link>}>
          {done}
        </Alert>
      ) : (
        <>
          {formError && <Alert tone="danger" className="mb-4">{formError}</Alert>}
          <form onSubmit={handleSubmit(onSubmit)} noValidate className="space-y-4">
            <TextField label="New password" type="password" autoComplete="new-password" required error={formState.errors.password?.message} {...register("password")} />
            <TextField label="Confirm new password" type="password" autoComplete="new-password" required error={formState.errors.password_confirmation?.message} {...register("password_confirmation")} />
            <Button type="submit" size="lg" className="w-full" loading={formState.isSubmitting}>
              Set new password
            </Button>
          </form>
        </>
      )}
    </AuthCard>
  );
}
