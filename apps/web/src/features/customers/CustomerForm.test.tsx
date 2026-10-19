import { screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";
import { ApiError } from "@/lib/api/client";
import { expiredState, makeMembership, renderWithSession } from "@/test/session";
import { CustomerForm } from "./CustomerForm";
import { customerSchema, emptyCustomer, toCustomerPayload } from "./schema";

describe("customer schema", () => {
  it("requires a name and validates BD mobile and amounts", () => {
    const result = customerSchema.safeParse({ ...emptyCustomer, name: "", mobile: "0121234567", opening_balance: "1.234" });
    expect(result.success).toBe(false);
    const fields = result.error!.issues.map((i) => i.path[0]);
    expect(fields).toEqual(expect.arrayContaining(["name", "mobile", "opening_balance"]));
  });

  it("converts blanks to null and keeps money as strings", () => {
    const payload = toCustomerPayload({ ...emptyCustomer, name: "Karim", opening_balance: "1500.50", payment_terms_days: "15" });
    expect(payload).toMatchObject({ name: "Karim", email: null, mobile: null, opening_balance: "1500.50", credit_limit: null, payment_terms_days: 15 });
  });
});

describe("CustomerForm", () => {
  it("shows validation errors and does not submit", async () => {
    const onSubmit = vi.fn();
    renderWithSession(<CustomerForm submitLabel="Save customer" onSubmit={onSubmit} />, makeMembership(["customers.manage"]));

    await userEvent.type(screen.getByLabelText("Mobile number"), "12345");
    await userEvent.click(screen.getByRole("button", { name: "Save customer" }));

    expect(await screen.findByText("Enter the customer's name.")).toBeInTheDocument();
    expect(screen.getByText(/valid Bangladesh mobile number/)).toBeInTheDocument();
    expect(onSubmit).not.toHaveBeenCalled();
  });

  it("submits a normalised payload", async () => {
    const onSubmit = vi.fn().mockResolvedValue(undefined);
    renderWithSession(<CustomerForm submitLabel="Save customer" onSubmit={onSubmit} />, makeMembership(["customers.manage"]));

    await userEvent.type(screen.getByLabelText(/Customer name/), "Karim Store");
    await userEvent.type(screen.getByLabelText("Mobile number"), "01712-345678");
    await userEvent.click(screen.getByRole("button", { name: "Save customer" }));

    await waitFor(() => expect(onSubmit).toHaveBeenCalledOnce());
    expect(onSubmit.mock.calls[0][0]).toMatchObject({ name: "Karim Store", mobile: "01712-345678", type: "individual" });
  });

  it("maps server field errors onto inputs", async () => {
    const onSubmit = vi.fn().mockRejectedValue(new ApiError(422, "VALIDATION_FAILED", "Fix", { fields: { email: ["The email has already been taken."] } }));
    renderWithSession(<CustomerForm submitLabel="Save customer" onSubmit={onSubmit} />, makeMembership(["customers.manage"]));

    await userEvent.type(screen.getByLabelText(/Customer name/), "Karim");
    await userEvent.click(screen.getByRole("button", { name: "Save customer" }));

    expect(await screen.findByText("The email has already been taken.")).toBeInTheDocument();
  });

  it("is disabled in read-only mode", () => {
    renderWithSession(<CustomerForm submitLabel="Save customer" onSubmit={vi.fn()} />, makeMembership(["customers.manage"], expiredState));

    expect(screen.getByRole("button", { name: "Save customer" })).toBeDisabled();
    expect(screen.getByLabelText(/Customer name/)).toBeDisabled();
    expect(screen.getByText("Renew your subscription to use this action.")).toBeInTheDocument();
  });
});
