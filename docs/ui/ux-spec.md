# UI/UX specification (I, J)

## Visual identity
Deep navy structure (`navy-900 #0f1e3d`), teal primary actions (`brand-600 #0d8a76`), neutral
canvas `#f5f7fa`, explicit status colours (success/warning/danger/info). Inter for Latin,
Noto Sans Bengali for Bangla (`:lang(bn)`). Minimal decoration, no gradients or heavy animation.
Tokens: `apps/web/src/app/globals.css` (`@theme`).

## Components (design system seed)
`components/ui`: Button (primary/secondary/ghost/danger, loading), TextField/SelectField/
TextAreaField/CheckboxField (label, required marker hidden from AT, error + hint wired via
`aria-describedby`), Card/CardHeader/CardBody, Badge, Alert, EmptyState/ErrorState,
PageHeader, Pagination, Spinner/PageSpinner.
`components/subscription`: ReadOnlyBanner, ReadOnlyIndicator, WriteGuard, SubscriptionBadge.
`components/layout/AppShell`: collapsible sidebar, mobile drawer, topbar with company
switcher, subscription badge, customer search, quick create, account menu.

Every data view has loading, empty, error (with retry) states.

## Screen inventory

| Area | Screen | Status |
|---|---|---|
| Public | Landing, Features (honest availability labels), Pricing (live prices) | Built |
| Public | Login, Registration, Forgot/Reset password, Accept invitation | Built |
| Public | Email verification (signed link → login notice; in-app resend) | Built |
| Onboarding | Company info, logo, tax settings, prefix, first customer, plan | Partial (settings page; guided wizard planned; logo upload planned) |
| Business | Dashboard (real counts, subscription card, "coming next") | Built (financial KPIs planned) |
| Business | Customers list/detail/create/edit, CSV export | Built |
| Business | Products & services list/create/edit | Built |
| Business | Team members & invitations | Built |
| Business | Subscription & billing, payment result | Built |
| Business | Company settings | Built |
| Business | Invoices (list, builder, preview, detail), Payments, Due collections, Today queue, Reminder center, Reports | Planned |
| Admin | Tenants (suspend, extend), Subscription payments (review, re-check), Plans & prices | Built (basic) |
| Admin | Messaging usage, provider settings, support, system logs, platform KPIs (MRR etc.) | Planned |

Navigation shows only built modules; there are no dead links or fake buttons.

## Read-only experience
Banner title "Your Subscription Has Expired" with the mandated copy, expiry date, Read-only
indicator, Renew Subscription and View Packages (owner) / "Ask your business owner" (staff).
Write actions disabled with a tooltip reason; forms locked; data and navigation unchanged.

## Invoice builder UX (Phase 4 spec)
Keyboard-first line entry, customer typeahead with inline create, catalog autocomplete with
free-text items, live totals computed client-side for display only (server recalculates),
draft autosave (debounced PATCH of drafts only), side-by-side preview on desktop, stacked on
mobile, sticky "Issue" bar on mobile.

## Accessibility
Semantic landmarks, labelled controls, focus-visible outlines, `aria-current` in nav,
`role=alert` for errors, 44 px tap targets in navigation. Target WCAG 2.1 AA; audit pending.
