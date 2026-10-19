<?php

namespace App\Modules\Subscriptions\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionReminder extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['expires_at_ref' => 'immutable_datetime'];
    }
}
