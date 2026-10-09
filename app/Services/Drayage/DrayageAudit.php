<?php

namespace App\Services\Drayage;

use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * The drayage audit trail: every import, activation, deletion and export.
 *
 * Written twice - to audit.jsonl in the drayage store, which travels with
 * the data, and to the `drayage` log channel, which travels with the rest of
 * the application's logs. Neither is MySQL.
 *
 * Contact details never appear in full. Filters can carry them (a search
 * for an email address or a phone number), so every string is scrubbed
 * before it is written.
 */
class DrayageAudit
{
    public function __construct(private DrayageStorage $storage) {}

    /**
     * @param  array{dataset_id?: string|null, filters?: array, row_count?: int}  $details
     */
    public function record(string $action, ?array $actor, ?string $ip, array $details = []): void
    {
        $entry = [
            'at' => now()->toIso8601String(),
            'action' => $action,
            'actor' => $actor,
            'ip' => $ip,
        ] + self::scrub($details);

        try {
            $this->storage->appendAudit($entry);
        } catch (\Throwable $e) {
            Log::channel('drayage')->error('Drayage audit file write failed', ['error' => $e->getMessage()]);
        }

        Log::channel('drayage')->info('drayage.'.$action, $entry);
    }

    /**
     * Who did it, without their email address.
     */
    public static function actor(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'type' => 'user',
            'id' => $user->id,
            'uuid' => $user->uuid,
            'name' => trim($user->first_name.' '.$user->last_name),
            'company_id' => $user->company_id,
        ];
    }

    public static function console(string $command): array
    {
        return ['type' => 'console', 'command' => $command, 'os_user' => get_current_user()];
    }

    /**
     * Masks email addresses and phone numbers anywhere in a value.
     */
    public static function scrub(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map([self::class, 'scrub'], $value);
        }

        if (! is_string($value)) {
            return $value;
        }

        $value = preg_replace_callback(
            '/([A-Za-z0-9._%+-])[A-Za-z0-9._%+-]*@([A-Za-z0-9.-]+\.[A-Za-z]{2,})/',
            fn ($m) => $m[1].'***@'.$m[2],
            $value
        );

        return preg_replace_callback(
            '/(?:\+?1[\s.-]?)?\(?\d{3}\)?[\s.-]?\d{3}[\s.-]?(\d{4})\b/',
            fn ($m) => '***-***-**'.substr($m[1], -2),
            $value
        );
    }
}
