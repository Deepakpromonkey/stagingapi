<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * A request to a carrier's insurance agency for current COI details.
 *
 * The lifecycle is one-way: pending -> responded -> success, with failed and
 * expired as the two ways out. Nothing moves a row backwards, so a second
 * reply on an already-resolved request is stored but does not re-open it.
 *
 * `awaiting` sits between those: the agency answered without a date, so the
 * request is still live and the next reply is still read. It leaves only by
 * succeeding or by the sweep giving up on it.
 */
class CoiInsuranceRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RESPONDED = 'responded';

    public const STATUS_SUCCESS = 'success';

    /*
    | The agency replied, and the reply carried no date — it asked who we are,
    | said the renewal is still with underwriting, or was an out-of-office.
    |
    | Not a failure: the certificate is usually in the next mail. Treating it
    | as one closed the request, and the follow-up carrying the actual date
    | was then stored and never read.
    */
    public const STATUS_AWAITING = 'awaiting';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'uuid',
        'company_id',
        'user_id',
        'dot_number',
        'carrier_name',
        'carrier_mc',
        'recipient_email',
        'recipient_source',
        'status',
        'reply_token',
        'subject',
        'message_id',
        'insurance_expiry_date',
        'verification',
        'coverage',
        'trust',
        'verified_at',
        'sent_at',
        'chase_count',
        'last_chase_at',
        'reroute_count',
        'rerouted_to',
        'responded_at',
        'resolved_at',
        'last_error',
    ];

    protected $casts = [
        'dot_number' => 'integer',
        'insurance_expiry_date' => 'date',
        'verification' => 'array',
        'coverage' => 'array',
        'trust' => 'array',
        'verified_at' => 'datetime',
        'sent_at' => 'datetime',
        'last_chase_at' => 'datetime',
        'responded_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $request) {
            $request->uuid ??= (string) Str::uuid();
            $request->reply_token ??= self::newReplyToken();
        });
    }

    /**
     * The random half of the Reply-To sub-address.
     *
     * Long enough that guessing one is not a way to inject a fabricated expiry
     * date into another company's carrier, and restricted to characters that
     * survive a mail provider's address normalisation unchanged.
     */
    public static function newReplyToken(): string
    {
        return Str::lower(Str::random(24));
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function responses(): HasMany
    {
        return $this->hasMany(CoiInsuranceResponse::class);
    }

    /**
     * The reply the current answer was read out of — the newest one, because a
     * follow-up correction from the agency supersedes the first mail.
     */
    public function latestResponse(): HasOne
    {
        return $this->hasOne(CoiInsuranceResponse::class)->latestOfMany();
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING,
            self::STATUS_RESPONDED,
            self::STATUS_AWAITING,
        ], true);
    }

    /*
    | The request as a path rather than a single status.
    |
    | A broker chasing a slow agency wants to know where the request got to,
    | not only where it stopped: "sent, no reply in nine days" and "sent,
    | replied, still being read" both show as open on the card, and the two
    | mean very different things to someone deciding whether to chase again.
    |
    | Every step is derived from facts already on the row or its replies, so
    | this stays true without a second source to keep in step. Steps are
    | returned in order, each marked reached or not, and the last reached one
    | is the request's current position.
    */
    public function statePath(): array
    {
        $replies = $this->relationLoaded('responses')
            ? $this->responses
            : $this->responses()->oldest('received_at')->get();

        $firstReply = $replies->first();

        $path = [
            [
                'state' => 'REQUEST_SENT',
                'label' => 'Request sent',
                'detail' => $this->recipient_email,
                'at' => $this->sent_at?->toIso8601String(),
                'reached' => $this->sent_at !== null,
            ],
            [
                'state' => 'REPLY_RECEIVED',
                'label' => 'Agency replied',
                'detail' => $firstReply?->from_email,
                'at' => $firstReply?->received_at?->toIso8601String(),
                'reached' => $firstReply !== null,
            ],
        ];

        /*
        | The two ways out that are not a certificate. Neither can follow a
        | reply being read, so they replace the tail of the path rather than
        | extending it.
        */
        /*
        | Failed covers two different endings: the mail never got out, and the
        | agency answered without stating a date. Calling both "could not be
        | delivered" tells a broker the opposite of what happened in the second
        | case, which is the more common of the two — so the reply decides.
        */
        if ($this->status === self::STATUS_FAILED) {
            $path[] = $firstReply === null
                ? [
                    'state' => 'FAILED',
                    'label' => 'Could not be delivered',
                    'detail' => $this->last_error,
                    'at' => $this->resolved_at?->toIso8601String(),
                    'reached' => true,
                ]
                : [
                    'state' => 'NO_DATE_IN_REPLY',
                    'label' => 'Replied, no expiry date',
                    'detail' => $this->last_error,
                    'at' => $this->resolved_at?->toIso8601String(),
                    'reached' => true,
                ];

            return $path;
        }

        if ($this->status === self::STATUS_EXPIRED) {
            $path[] = [
                'state' => 'NO_RESPONSE',
                'label' => 'No response',
                'detail' => 'The agency did not reply.',
                'at' => $this->resolved_at?->toIso8601String(),
                'reached' => true,
            ];

            return $path;
        }

        /*
        | Answered, but not with a date. The path stops here and stays here
        | until the agency sends the certificate — so it reads as waiting on
        | someone else, which is what it is, rather than as finished.
        */
        if ($this->status === self::STATUS_AWAITING) {
            $path[] = [
                'state' => 'AWAITING_DETAILS',
                'label' => 'Replied, waiting on the certificate',
                'detail' => $this->last_error,
                'at' => $this->responded_at?->toIso8601String(),
                'reached' => true,
            ];

            $path[] = [
                'state' => 'VERIFIED',
                'label' => 'Expiry date read',
                'detail' => null,
                'at' => null,
                'reached' => false,
            ];

            return $path;
        }

        $path[] = [
            'state' => 'READING_REPLY',
            'label' => 'Reading the reply',
            'detail' => null,
            'at' => $this->responded_at?->toIso8601String(),
            'reached' => $this->responded_at !== null,
        ];

        $path[] = [
            'state' => 'VERIFIED',
            'label' => 'Expiry date read',
            'detail' => $this->insurance_expiry_date?->toFormattedDateString(),
            'at' => $this->resolved_at?->toIso8601String(),
            'reached' => $this->status === self::STATUS_SUCCESS,
        ];

        return $path;
    }

    /**
     * The address the agency replies to: the shared inbox, sub-addressed with
     * this carrier's DOT and this request's token.
     */
    public function replyToAddress(): string
    {
        $local = config('coi_insurance.inbox.local_part');
        $domain = config('coi_insurance.inbox.domain');

        return sprintf('%s+%d-%s@%s', $local, $this->dot_number, $this->reply_token, $domain);
    }

    /**
     * The subject line the whole feature is identified by, in the broker's
     * inbox as much as in ours.
     */
    public static function buildSubject(?string $carrierName, int|string $dot): string
    {
        $name = trim((string) $carrierName);

        return 'Insurance details of the carrier '
            .($name !== '' ? $name.' ' : '')
            .$dot;
    }
}
