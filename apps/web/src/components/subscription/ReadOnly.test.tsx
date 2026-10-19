import { screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { Button } from "@/components/ui/Button";
import { activeState, expiredState, makeMembership, renderWithSession, suspendedState } from "@/test/session";
import { ReadOnlyBanner } from "./ReadOnlyBanner";
import { WriteGuard } from "./WriteGuard";

describe("ReadOnlyBanner", () => {
  it("renders nothing for an active subscription", () => {
    const { container } = renderWithSession(<ReadOnlyBanner state={activeState} canManageSubscription />, makeMembership([]));
    expect(container).toBeEmptyDOMElement();
  });

  it("shows the expiry message, date and renewal actions to the owner", () => {
    renderWithSession(<ReadOnlyBanner state={expiredState} canManageSubscription />, makeMembership([]));

    expect(screen.getByRole("heading", { name: "Your Subscription Has Expired" })).toBeInTheDocument();
    expect(
      screen.getByText("Your business information is safe and available in read-only mode. Renew your subscription to continue creating invoices, recording payments and managing collections."),
    ).toBeInTheDocument();
    expect(screen.getByText(/Expired after 31 Jan 2027/)).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Renew Subscription" })).toHaveAttribute("href", "/app/subscription#packages");
    expect(screen.getByRole("link", { name: "View Packages" })).toBeInTheDocument();
    expect(screen.getByText("Read-only")).toBeInTheDocument();
  });

  it("tells staff to ask the owner instead of offering checkout", () => {
    renderWithSession(<ReadOnlyBanner state={expiredState} canManageSubscription={false} />, makeMembership([]));

    expect(screen.queryByRole("link", { name: "Renew Subscription" })).not.toBeInTheDocument();
    expect(screen.getByText(/Ask your business owner/)).toBeInTheDocument();
  });

  it("explains suspension without renewal links", () => {
    renderWithSession(<ReadOnlyBanner state={suspendedState} canManageSubscription />, makeMembership([]));

    expect(screen.getByRole("heading", { name: "Your Company Account Is Suspended" })).toBeInTheDocument();
    expect(screen.queryByRole("link", { name: "Renew Subscription" })).not.toBeInTheDocument();
  });
});

describe("WriteGuard", () => {
  const action = <Button>Add customer</Button>;

  it("leaves the action enabled with permission and an active subscription", () => {
    renderWithSession(<WriteGuard permission="customers.manage">{action}</WriteGuard>, makeMembership(["customers.manage"]));
    expect(screen.getByRole("button", { name: "Add customer" })).toBeEnabled();
  });

  it("disables the action with an explanation in read-only mode", () => {
    renderWithSession(<WriteGuard permission="customers.manage">{action}</WriteGuard>, makeMembership(["customers.manage"], expiredState));

    const button = screen.getByRole("button", { name: "Add customer" });
    expect(button).toBeDisabled();
    expect(button).toHaveAttribute("title", "Renew your subscription to use this action.");
  });

  it("hides the action when the role lacks permission", () => {
    renderWithSession(<WriteGuard permission="customers.manage">{action}</WriteGuard>, makeMembership(["customers.view"]));
    expect(screen.queryByRole("button", { name: "Add customer" })).not.toBeInTheDocument();
  });

  it("disables rather than hides when suspended", () => {
    renderWithSession(<WriteGuard permission="customers.manage">{action}</WriteGuard>, makeMembership(["customers.manage"], suspendedState));
    expect(screen.getByRole("button", { name: "Add customer" })).toBeDisabled();
  });
});
