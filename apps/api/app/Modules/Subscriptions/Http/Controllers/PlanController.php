<?php

namespace App\Modules\Subscriptions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Subscriptions\Http\Resources\PlanResource;
use App\Modules\Subscriptions\Models\Plan;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PlanController extends Controller
{
    /**
     * Public list of purchasable packages (pricing page, onboarding, renewal).
     */
    public function index(): AnonymousResourceCollection
    {
        return PlanResource::collection(
            Plan::query()->where('is_active', true)->with('entitlementProfile')->orderBy('sort_order')->get(),
        );
    }
}
