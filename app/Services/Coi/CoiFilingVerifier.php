<?php

namespace App\Services\Coi;

use App\Models\CoiInsuranceRequest;
use Carbon\CarbonImmutable;

/**
 * The certificate against what FMCSA actually shows.
 *
 * A valid certificate is not the same thing as a cleared carrier. L&I lags
 * behind the paperwork by days, so an account that moved insurer last week
 * still shows the old one (16); a pending cancellation may already have been
 * rescinded, or may be the replacement of a policy cancelled at the insured's
 * own request (10). Both look identical on the certificate and mean opposite
 * things.
 *
 * This does not decide whether to haul. It records what the two sources say
 * and which of them disagree, so a broker holding a carrier can see why.
 */
class CoiFilingVerifier
{
    public function __construct(private readonly CarrierFilingLookup $filings) {}

    /**
     * @param  array<string, mixed>  $details  the reading of the agency's reply
     * @return array<string, mixed>
     */
    public function verify(CoiInsuranceRequest $request, array $details): array
    {
        $filings = $this->filings->activeFilings($request->dot_number);

        if ($filings === []) {
            return [
                'verdict' => 'not_checked',
                'reason' => 'No FMCSA filing was readable for this carrier.',
                'insurer_on_certificate' => $details['insurer'] ?? null,
                'insurer_on_file' => null,
                'checked_at' => now()->toIso8601String(),
            ];
        }

        $certificateInsurer = $this->normalise($details['insurer'] ?? null);
        $onFile = array_values(array_filter(array_map(
            fn ($filing) => $this->normalise($filing['insurer'] ?? null),
            $filings,
        )));

        $pendingCancellation = $this->pendingCancellation($filings);

        /*
         | The agency told us the account moved and the new form is filed. That
         | is the explanation for a mismatch, not a second problem — so it is
         | reported as a lag to re-check rather than as a carrier to hold.
         */
        $signals = is_array($details['signals'] ?? null) ? $details['signals'] : [];
        $agencyExplainedTheMove = (bool) array_intersect($signals, ['insurer_changed', 'filing_lag']);

        $insurerMatches = $certificateInsurer !== null
            && $this->anyMatch($certificateInsurer, $onFile);

        $verdict = match (true) {
            $pendingCancellation !== null && ! in_array('cancellation_rescinded', $signals, true)
                => 'pending_cancellation',
            $certificateInsurer === null => 'not_checked',
            $insurerMatches => 'matches',
            $agencyExplainedTheMove => 'filing_lag',
            default => 'insurer_mismatch',
        };

        return [
            'verdict' => $verdict,
            'reason' => $this->reason($verdict, $pendingCancellation),
            'insurer_on_certificate' => $details['insurer'] ?? null,
            'insurer_on_file' => $filings[0]['insurer'] ?? null,
            'policy_on_certificate' => $details['policy_number'] ?? null,
            'policy_on_file' => $filings[0]['policy_no'] ?? null,
            'pending_cancellation_on' => $pendingCancellation,
            'recheck_after' => in_array($verdict, ['filing_lag', 'insurer_mismatch'], true)
                ? now()->addWeekdays(5)->toDateString()
                : null,
            'checked_at' => now()->toIso8601String(),
        ];
    }

    /** The soonest future cancellation date across the filings, if any. */
    private function pendingCancellation(array $filings): ?string
    {
        $dates = [];

        foreach ($filings as $filing) {
            $cancels = $filing['cancels_on'] ?? null;

            if (! is_string($cancels) || $cancels === '') {
                continue;
            }

            try {
                $date = CarbonImmutable::parse($cancels);
            } catch (\Throwable) {
                continue;
            }

            if ($date->isFuture()) {
                $dates[] = $date->toDateString();
            }
        }

        sort($dates);

        return $dates[0] ?? null;
    }

    /**
     * Insurer names are written differently on a certificate than in a federal
     * filing — "Great Northern Casualty Co." against "GREAT NORTHERN CASUALTY
     * COMPANY". Comparing the significant words rather than the whole string
     * is the difference between a useful check and one that always disagrees.
     */
    private function normalise(?string $name): ?string
    {
        if (! is_string($name) || trim($name) === '') {
            return null;
        }

        $lower = strtolower($name);
        $lower = preg_replace('/[^a-z0-9 ]+/', ' ', $lower) ?? $lower;

        $noise = ['co', 'company', 'inc', 'incorporated', 'the', 'of', 'and',
            'insurance', 'ins', 'casualty', 'mutual', 'group', 'corp', 'llc', 'ltd'];

        $words = array_values(array_diff(
            array_filter(explode(' ', $lower), fn ($w) => $w !== ''),
            $noise,
        ));

        return $words === [] ? null : implode(' ', $words);
    }

    private function anyMatch(string $certificate, array $onFile): bool
    {
        foreach ($onFile as $filed) {
            if ($filed === $certificate
                || str_contains($filed, $certificate)
                || str_contains($certificate, $filed)) {
                return true;
            }
        }

        return false;
    }

    private function reason(string $verdict, ?string $pendingCancellation): string
    {
        return match ($verdict) {
            'matches' => 'The certificate insurer matches the FMCSA filing.',
            'filing_lag' => 'The agency says the account moved and the new form is filed; L&I has not caught up.',
            'insurer_mismatch' => 'The certificate names a different insurer than the FMCSA filing, unexplained.',
            'pending_cancellation' => 'FMCSA shows a cancellation pending on '.$pendingCancellation.'.',
            default => 'Not enough to compare.',
        };
    }
}
