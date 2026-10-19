<?php

namespace App\Modules\Tenancy\Enums;

/**
 * Company-level permissions. Enforced by policies on the server; the frontend only
 * uses them to decide which controls to show.
 */
enum Permission: string
{
    case CustomersView = 'customers.view';
    case CustomersManage = 'customers.manage';
    case CatalogView = 'catalog.view';
    case CatalogManage = 'catalog.manage';
    case InvoicesView = 'invoices.view';
    case InvoicesCreate = 'invoices.create';
    case InvoicesEditDraft = 'invoices.edit_draft';
    case InvoicesIssue = 'invoices.issue';
    case InvoicesVoid = 'invoices.void';
    case PaymentsView = 'payments.view';
    case PaymentsRecord = 'payments.record';
    case PaymentsReverse = 'payments.reverse';
    case CreditNotesIssue = 'credit_notes.issue';
    case ReportsFinancial = 'reports.financial';
    case DataExport = 'data.export';
    case RemindersConfigure = 'reminders.configure';
    case RemindersSend = 'reminders.send';
    case UsersManage = 'users.manage';
    case SettingsManage = 'settings.manage';
    case SubscriptionView = 'subscription.view';
    case SubscriptionManage = 'subscription.manage';
    case AuditView = 'audit.view';
}
