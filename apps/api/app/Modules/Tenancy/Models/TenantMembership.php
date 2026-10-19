<?php

namespace App\Modules\Tenancy\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Enums\Permission;
use App\Modules\Tenancy\Enums\Role;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class TenantMembership extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['joined_at' => 'datetime'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function roleRows(): HasMany
    {
        return $this->hasMany(MembershipRole::class, 'membership_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * @return list<Role>
     */
    public function roles(): array
    {
        return $this->roleRows
            ->map(fn (MembershipRole $row) => Role::from($row->role))
            ->sortBy(fn (Role $r) => array_search($r, Role::cases(), true))
            ->values()
            ->all();
    }

    public function hasRole(Role $role): bool
    {
        return in_array($role, $this->roles(), true);
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        return $this->isActive() ? Role::permissionsFor($this->roles()) : [];
    }

    public function can(Permission $permission): bool
    {
        return in_array($permission->value, $this->permissions(), true);
    }

    /**
     * @param  list<Role>  $roles
     */
    public function syncRoles(array $roles): void
    {
        DB::transaction(function () use ($roles) {
            $this->roleRows()->delete();
            foreach (array_unique(array_map(fn (Role $r) => $r->value, $roles)) as $role) {
                $this->roleRows()->create(['role' => $role]);
            }
        });

        $this->unsetRelation('roleRows');
    }
}
