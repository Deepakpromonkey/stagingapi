<?php

/*
|--------------------------------------------------------------------------
| Operational alerts
|--------------------------------------------------------------------------
|
| Server errors, failed jobs, services that stop and sites that go down are
| posted to the Teams channel (services.teams.webhook_url) and emailed.
|
| Which checks a box runs depends on OPS_ROLE, set in that box's .env:
|
|   production - reports its own errors, failed jobs and scheduled tasks,
|                and checks its own services (nginx, PHP-FPM, MySQL, ...).
|   watcher    - checks the production sites from outside. Runs on the
|                staging box, so it still reports when production is down
|                altogether - production cannot report its own outage.
|
| Unset, nothing is sent: a laptop or a test run never alerts anyone. Both
| boxes carry APP_ENV=production and the production APP_URL, so neither can
| stand in for this.
|
*/

return [

    'role' => env('OPS_ROLE'),

    // Shown on every alert, so a reader knows which box it came from.
    'server_label' => env('OPS_SERVER_LABEL', gethostname() ?: 'server'),

    'email' => [
        'to' => array_filter(explode(',', (string) env('OPS_ALERT_EMAIL_TO', 'deepak@promonkey.tech'))),
        'cc' => array_filter(explode(',', (string) env('OPS_ALERT_EMAIL_CC', 'gaurav@promonkey.tech'))),
    ],

    // The same problem is sent at most once in this window; repeats inside it
    // are counted and reported with the next alert.
    'repeat_after_minutes' => (int) env('OPS_ALERT_REPEAT_MINUTES', 15),

    // A hard ceiling across all alerts, so a cascading failure cannot send
    // hundreds of mails. Past it, alerts are logged and dropped until the
    // hour turns over.
    'max_per_hour' => (int) env('OPS_ALERT_MAX_PER_HOUR', 40),

    /*
    | Checked every minute by the watcher. A site counts as down after
    | `failures_before_alert` failed checks in a row, which rides out a single
    | slow response or a deploy's few seconds of 503.
    */
    'uptime' => [
        'targets' => array_filter(explode(',', (string) env('OPS_UPTIME_TARGETS', implode(',', [
            'https://brokerapi.dollartraq.com/up',
            'https://adminapi.dollartraq.com/up',
            'https://driverapi.dollartraq.com/up',
            'https://broker.dollartraq.com/',
            'https://admin.dollartraq.com/',
            'https://carrier.dollartraq.com/',
        ])))),
        'timeout' => (int) env('OPS_UPTIME_TIMEOUT', 15),
        'failures_before_alert' => (int) env('OPS_UPTIME_FAILURES', 2),
    ],

    // systemd units production checks on itself every minute.
    'services' => array_filter(explode(',', (string) env(
        'OPS_SERVICES',
        'nginx,php8.5-fpm,mysql,redis-server,supervisor'
    ))),

    // Longer than this, and a still-ongoing outage is posted again.
    'remind_after_minutes' => (int) env('OPS_REMIND_MINUTES', 60),

];
