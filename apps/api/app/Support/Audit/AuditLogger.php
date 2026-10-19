<?php

namespace App\Support\Audit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

final class AuditLogger
{
    private const SENSITIVE_KEYS = ['password', 'token', 'secret', 'app_secret', 'id_token', 'refresh_token', 'authorization'];

    public function __construct(private readonly ?Request $request = null) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $action,
        ?Model $subject = null,
        array $metadata = [],
        ?string $tenantId = null,
        ?string $actorUserId = null,
        string $actorType = 'user',
    ): AuditEvent {
        $request = $this->request;
        $actorUserId ??= $request?->user()?->getAuthIdentifier();

        if ($actorUserId === null && $actorType === 'user') {
            $actorType = 'system';
        }

        return AuditEvent::create([
            'tenant_id' => $tenantId ?? ($subject?->getAttribute('tenant_id')),
            'actor_user_id' => $actorUserId,
            'actor_type' => $actorType,
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'metadata' => $metadata === [] ? null : self::scrub($metadata),
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? substr((string) $request->userAgent(), 0, 255) : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function scrub(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
                $data[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $data[$key] = self::scrub($value);
            }
        }

        return $data;
    }
}
