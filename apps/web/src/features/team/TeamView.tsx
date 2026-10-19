"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useState, type FormEvent } from "react";
import { api, ApiError } from "@/lib/api/client";
import type { Invitation, Member, Role } from "@/lib/api/types";
import { formatDate } from "@/lib/dates";
import { useSession, useWriteAccess } from "@/lib/session/SessionProvider";
import { writeBlockedMessage } from "@/components/subscription/WriteGuard";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { ErrorState } from "@/components/ui/EmptyState";
import { TextField } from "@/components/ui/Field";
import { PageHeader } from "@/components/ui/PageHeader";
import { PageSpinner } from "@/components/ui/Spinner";

export const ROLE_LABELS: Record<Role, string> = {
  owner: "Business Owner",
  admin: "Company Admin",
  accounts_manager: "Accounts Manager",
  billing_executive: "Billing Executive",
  collection_executive: "Collection Executive",
  viewer: "Viewer",
};

export function TeamView() {
  const { tenantId, membership, me } = useSession();
  const queryClient = useQueryClient();
  const access = useWriteAccess("users.manage");
  const isOwner = membership?.roles.includes("owner") ?? false;
  const assignable = (Object.keys(ROLE_LABELS) as Role[]).filter((r) => r !== "owner" || isOwner);

  const members = useQuery({ queryKey: ["members", tenantId], queryFn: () => api.get<{ data: Member[] }>("members").then((r) => r.data), enabled: !!tenantId });
  const invitations = useQuery({ queryKey: ["invitations", tenantId], queryFn: () => api.get<{ data: Invitation[] }>("invitations").then((r) => r.data), enabled: !!tenantId });

  const [email, setEmail] = useState("");
  const [roles, setRoles] = useState<Role[]>(["billing_executive"]);
  const [message, setMessage] = useState<{ tone: "success" | "danger"; text: string } | null>(null);

  const invalidate = () => {
    void queryClient.invalidateQueries({ queryKey: ["members"] });
    void queryClient.invalidateQueries({ queryKey: ["invitations"] });
  };
  const onError = (e: unknown) => setMessage({ tone: "danger", text: e instanceof ApiError ? e.message : "Something went wrong." });

  const invite = useMutation({
    mutationFn: () => api.post("invitations", { email, roles }),
    onSuccess: () => { setEmail(""); setMessage({ tone: "success", text: `Invitation sent to ${email}.` }); invalidate(); },
    onError,
  });
  const updateMember = useMutation({ mutationFn: ({ id, body }: { id: string; body: Record<string, unknown> }) => api.patch(`members/${id}`, body), onSuccess: invalidate, onError });
  const revoke = useMutation({ mutationFn: (id: string) => api.delete(`invitations/${id}`), onSuccess: invalidate, onError });

  function submitInvite(event: FormEvent) {
    event.preventDefault();
    setMessage(null);
    if (roles.length === 0) return setMessage({ tone: "danger", text: "Choose at least one role." });
    invite.mutate();
  }

  return (
    <>
      <PageHeader title="Team members" description="Invite staff and choose what each person can do." />
      {!access.allowed && <Alert tone="warning" className="mb-4">{writeBlockedMessage(access.reason)}</Alert>}
      {message && <Alert tone={message.tone} className="mb-4">{message.text}</Alert>}

      <div className="grid gap-6 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader title="Members" />
          {members.isPending ? (
            <PageSpinner />
          ) : members.isError ? (
            <ErrorState message={members.error.message} onRetry={() => void members.refetch()} />
          ) : (
            <ul className="divide-y divide-border">
              {members.data.map((m) => {
                const self = m.user.id === me?.id;
                const locked = !access.allowed || self || (m.roles.includes("owner") && !isOwner);
                return (
                  <li key={m.id} className="space-y-3 px-5 py-4">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <div>
                        <p className="font-medium">
                          {m.user.name} {self && <span className="text-sm text-muted">(you)</span>}
                        </p>
                        <p className="text-sm text-muted">{m.user.email} · joined {formatDate(m.joined_at)}</p>
                      </div>
                      <Badge tone={m.status === "active" ? "success" : "neutral"}>{m.status}</Badge>
                    </div>
                    <fieldset disabled={locked} className="flex flex-wrap gap-2">
                      <legend className="sr-only">Roles for {m.user.name}</legend>
                      {assignable.map((role) => {
                        const checked = m.roles.includes(role);
                        return (
                          <label key={role} className="inline-flex items-center gap-1.5 rounded-full border border-border px-3 py-1 text-xs has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50">
                            <input
                              type="checkbox"
                              checked={checked}
                              onChange={() => {
                                const next = checked ? m.roles.filter((r) => r !== role) : [...m.roles, role];
                                if (next.length > 0) updateMember.mutate({ id: m.id, body: { roles: next } });
                              }}
                              className="size-3.5"
                            />
                            {ROLE_LABELS[role]}
                          </label>
                        );
                      })}
                    </fieldset>
                    {!locked && (
                      <Button variant="ghost" size="sm" onClick={() => updateMember.mutate({ id: m.id, body: { status: m.status === "active" ? "disabled" : "active" } })}>
                        {m.status === "active" ? "Disable access" : "Re-enable access"}
                      </Button>
                    )}
                  </li>
                );
              })}
            </ul>
          )}
        </Card>

        <div className="space-y-6">
          <Card>
            <CardHeader title="Invite a team member" />
            <CardBody>
              <form onSubmit={submitInvite} className="space-y-4">
                <fieldset disabled={!access.allowed} className="space-y-4">
                  <TextField label="Email" type="email" required value={email} onChange={(e) => setEmail(e.target.value)} />
                  <div>
                    <p className="mb-2 text-sm font-medium">Roles</p>
                    <div className="space-y-1.5">
                      {assignable.map((role) => (
                        <label key={role} className="flex items-center gap-2 text-sm">
                          <input type="checkbox" checked={roles.includes(role)} onChange={() => setRoles((cur) => (cur.includes(role) ? cur.filter((r) => r !== role) : [...cur, role]))} className="size-4" />
                          {ROLE_LABELS[role]}
                        </label>
                      ))}
                    </div>
                  </div>
                </fieldset>
                <Button type="submit" className="w-full" disabled={!access.allowed} loading={invite.isPending}>
                  Send invitation
                </Button>
              </form>
            </CardBody>
          </Card>
          <Card>
            <CardHeader title="Pending invitations" />
            {invitations.isPending ? (
              <PageSpinner />
            ) : invitations.isError ? (
              <ErrorState message={invitations.error.message} />
            ) : invitations.data.length === 0 ? (
              <CardBody className="text-sm text-muted">No pending invitations.</CardBody>
            ) : (
              <ul className="divide-y divide-border">
                {invitations.data.map((i) => (
                  <li key={i.id} className="flex items-center justify-between gap-2 px-5 py-3 text-sm">
                    <div className="min-w-0">
                      <p className="truncate font-medium">{i.email}</p>
                      <p className="text-muted">Expires {formatDate(i.expires_at)}</p>
                    </div>
                    <Button variant="ghost" size="sm" disabled={!access.allowed} onClick={() => revoke.mutate(i.id)}>
                      Revoke
                    </Button>
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </div>
      </div>
    </>
  );
}
