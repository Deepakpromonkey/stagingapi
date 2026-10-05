# Staging deploy

`staggingapi.dollartraq.com` deploys itself. A push to `main` on
`Deepakpromonkey/stagingapi` runs the tests; when they pass, GitHub Actions
connects to the staging box (3.15.199.243) and:

1. **Provisions** it with `deploy/staging/provision.sh`. Anything already in
   place is left alone.
2. **Releases** the commit with `deploy/staging/deploy.sh`.
3. Checks `https://staggingapi.dollartraq.com/up` from outside.

The workflow is `.github/workflows/deploy-staging.yml`. It only runs in the
staging repository; the same file in the production repository does nothing.

## What a release does

`git reset` to the commit, `composer install --no-dev`, `migrate --force`,
`php artisan optimize`, reload php-fpm, `queue:restart`. It then checks that
`/up` answers and the cache can be written.

If either check fails, the previous commit goes back and the run fails red.
**Migrations are not undone.** They are forward-only (see the technical
documentation); fix forward with a new migration.

Edits made by hand on the server are not lost: the release saves them to
`storage/app/deploy-backups/` before the reset.

## What the server gets

| | |
|---|---|
| Redis | Local only, password in `.env`, 256 MB, least-recently-used eviction. The app uses it through `CACHE_STORE=failover` (see redis-cache.md). |
| Queue worker | `dollartraq-queue` under Supervisor, always running: jobs start within seconds instead of at the next minute. Log: `storage/logs/queue-worker.log`. |
| PHP | OPcache stops re-checking files on every request; php-fpm runs 10 workers instead of 5. |
| Logs | `laravel.log` rotates daily or at 50 MB, kept 14 days, compressed. |

## Day to day

- **Deploy:** merge to `main`.
- **Deploy something else / roll back:** Actions → Deploy staging → Run
  workflow, and give the branch, tag or commit.
- **Changed `.env` by hand:** config is cached, so run
  `php artisan config:cache` afterwards, or the change does nothing.
- **Changed a PHP file by hand:** `sudo systemctl reload php8.5-fpm`, or the
  old code keeps running. Better: commit it.
- **Worker:** `sudo supervisorctl status`, `sudo supervisorctl restart dollartraq-queue`.

## Setup (once)

The workflow needs one secret, `STAGING_SSH_KEY`: the private half of a key
whose public half is in `~ubuntu/.ssh/authorized_keys` on the box. Add it
under Settings → Secrets and variables → Actions.

If the instance is rebuilt, update `STAGING_HOST_KEY` in the workflow with
`ssh-keyscan -t ed25519 3.15.199.243`.
