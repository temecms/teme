<?php

/**
 * The security event log: a telemetry sink, read by people, never by code
 * that decides anything (docs/architecture/operations.md). Typed addresses
 * are never stored — only a keyed hash, so two failures on one made-up
 * address still count together.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Security;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

final class SecurityLog
{
    /**
     * @param  array<string, scalar|list<scalar>|null>  $context
     */
    public function record(SecurityEventType $type, int|string|null $userId = null, ?string $email = null, array $context = []): void
    {
        $now = Date::now();
        $bindings = [
            'type' => $type->value,
            'subject' => self::subject($userId, $email),
            'user_id' => $userId === null ? null : (int) $userId,
            'ip' => (string) (request()->ip() ?? ''),
            'hour' => $now->startOfHour(),
            'context' => json_encode($context === [] ? new stdClass : $context, JSON_THROW_ON_ERROR),
            'first_at' => $now,
            'last_at' => $now,
        ];

        if (! $type->isCounted()) {
            DB::table('security_events')->insert([...$bindings, 'counted' => false, 'count' => 1]);

            return;
        }

        DB::statement(<<<'SQL'
            INSERT INTO security_events (type, subject, user_id, ip, hour, context, first_at, last_at, counted, count)
            VALUES (:type, :subject, :user_id, :ip, :hour, CAST(:context AS jsonb), :first_at, :last_at, true, 1)
            ON CONFLICT (type, subject, ip, hour) WHERE counted
            DO UPDATE SET count = security_events.count + 1, last_at = EXCLUDED.last_at
            SQL, $bindings);
    }

    public static function subject(int|string|null $userId, ?string $email): string
    {
        if ($userId !== null) {
            return 'user:'.$userId;
        }

        if ($email !== null && trim($email) !== '') {
            return 'email:'.substr(hash_hmac('sha256', Str::lower(trim($email)), (string) config('app.key')), 0, 32);
        }

        return 'none';
    }

    public function prune(int $days): int
    {
        return DB::table('security_events')->where('last_at', '<', Date::now()->subDays($days))->delete();
    }
}
