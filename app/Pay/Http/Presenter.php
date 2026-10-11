<?php

namespace App\Pay\Http;

use App\Models\User;
use App\Pay\Models\Dispute;
use App\Pay\Models\Payment;
use App\Pay\Models\PayoutAccount;

/** The JSON shape of a DT Pay payment, shared by the broker and guest APIs. */
final class Presenter
{
    public const STATUS_LABELS = [
        Payment::DRAFT => 'Awaiting payment',
        Payment::PROCESSING => 'Processing',
        Payment::FUNDED => 'Funded · held',
        Payment::RELEASING => 'Releasing',
        Payment::RELEASED => 'Released',
        Payment::REFUNDING => 'Refunding',
        Payment::REFUNDED => 'Refunded',
        Payment::FAILED => 'Failed',
        Payment::CANCELLED => 'Cancelled',
    ];

    public static function summary(Payment $p): array
    {
        return [
            'id' => $p->uuid,
            'reference' => $p->reference,
            'source' => $p->source,
            'status' => $p->status,
            'status_label' => self::STATUS_LABELS[$p->status] ?? $p->status,
            'on_hold' => $p->on_hold,
            'carrier' => ['dot' => $p->carrier_dot, 'name' => $p->carrier_name, 'mc' => $p->carrier_mc],
            'load_number' => $p->load_number,
            'origin' => $p->origin,
            'destination' => $p->destination,
            'amount' => self::money($p->amount_cents),
            'total' => self::money($p->total_cents),
            'method_label' => $p->method_label,
            'created_at' => $p->created_at?->toIso8601String(),
            'funded_at' => $p->funded_at?->toIso8601String(),
            'released_at' => $p->released_at?->toIso8601String(),
        ];
    }

    /** Full detail, with what this viewer may do next. */
    public static function detail(Payment $p, ?User $user, ?PayoutAccount $payout, bool $guest = false): array
    {
        $open = $p->openDispute();

        return self::summary($p) + [
            'payer' => $p->source === Payment::SOURCE_GUEST ? [
                'name' => $p->payer_name,
                'company' => $p->payer_company,
                'email' => $p->payer_email,
                'phone' => $p->payer_phone,
            ] : null,
            'pickup_date' => $p->pickup_date?->format('Y-m-d'),
            'delivery_date' => $p->delivery_date?->format('Y-m-d'),
            'memo' => $p->memo,
            'livemode' => $p->livemode,
            'breakdown' => [
                'amount' => self::money($p->amount_cents),
                'platform_fee' => self::money($p->platform_fee_cents) + ['pct' => $p->platform_fee_pct],
                'platform_fee_pct' => $p->platform_fee_pct,
                'card_fee' => self::money($p->card_fee_cents) + ['pct' => $p->card_fee_cents > 0 ? (float) config('dtpay.fees.card_pct') : 0],
                'total' => self::money($p->total_cents),
                'carrier_receives' => self::money($p->amount_cents),
            ],
            'method_type' => $p->method_type,
            'failure_reason' => $p->failure_reason,
            'documents' => [
                'rate_confirmation' => (bool) $p->rate_confirmation_path,
                'pod' => (bool) $p->pod_path,
            ],
            'payout' => [
                'ready' => (bool) $payout?->ready(),
                'started' => (bool) $payout,
            ],
            'open_dispute' => $open ? self::dispute($open) : null,
            'disputes' => $p->disputes->map(fn (Dispute $d) => self::dispute($d))->values(),
            'timeline' => $p->events->map(fn ($e) => [
                'type' => $e->type,
                'label' => $e->label,
                'detail' => $e->detail,
                'actor' => $e->actor_type,
                'at' => $e->created_at?->toIso8601String(),
            ])->values(),
            'can' => $guest ? self::guestCan($p, $open !== null, $payout) : self::brokerCan($p, $user, $open !== null, $payout),
        ];
    }

    public static function dispute(Dispute $d): array
    {
        return [
            'id' => $d->id,
            'reason' => $d->reason,
            'reason_label' => Dispute::REASONS[$d->reason] ?? $d->reason,
            'details' => $d->details,
            'status' => $d->status,
            'resolution' => $d->resolution,
            'evidence_count' => count($d->evidence_paths ?? []),
            'raised_by' => $d->raised_by_type,
            'created_at' => $d->created_at?->toIso8601String(),
            'resolved_at' => $d->resolved_at?->toIso8601String(),
        ];
    }

    public static function money(int $cents): array
    {
        return ['cents' => $cents, 'formatted' => '$'.number_format($cents / 100, 2)];
    }

    private static function brokerCan(Payment $p, ?User $user, bool $disputed, ?PayoutAccount $payout): array
    {
        $create = Permissions::can($user, 'create-fund-payment');
        $refund = Permissions::can($user, 'refund-cancel-payment');
        $held = in_array($p->status, [Payment::PROCESSING, Payment::FUNDED], true);

        return [
            'pay' => $create && $p->payable(),
            'cancel' => $refund && $p->payable(),
            'release' => $p->status === Payment::FUNDED && ! $p->on_hold && ! $disputed && (bool) $payout?->ready() && Permissions::canRelease($user, $p),
            'release_blocked_reason' => self::releaseBlocker($p, $disputed, $payout),
            'refund' => $refund && $p->status === Payment::FUNDED,
            'hold' => $create && $held && ! $p->on_hold,
            'unhold' => $create && $held && $p->on_hold,
            'dispute' => $create && $held && ! $disputed,
            'pod' => $create && ! in_array($p->status, [Payment::CANCELLED, Payment::REFUNDED], true),
        ];
    }

    private static function guestCan(Payment $p, bool $disputed, ?PayoutAccount $payout): array
    {
        $held = in_array($p->status, [Payment::PROCESSING, Payment::FUNDED], true);

        return [
            'pay' => $p->payable(),
            'release' => $p->status === Payment::FUNDED && ! $p->on_hold && ! $disputed && (bool) $payout?->ready(),
            'dispute' => $held && ! $disputed,
        ];
    }

    private static function releaseBlocker(Payment $p, bool $disputed, ?PayoutAccount $payout): ?string
    {
        return match (true) {
            $p->status === Payment::PROCESSING => 'Waiting for the funds to clear.',
            $p->status !== Payment::FUNDED => null,
            $p->on_hold => 'On hold.',
            $disputed => 'Open dispute.',
            ! $payout || ! $payout->ready() => 'The carrier has not finished payout setup.',
            default => null,
        };
    }
}
