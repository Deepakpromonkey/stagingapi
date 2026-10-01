<?php

namespace App\Services\Coi;

/**
 * Whether a certificate can be believed.
 *
 * Two sequences turn on it and both are quiet failures — the certificate
 * parses perfectly and states a real date, so every other check passes.
 *
 * 14: the carrier sends its own certificate. The producer block carries a
 * free-mail address and the carrier's own phone number, and the agency, asked
 * at its published address rather than the one printed on the PDF, says it
 * never issued the thing.
 *
 * 15: the same certificate number and issue date as the agency's, with the
 * cargo limit changed from $100,000 to $250,000 on the carrier's copy.
 *
 * The agency's own words are the strongest evidence available here, so the
 * signals read out of the reply do most of the work. The sender's domain is
 * the part the model cannot judge, and it is checked here.
 */
class CoiTrustCheck
{
    /**
     * Domains no insurance agency issues certificates from. A producer block
     * quoting one is not proof of anything on its own — small agencies do use
     * them — but it is the single most common tell on a self-issued
     * certificate, and it belongs in front of a broker.
     */
    private const FREE_MAIL = [
        'gmail.com', 'googlemail.com', 'yahoo.com', 'ymail.com', 'hotmail.com',
        'outlook.com', 'live.com', 'aol.com', 'icloud.com', 'me.com',
        'protonmail.com', 'proton.me', 'mail.com', 'gmx.com', 'yandex.com',
    ];

    /**
     * @param  array<string, mixed>  $details  the reading of the agency's reply
     * @return array<string, mixed>
     */
    public function check(?string $fromEmail, array $details): array
    {
        $signals = is_array($details['signals'] ?? null) ? $details['signals'] : [];
        $domain = $this->domainOf($fromEmail);
        $freeMail = $domain !== null && in_array($domain, self::FREE_MAIL, true);

        $flags = [];

        if (in_array('certificate_disowned', $signals, true)) {
            $flags[] = [
                'code' => 'disowned',
                'severity' => 'stop',
                'message' => 'The agency says it did not issue this certificate.',
            ];
        }

        if (in_array('limit_discrepancy', $signals, true)) {
            $flags[] = [
                'code' => 'limit_discrepancy',
                'severity' => 'stop',
                'message' => 'A limit on the certificate is not the one the agency wrote.',
            ];
        }

        if ($freeMail) {
            $flags[] = [
                'code' => 'free_mail_producer',
                'severity' => 'warn',
                'message' => 'This reply came from '.$domain.', which is not an agency domain.',
            ];
        }

        /*
         | A stop is a stop: one of these means the document is not what it
         | claims, and no amount of otherwise-valid detail changes that.
         */
        $verdict = match (true) {
            (bool) array_filter($flags, fn ($f) => $f['severity'] === 'stop') => 'do_not_rely',
            $flags !== [] => 'check_before_relying',
            default => 'no_concerns',
        };

        return [
            'verdict' => $verdict,
            'flags' => $flags,
            'reply_domain' => $domain,
            'free_mail' => $freeMail,
            'checked_at' => now()->toIso8601String(),
        ];
    }

    private function domainOf(?string $email): ?string
    {
        if (! is_string($email) || ! str_contains($email, '@')) {
            return null;
        }

        $domain = strtolower(trim(substr(strrchr($email, '@') ?: '', 1)));

        return $domain === '' ? null : $domain;
    }
}
