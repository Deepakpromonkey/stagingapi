<?php

namespace App\Http\Controllers\Api\V1\Notification;

use App\Http\Controllers\Api\V1\BaseController;
use App\Models\CarrierConnectRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The bell in the app header.
 *
 * There is no notifications table: nothing writes one, and adding a row per
 * event would need every onboarding write path to remember to do it. The feed
 * is instead *derived* from the timestamps the onboarding rows already carry,
 * which cannot drift out of step with the thing it reports on.
 *
 * Scoped to the caller's company, same as the Connected Carriers list — an
 * onboarding a colleague started is the whole team's business.
 */
class NotificationController extends BaseController
{
    /** How far back the feed reaches. Older than this is history, not news. */
    private const WINDOW_DAYS = 60;

    private const DEFAULT_LIMIT = 30;

    private const MAX_LIMIT = 100;

    public function index(Request $request)
    {
        $limit = (int) $request->input('limit', self::DEFAULT_LIMIT);
        $limit = max(1, min($limit, self::MAX_LIMIT));

        // Anything the client has already seen. The client sends back the
        // timestamp of the newest item it displayed, so "unread" survives a
        // reload without a read-state table.
        $seenAt = $this->parseSeenAt($request->input('seen_at'));

        $since = now()->subDays(self::WINDOW_DAYS);

        $requests = CarrierConnectRequest::forCompany($request->user()->company_id)
            ->with('user:id,first_name,last_name')

            // `sent_on` is null only for a row that was never mailed, and
            // `created_at` covers it. Either being inside the window is enough
            // — a request sent months ago can still produce a fresh event.
            ->where(function ($query) use ($since) {
                $query->where('updated_at', '>=', $since)
                    ->orWhere('created_at', '>=', $since);
            })
            ->get();

        $events = $requests
            ->flatMap(fn (CarrierConnectRequest $connectRequest) => $this->eventsFor($connectRequest))
            ->filter(fn (array $event) => $event['created_at'] !== null
                && $event['created_at']->greaterThanOrEqualTo($since))
            ->sortByDesc(fn (array $event) => $event['created_at']->getTimestamp())
            ->values();

        $unread = $seenAt
            ? $events->filter(fn (array $event) => $event['created_at']->greaterThan($seenAt))->count()
            : $events->count();

        $latestAt = $events->first()['created_at'] ?? null;

        return $this->success([
            'notifications' => $events->take($limit)
                ->map(fn (array $event) => [
                    'id' => $event['id'],
                    'type' => $event['type'],
                    'severity' => $event['severity'],
                    'title' => $event['title'],
                    'message' => $event['message'],
                    'link' => $event['link'],
                    'carrier' => $event['carrier'],
                    'created_at' => $event['created_at']->toIso8601String(),
                    'unread' => $seenAt === null || $event['created_at']->greaterThan($seenAt),
                ])
                ->values(),

            'unread_count' => $unread,

            // What the client should send back as `seen_at` once the panel has
            // been opened. Taken from the newest event rather than "now", so an
            // event that lands mid-request is not marked read by accident.
            'latest_at' => $latestAt?->toIso8601String(),
        ], 'Notifications retrieved.');
    }

    /**
     * Every notable moment in one onboarding, newest first once sorted.
     *
     * Each id is stable and derived from the request uuid plus the event key,
     * so the client can dedupe and remember dismissals across reloads.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function eventsFor(CarrierConnectRequest $connectRequest): Collection
    {
        $name = $connectRequest->carrier_legal_name ?: 'A carrier';
        $dot = $connectRequest->carrier_dot_number;

        $carrier = [
            'row_id' => $connectRequest->carrier_row_id,
            'dot_number' => $dot,
            'legal_name' => $connectRequest->carrier_legal_name,
        ];

        // The carrier profile, when we know which row it is. Onboarding rows
        // seeded from a manual entry have no directory row to link to.
        $link = $connectRequest->carrier_row_id
            ? '/carriers/'.$connectRequest->carrier_row_id
            : '/carriers';

        $completed = $connectRequest->status === CarrierConnectRequest::STATUS_COMPLETED;

        $events = collect();

        $add = function (
            string $key,
            ?Carbon $at,
            string $severity,
            string $title,
            string $message
        ) use (&$events, $connectRequest, $carrier, $link) {
            if (! $at) {
                return;
            }

            $events->push([
                'id' => 'connect:'.$connectRequest->uuid.':'.$key,
                'type' => $key,
                'severity' => $severity,
                'title' => $title,
                'message' => $message,
                'link' => $link,
                'carrier' => $carrier,
                'created_at' => $at,
            ]);
        };

        $add(
            'invitation_sent',
            $connectRequest->sent_on,
            'info',
            'Onboarding invitation sent',
            $name.' was invited to onboard'.($dot ? ' (DOT '.$dot.')' : '').'.'
        );

        // Only while it is still pending — once approved the wait is over and
        // the invitation event above tells the story.
        if ($connectRequest->pending_email && ! $connectRequest->pending_email_approved_at) {
            $add(
                'awaiting_email_approval',
                $connectRequest->pending_email_requested_at,
                'warning',
                'Waiting on email approval',
                $name.' has been asked to approve '.$connectRequest->pending_email
                    .' from their FMCSA-registered inbox.'
            );
        }

        $add(
            'onboarding_started',
            $connectRequest->first_visit_at,
            'info',
            'Onboarding started',
            $name.' opened their onboarding link.'
        );

        $add(
            'questionnaire_completed',
            $connectRequest->questionnaire_completed_at,
            'info',
            'Broker questions answered',
            $name.' answered your onboarding questionnaire.'
        );

        $add(
            'documents_completed',
            $connectRequest->documents_completed_at,
            'info',
            'Documents submitted',
            $name.' uploaded their onboarding documents.'
        );

        // A signature that has not yet turned into a completed onboarding is
        // news on its own; once it completes, the completion event says it
        // better and this would only repeat it.
        if (! $completed) {
            $add(
                'agreement_signed',
                $connectRequest->signed_at,
                'success',
                'Carrier agreement signed',
                $name.' signed your carrier agreement.'
            );
        }

        if (in_array($connectRequest->didit_status, ['Declined', 'Rejected', 'Failed', 'Expired'], true)) {
            $add(
                'identity_failed',
                $connectRequest->didit_responded_at,
                'error',
                'ID check failed',
                'The government ID check for '.$name.' came back '
                    .strtolower($connectRequest->didit_status).'.'
            );
        }

        if ($connectRequest->didit_risk_flagged) {
            $add(
                'identity_risk_flagged',
                $connectRequest->didit_responded_at,
                'warning',
                'Identity check flagged',
                'The verification session for '.$name
                    .' came from a VPN, Tor exit or data centre.'
            );
        }

        if ($completed) {
            $add(
                'onboarding_completed',
                $connectRequest->portal_account_provisioned_at
                    ?? $connectRequest->signed_at
                    ?? $connectRequest->updated_at,
                'success',
                'Onboarding complete',
                $name.' finished onboarding and is ready to haul.'
            );
        }

        // Expiry has no column of its own — it is the send date plus the
        // configured lifetime, the same arithmetic the Connected Carriers list
        // does to derive its `expired` stage.
        $lifetime = (int) config('carrier_connect.request_lifetime_hours', 72);

        $expiresAt = $connectRequest->sent_on?->copy()->addHours($lifetime);

        if (! $completed && $expiresAt && $expiresAt->isPast()) {
            $add(
                'invitation_expired',
                $expiresAt,
                'warning',
                'Onboarding invitation expired',
                'The invitation to '.$name.' expired before it was completed.'
            );
        }

        return $events;
    }

    /**
     * The client's read marker. A value we cannot parse is treated as absent
     * rather than as an error — a corrupt marker should show everything as
     * unread, not break the bell.
     */
    private function parseSeenAt(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
