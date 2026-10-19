<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Http\Resources\TenantResource;
use App\Modules\Tenancy\Support\TenantContext;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\Request;

class CurrentTenantController extends Controller
{
    public function show(TenantContext $context): TenantResource
    {
        return new TenantResource($context->tenant());
    }

    public function update(Request $request, TenantContext $context, AuditLogger $audit): TenantResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:160'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:200'],
            'email' => ['sometimes', 'nullable', 'email', 'max:254'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'district' => ['sometimes', 'nullable', 'string', 'max:60'],
            'bin' => ['sometimes', 'nullable', 'string', 'max:32'],
            'tin' => ['sometimes', 'nullable', 'string', 'max:32'],
            'locale' => ['sometimes', 'in:bn,en'],
            'invoice_prefix' => ['sometimes', 'string', 'max:12', 'regex:/^[A-Za-z0-9\-\/]+$/'],
            'onboarding_completed' => ['sometimes', 'boolean'],
        ]);

        $tenant = $context->tenant();

        if (array_key_exists('onboarding_completed', $data)) {
            $tenant->onboarding_completed_at = $data['onboarding_completed'] ? ($tenant->onboarding_completed_at ?? now()) : null;
            unset($data['onboarding_completed']);
        }

        $tenant->fill($data);
        $changes = array_keys($tenant->getDirty());
        $tenant->save();

        $audit->record('tenant.settings_updated', $tenant, ['fields' => $changes], tenantId: $tenant->id);

        return new TenantResource($tenant);
    }
}
