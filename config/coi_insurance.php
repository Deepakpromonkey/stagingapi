<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Where the reply lands
    |--------------------------------------------------------------------------
    |
    | The request mail carries a Reply-To of
    |
    |     {local_part}+{dot}-{token}@{domain}
    |
    | so the agent's reply routes itself. Nothing is provisioned per request:
    | one real mailbox on `domain` receives everything, and the sub-address
    | carries the DOT and a per-request token that the webhook matches on. That
    | is why a broker can raise a request for two carriers in the same minute
    | and the two replies still land on the right rows.
    |
    | The mailbox itself has to exist and be pointed at the webhook below —
    | see docs/coi-insurance-requests.md for the provider setup.
    |
    */

    'inbox' => [
        'local_part' => env('COI_INBOX_LOCAL_PART', 'insurance'),
        'domain' => env('COI_INBOX_DOMAIN', 'inbox.dollartraq.app'),

        // Falls back to the application's own from-address when unset, which
        // is what a local install without a dedicated inbox should do.
        'from_address' => env('COI_FROM_ADDRESS'),
        'from_name' => env('COI_FROM_NAME', 'DollarTraq Team'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Test recipient
    |--------------------------------------------------------------------------
    |
    | Sends every request here instead of to the agency. For proving the loop
    | on a live server without mailing a real insurance broker — the whole
    | point of the feature is that a stranger receives the mail, and that is
    | not something to discover is broken by sending it.
    |
    | The address the resolver actually found is still recorded on the request,
    | so what the row says is what production would have done. It also stands
    | in for a missing contact: a DOT whose certificate carries no agency
    | address can still be walked end to end.
    |
    | MUST be empty in production.
    |
    */

    'force_recipient' => env('COI_FORCE_RECIPIENT'),

    /*
    |--------------------------------------------------------------------------
    | Inbound webhook
    |--------------------------------------------------------------------------
    |
    | The endpoint is public — the mail provider has no bearer token — so the
    | shared secret is the whole of the authorisation. Set it, or the endpoint
    | refuses every delivery rather than trusting an unsigned one.
    |
    | Sent either as the `X-Inbound-Secret` header or as `?secret=` on the URL,
    | because not every provider lets you set a custom header on a route.
    |
    */

    'webhook' => [
        'secret' => env('COI_INBOUND_SECRET'),

        /*
        | SES delivers through SNS, which opens with a SubscriptionConfirmation
        | that must be fetched before any mail arrives. Confirming is automatic
        | only for this topic — an unknown ARN is logged and ignored, so a
        | stranger cannot make the endpoint subscribe itself to their topic.
        */
        'sns_topic_arn' => env('COI_INBOUND_SNS_TOPIC_ARN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Expiry extraction
    |--------------------------------------------------------------------------
    |
    | The reply is prose written by a human at an insurance agency, so the date
    | is read out of it by Claude rather than by a regular expression. Keyed
    | separately from the rest of the application because it is the only thing
    | here that costs money per call.
    |
    */

    'llm' => [
        'model' => env('COI_LLM_MODEL', 'claude-opus-5'),
        'max_tokens' => (int) env('COI_LLM_MAX_TOKENS', 1024),

        // The reply body is truncated to this before it is sent, so a mail
        // with a 200-page quoted history cannot turn into a large bill.
        'max_body_chars' => (int) env('COI_LLM_MAX_BODY_CHARS', 20000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Set explicitly rather than inherited from QUEUE_CONNECTION: on `sync`
    | the send would run inside the broker's click and the extraction inside
    | the provider's webhook POST, and a provider that times out retries the
    | delivery — which would call Claude twice for one reply.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Chasing a silent agency
    |--------------------------------------------------------------------------
    |
    | A certificate request that gets no answer is asked again, twice, and then
    | left alone. Three mails from a stranger is a follow-up; the fourth is the
    | reason the address stops answering any of them.
    |
    | The clock runs from the last send, so a chase does not fire the moment
    | the previous one lands.
    |
    */

    'chase' => [
        'after_hours' => (int) env('COI_CHASE_AFTER_HOURS', 24),
        'max' => (int) env('COI_CHASE_MAX', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Re-routing to the address that can actually answer
    |--------------------------------------------------------------------------
    |
    | Some replies exist only to name someone else: an out-of-office pointing at
    | a service inbox, a producer saying the account moved, a broker-of-record
    | who cannot issue on a direct policy. Those are re-sent to the address the
    | reply names, and the request keeps its identity so the thread stays whole.
    |
    | Capped so a pair of agencies forwarding to each other cannot loop.
    |
    */

    'reroute' => [
        'max' => (int) env('COI_REROUTE_MAX', 2),
    ],

    'connection' => env('COI_QUEUE_CONNECTION', 'database'),
    'queue' => env('COI_QUEUE', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Lifecycle
    |--------------------------------------------------------------------------
    */

    // A request nobody answers stops being "pending" after this many days.
    'expire_after_days' => (int) env('COI_EXPIRE_AFTER_DAYS', 14),

    // How long before the same DOT may be chased again, in hours. Stops a
    // team from mailing one agency four times because four people opened the
    // same carrier profile.
    'resend_cooldown_hours' => (int) env('COI_RESEND_COOLDOWN_HOURS', 24),

];
