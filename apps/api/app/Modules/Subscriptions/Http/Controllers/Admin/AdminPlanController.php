<?php

namespace App\Modules\Subscriptions\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Subscriptions\Http\Resources\PlanResource;
use App\Modules\Subscriptions\Models\Plan;
use App\Support\Audit\AuditLogger;
use App\Support\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class AdminPlanController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return PlanResource::collection(Plan::query()->with('entitlementProfile')->orderBy('sort_order')->get());
    }

    /**
     * Change price/name/availability. Existing orders keep their price snapshot.
     */
    public function update(Request $request, Plan $plan, AuditLogger $audit): PlanResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'price' => ['sometimes', 'string', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('price', $data)) {
            $data['price_minor'] = Money::parseToMinor($data['price']);
            unset($data['price']);
            if ($data['price_minor'] <= 0) {
                throw ValidationException::withMessages(['price' => 'Price must be greater than zero.']);
            }
        }

        $before = $plan->only(array_keys($data));
        $plan->update($data);

        $audit->record('plan.updated', $plan, ['before' => $before, 'after' => $plan->only(array_keys($data))], actorType: 'platform');

        return new PlanResource($plan->load('entitlementProfile'));
    }
}
