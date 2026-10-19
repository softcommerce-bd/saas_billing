<?php

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Row in membership_roles (composite primary key; managed through TenantMembership::syncRoles()).
 */
class MembershipRole extends Model
{
    public const UPDATED_AT = null;

    public $incrementing = false;

    protected $primaryKey = null;

    protected $guarded = [];
}
