"use client";

import { useRouter } from "next/navigation";
import { Card, CardBody } from "@/components/ui/Card";
import { PageHeader } from "@/components/ui/PageHeader";
import { useSaveCustomer } from "./api";
import { CustomerForm } from "./CustomerForm";

export function CustomerCreateView() {
  const router = useRouter();
  const save = useSaveCustomer();

  return (
    <>
      <PageHeader title="Add customer" description="Only the name is required. You can add details later." />
      <Card>
        <CardBody>
          <CustomerForm
            submitLabel="Save customer"
            onSubmit={async (payload) => {
              const customer = await save.mutateAsync(payload);
              router.replace(`/app/customers/${customer.id}`);
            }}
          />
        </CardBody>
      </Card>
    </>
  );
}
