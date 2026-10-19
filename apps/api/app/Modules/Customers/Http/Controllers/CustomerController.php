<?php

namespace App\Modules\Customers\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Customers\Http\Requests\CustomerRequest;
use App\Modules\Customers\Http\Resources\CustomerResource;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Support\BangladeshPhone;
use App\Modules\Tenancy\Support\TenantContext;
use App\Support\Audit\AuditLogger;
use App\Support\Money\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,inactive'],
            'type' => ['nullable', 'in:individual,business'],
            'district' => ['nullable', 'string', 'max:60'],
            'sort' => ['nullable', 'in:name,-name,created_at,-created_at'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $sort = $request->query('sort', 'name');
        $query = Customer::query()
            ->search($request->query('search'))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('type'), fn ($q, $v) => $q->where('type', $v))
            ->when($request->query('district'), fn ($q, $v) => $q->where('district', $v))
            ->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->orderBy('id');

        return CustomerResource::collection($query->paginate((int) $request->query('per_page', 25))->withQueryString());
    }

    public function store(CustomerRequest $request, AuditLogger $audit): JsonResponse
    {
        $customer = new Customer($this->attributes($request));
        $customer->created_by_user_id = $request->user()->id;
        $customer->save();

        $audit->record('customer.created', $customer);

        return (new CustomerResource($customer->refresh()))->response()->setStatusCode(201);
    }

    public function show(Customer $customer): CustomerResource
    {
        return new CustomerResource($customer);
    }

    public function update(CustomerRequest $request, Customer $customer, AuditLogger $audit): CustomerResource
    {
        $customer->fill($this->attributes($request));
        $changed = array_keys($customer->getDirty());
        $customer->save();

        $audit->record('customer.updated', $customer, ['fields' => $changed]);

        return new CustomerResource($customer);
    }

    /**
     * Archives (soft-deletes) the customer. Once invoices exist this will be blocked
     * in favour of marking the customer inactive.
     */
    public function destroy(Customer $customer, AuditLogger $audit): JsonResponse
    {
        $customer->delete();
        $audit->record('customer.archived', $customer);

        return new JsonResponse(null, 204);
    }

    public function export(Request $request, TenantContext $context, AuditLogger $audit): StreamedResponse
    {
        $audit->record('customer.exported', null, ['search' => $request->query('search')], tenantId: $context->tenantId());

        // The stream runs after the middleware stack has returned, so pin the tenant explicitly.
        $query = Customer::acrossTenants()
            ->where('tenant_id', $context->tenantId())
            ->search($request->query('search'))
            ->orderBy('name')->orderBy('id');

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows Bangla text correctly.
            fputcsv($out, ['Name', 'Company', 'Type', 'Mobile', 'Email', 'District', 'Billing address', 'Opening balance', 'Status']);

            $query->chunkById(500, function ($customers) use ($out) {
                foreach ($customers as $c) {
                    fputcsv($out, array_map([self::class, 'csvSafe'], [
                        $c->name, $c->company_name, $c->type, BangladeshPhone::display($c->mobile), $c->email,
                        $c->district, $c->billing_address, Money::toDecimalString($c->opening_balance_minor), $c->status,
                    ]));
                }
            });

            fclose($out);
        }, 'customers-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Neutralise spreadsheet formula injection in exported cells.
     */
    public static function csvSafe(?string $value): string
    {
        $value ??= '';

        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(CustomerRequest $request): array
    {
        $data = $request->validated();

        if (array_key_exists('mobile', $data)) {
            $data['mobile'] = BangladeshPhone::normalizeMobile($data['mobile']);
        }
        if (array_key_exists('opening_balance', $data)) {
            $data['opening_balance_minor'] = Money::parseToMinor($data['opening_balance'] ?? '0');
            unset($data['opening_balance']);
        }
        if (array_key_exists('credit_limit', $data)) {
            $data['credit_limit_minor'] = $data['credit_limit'] === null ? null : Money::parseToMinor($data['credit_limit']);
            unset($data['credit_limit']);
        }
        if (isset($data['email'])) {
            $data['email'] = mb_strtolower(trim($data['email']));
        }

        return $data;
    }
}
