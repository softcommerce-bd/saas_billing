"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useQueryClient } from "@tanstack/react-query";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { useState } from "react";
import { useForm } from "react-hook-form";
import { z } from "zod";
import { api } from "@/lib/api/client";
import type { Me } from "@/lib/api/types";
import { applyApiErrors } from "@/lib/forms";
import { useSession } from "@/lib/session/SessionProvider";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { TextField } from "@/components/ui/Field";
import { PageSpinner } from "@/components/ui/Spinner";
import { AuthCard } from "./AuthCard";
import { invitedRegisterSchema } from "./schemas";

type Values = z.infer<typeof invitedRegisterSchema>;

export function AcceptInvitation() {
  const params = useSearchParams();
  const router = useRouter();
  const queryClient = useQueryClient();
  const { me, isLoading } = useSession();
  const [formError, setFormError] = useState<string | null>(null);
  const [accepting, setAccepting] = useState(false);
  const token = params.get("token") ?? "";
  const { register, handleSubmit, setError, formState } = useForm<Values>({ resolver: zodResolver(invitedRegisterSchema) });

  if (!token) {
    return (
      <AuthCard title="Invalid invitation">
        <Alert tone="danger">This invitation link is incomplete.</Alert>
      </AuthCard>
    );
  }

  if (isLoading) return <PageSpinner />;

  async function finish(request: Promise<{ data: Me }>) {
    setFormError(null);
    try {
      const { data } = await request;
      queryClient.setQueryData(["me"], data);
      router.replace("/app");
    } catch (error) {
      setFormError(applyApiErrors(error, setError));
    }
  }

  if (me) {
    return (
      <AuthCard title="Join company" subtitle={`Signed in as ${me.email}`}>
        {formError && <Alert tone="danger" className="mb-4">{formError}</Alert>}
        <Button
          size="lg"
          className="w-full"
          loading={accepting}
          onClick={async () => {
            setAccepting(true);
            await finish(api.post<{ data: Me }>("invitations/accept", { token }));
            setAccepting(false);
          }}
        >
          Accept invitation
        </Button>
      </AuthCard>
    );
  }

  return (
    <AuthCard
      title="Accept your invitation"
      subtitle="Create your account to join the company."
      footer={
        <>
          Already have an account?{" "}
          <Link href={`/login?next=${encodeURIComponent(`/accept-invitation?token=${token}`)}`} className="font-medium text-brand-700 hover:underline">
            Sign in to accept
          </Link>
        </>
      }
    >
      {formError && <Alert tone="danger" className="mb-4">{formError}</Alert>}
      <form onSubmit={handleSubmit((values) => finish(api.post<{ data: Me }>("invitations/register", { ...values, token })))} noValidate className="space-y-4">
        <TextField label="Your name" autoComplete="name" required error={formState.errors.name?.message} {...register("name")} />
        <TextField label="Password" type="password" autoComplete="new-password" required error={formState.errors.password?.message} {...register("password")} />
        <TextField label="Confirm password" type="password" autoComplete="new-password" required error={formState.errors.password_confirmation?.message} {...register("password_confirmation")} />
        <Button type="submit" size="lg" className="w-full" loading={formState.isSubmitting}>
          Create account and join
        </Button>
      </form>
    </AuthCard>
  );
}
