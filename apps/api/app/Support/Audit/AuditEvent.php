<?php

namespace App\Support\Audit;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Append-only audit record. The database trigger rejects UPDATE/DELETE as well.
 */
class AuditEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit events are immutable.'));
        static::deleting(fn () => throw new LogicException('Audit events are immutable.'));
    }
}
