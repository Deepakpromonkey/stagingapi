<?php

namespace App\Pay;

/**
 * What a payment costs. The carrier always receives the load amount in
 * full; the platform fee and, for cards, the surcharge are added on top
 * and paid by the payer. Everything is in cents.
 */
final class Fees
{
    /**
     * @return array{amount_cents: int, platform_fee_cents: int, card_fee_cents: int, total_cents: int, platform_fee_pct: float, card_fee_pct: float}
     */
    public static function quote(int $amountCents, string $methodType, bool $guest = false): array
    {
        $platformPct = (float) config($guest ? 'dtpay.fees.guest_platform_pct' : 'dtpay.fees.platform_pct');
        $cardPct = $methodType === 'card' ? (float) config('dtpay.fees.card_pct') : 0.0;

        $platform = self::percentOf($amountCents, $platformPct);
        $card = self::percentOf($amountCents, $cardPct);

        return [
            'amount_cents' => $amountCents,
            'platform_fee_cents' => $platform,
            'card_fee_cents' => $card,
            'total_cents' => $amountCents + $platform + $card,
            'platform_fee_pct' => $platformPct,
            'card_fee_pct' => $cardPct,
        ];
    }

    /** Percent of an amount in cents, rounded half up to the cent. */
    public static function percentOf(int $cents, float $pct): int
    {
        // Basis points keep this exact: 1.2% of 100000c = 100000 * 120 / 10000.
        $bps = (int) round($pct * 100);

        return intdiv($cents * $bps + 5000, 10000);
    }

    public static function dollarsToCents(string|float|int $dollars): int
    {
        return (int) round(((float) $dollars) * 100);
    }
}
