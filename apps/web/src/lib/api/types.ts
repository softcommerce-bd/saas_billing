export type AccessMode = "full" | "read_only" | "suspended";

export type EntitlementState = {
  mode: AccessMode;
  can_write: boolean;
  reason: "expired" | "pending_payment" | "cancelled" | "suspended" | null;
  expires_at: string | null;
  valid_through: string | null;
  is_trial: boolean;
  plan_code: PlanCode | null;
  override_active: boolean;
  override_expires_at: string | null;
  can_renew: boolean;
};

export type Role = "owner" | "admin" | "accounts_manager" | "billing_executive" | "collection_executive" | "viewer";

export type Permission =
  | "customers.view" | "customers.manage" | "catalog.view" | "catalog.manage"
  | "invoices.view" | "invoices.create" | "invoices.edit_draft" | "invoices.issue" | "invoices.void"
  | "payments.view" | "payments.record" | "payments.reverse" | "credit_notes.issue"
  | "reports.financial" | "data.export" | "reminders.configure" | "reminders.send"
  | "users.manage" | "settings.manage" | "subscription.view" | "subscription.manage" | "audit.view";

export type Membership = {
  tenant: { id: string; name: string; slug: string; currency: string; timezone: string; onboarding_completed: boolean };
  roles: Role[];
  permissions: Permission[];
  subscription: EntitlementState;
};

export type Me = {
  id: string;
  name: string;
  email: string;
  email_verified: boolean;
  locale: string;
  platform_role: "super_admin" | "support_admin" | null;
  memberships: Membership[];
};

export type Paginated<T> = {
  data: T[];
  meta: { current_page: number; last_page: number; per_page: number; total: number };
};

export type Customer = {
  id: string;
  type: "individual" | "business";
  name: string;
  company_name: string | null;
  mobile: string | null;
  mobile_display: string | null;
  secondary_phone: string | null;
  email: string | null;
  billing_address: string | null;
  shipping_address: string | null;
  district: string | null;
  business_info: string | null;
  bin: string | null;
  tin: string | null;
  opening_balance_minor: number;
  opening_balance: string;
  credit_limit_minor: number | null;
  payment_terms_days: number | null;
  notes: string | null;
  status: "active" | "inactive";
  preferred_language: "bn" | "en";
  sms_opt_in: boolean;
  email_opt_in: boolean;
  created_at: string;
  updated_at: string;
};

export type CatalogItem = {
  id: string;
  type: "product" | "service";
  name: string;
  description: string | null;
  sku: string | null;
  category: string | null;
  unit: string;
  price_minor: number;
  price: string;
  default_discount_bp: number;
  tax_rate_bp: number | null;
  tax_label: string | null;
  is_active: boolean;
  created_at: string;
  updated_at: string;
};

export type PlanCode = "monthly" | "quarterly" | "half_yearly" | "yearly";

export type Plan = {
  id: string;
  code: PlanCode;
  name: string;
  description: string | null;
  duration_months: number;
  price_minor: number;
  price: string;
  monthly_equivalent_minor: number;
  currency: string;
  is_active: boolean;
  entitlements: {
    max_users: number | null;
    max_invoices_per_month: number | null;
    sms_monthly_allowance: number;
    storage_mb: number;
    modules: string[];
  } | null;
};

export type SubscriptionDetails = {
  status: "pending_payment" | "active" | "expired" | "cancelled" | "suspended" | null;
  plan: Plan | null;
  starts_at: string | null;
  expires_at: string | null;
  activated_at: string | null;
  renewed_at: string | null;
  amount_minor: number | null;
  currency: string | null;
  entitlement: EntitlementState;
  periods: Array<{
    id: string;
    source: "payment" | "trial" | "complimentary";
    months: number | null;
    days: number | null;
    period_start: string;
    period_end: string;
    reason: string | null;
    revoked: boolean;
  }>;
};

export type SubscriptionPayment = {
  id: string;
  order_id: string;
  gateway: string;
  environment: string;
  status: "initiated" | "completed" | "failed" | "cancelled" | "expired" | "unknown" | "refunded" | "partially_refunded";
  amount_minor: number;
  currency: string;
  transaction_id: string | null;
  verified_at: string | null;
  applied: boolean;
  refunded_amount_minor: number;
  requires_review: boolean;
  failure_code: string | null;
  plan_code?: PlanCode;
  merchant_reference?: string;
  created_at: string;
  tenant_id?: string;
  failure_message?: string | null;
};

export type Member = {
  id: string;
  user: { id: string; name: string; email: string };
  roles: Role[];
  status: "active" | "disabled";
  joined_at: string | null;
};

export type Invitation = { id: string; email: string; roles: Role[]; expires_at: string };

export type Company = {
  id: string;
  name: string;
  slug: string;
  legal_name: string | null;
  email: string | null;
  phone: string | null;
  address: string | null;
  district: string | null;
  bin: string | null;
  tin: string | null;
  currency: string;
  timezone: string;
  locale: "bn" | "en";
  invoice_prefix: string;
  onboarding_completed: boolean;
  created_at: string;
};

export type AdminTenant = {
  id: string;
  name: string;
  slug: string;
  created_at: string;
  suspended_at: string | null;
  subscription: { status: string | null; plan_code: PlanCode | null; expires_at: string | null; is_trial: boolean };
  entitlement: EntitlementState;
};
