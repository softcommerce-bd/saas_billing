<?php

namespace App\Modules\Tenancy\Enums;

use App\Modules\Tenancy\Enums\Permission as P;

enum Role: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case AccountsManager = 'accounts_manager';
    case BillingExecutive = 'billing_executive';
    case CollectionExecutive = 'collection_executive';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Business Owner',
            self::Admin => 'Company Admin',
            self::AccountsManager => 'Accounts Manager',
            self::BillingExecutive => 'Billing Executive',
            self::CollectionExecutive => 'Collection Executive',
            self::Viewer => 'Viewer',
        };
    }

    /**
     * @return list<P>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => P::cases(),
            self::Admin => array_values(array_filter(
                P::cases(),
                fn (P $p) => $p !== P::SubscriptionManage,
            )),
            self::AccountsManager => [
                P::CustomersView, P::CustomersManage, P::CatalogView, P::CatalogManage,
                P::InvoicesView, P::InvoicesCreate, P::InvoicesEditDraft, P::InvoicesIssue, P::InvoicesVoid,
                P::PaymentsView, P::PaymentsRecord, P::PaymentsReverse, P::CreditNotesIssue,
                P::ReportsFinancial, P::DataExport, P::RemindersConfigure, P::RemindersSend,
                P::SubscriptionView,
            ],
            self::BillingExecutive => [
                P::CustomersView, P::CustomersManage, P::CatalogView,
                P::InvoicesView, P::InvoicesCreate, P::InvoicesEditDraft, P::InvoicesIssue,
                P::PaymentsView, P::RemindersSend,
            ],
            self::CollectionExecutive => [
                P::CustomersView, P::InvoicesView, P::PaymentsView, P::PaymentsRecord, P::RemindersSend,
            ],
            self::Viewer => [
                P::CustomersView, P::CatalogView, P::InvoicesView, P::PaymentsView,
            ],
        };
    }

    /**
     * Union of permissions for a set of roles.
     *
     * @param  iterable<Role>  $roles
     * @return list<string>
     */
    public static function permissionsFor(iterable $roles): array
    {
        $set = [];
        foreach ($roles as $role) {
            foreach ($role->permissions() as $permission) {
                $set[$permission->value] = true;
            }
        }
        ksort($set);

        return array_keys($set);
    }
}
