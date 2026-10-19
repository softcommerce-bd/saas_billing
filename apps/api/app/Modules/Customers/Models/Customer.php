<?php

namespace App\Modules\Customers\Models;

use App\Modules\Subscriptions\Services\GuardsBusinessWrites;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[UseFactory(CustomerFactory::class)]
class Customer extends Model
{
    use BelongsToTenant, GuardsBusinessWrites, HasFactory, HasUlids, SoftDeletes;

    protected $guarded = ['id', 'tenant_id', 'created_by_user_id'];

    protected function casts(): array
    {
        return [
            'opening_balance_minor' => 'integer',
            'credit_limit_minor' => 'integer',
            'payment_terms_days' => 'integer',
            'sms_opt_in' => 'boolean',
            'email_opt_in' => 'boolean',
        ];
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if ($term === null || trim($term) === '') {
            return $query;
        }

        $like = '%'.addcslashes(trim($term), '%_\\').'%';
        $digits = preg_replace('/\D/', '', $term);

        return $query->where(function (Builder $q) use ($like, $digits) {
            $q->where('name', 'ilike', $like)
                ->orWhere('company_name', 'ilike', $like)
                ->orWhere('email', 'ilike', $like);

            if (strlen($digits) >= 4) {
                $q->orWhere('mobile', 'like', '%'.ltrim($digits, '0').'%');
            }
        });
    }
}
