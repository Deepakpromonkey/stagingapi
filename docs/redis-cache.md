# Redis cache

The application cache moves from the MySQL `cache` table to Redis. Every
`Cache::` call, the rate limiter, the permission cache, scheduler locks and
unique-job locks go with it. Nothing else changes: sessions stay on `file`
(the API authenticates with Sanctum tokens, not sessions) and queues stay on
`database`, where a job survives a Redis restart.

## What changed in the code

| Change | Why |
|---|---|
| `failover` store is Redis → database | A Redis outage costs speed, not uptime. The cache connection times out in 1s, so failing over is quick. |
| `cache.durable_store` (database) for the VIN backfill cursor and the carrier benchmarks | Neither is recomputed on a miss. They already live in the database store, so the switch needs no data copy. |
| `(int)` on the advanced search total | Redis returns a cached integer as a string; without the cast `total` would be serialised as `"123"`. Every other numeric cache read already casts. |
| Fleetra key path read through `config('services.fleetra…')` | `env()` outside `config/` returns null once config is cached. |
| Carrier users list eager-loads `roles.permissions` and `permissions` | Was two queries per user. |
| `predis/predis` added | Works on any PHP. Use phpredis instead where the extension is installed. |

## Rollout

Staging needs none of this by hand: `deploy/staging/provision.sh` does every
step below on each deploy (see docs/staging-deploy.md). The steps are for a
server the pipeline does not manage.

1. Redis on the box (or ElastiCache in the same VPC):

   ```bash
   sudo apt install redis-server
   # /etc/redis/redis.conf
   #   bind 127.0.0.1 ::1
   #   requirepass <strong password>
   #   maxmemory 512mb
   #   maxmemory-policy allkeys-lru
   sudo systemctl enable --now redis-server
   ```

2. `.env`:

   ```dotenv
   CACHE_STORE=failover
   REDIS_CLIENT=phpredis        # or predis if the extension is not installed
   REDIS_HOST=127.0.0.1
   REDIS_PASSWORD=<same as requirepass>
   REDIS_PORT=6379
   ```

   Staging and production must not share a Redis database. Use separate
   instances, or set a different `REDIS_PREFIX` / `REDIS_CACHE_DB`.

3. Deploy:

   ```bash
   composer install --no-dev --optimize-autoloader
   php artisan optimize          # config, routes, events, views
   sudo systemctl reload php8.5-fpm
   ```

   With OPcache on (`opcache.enable=1`, `opcache.memory_consumption=256`,
   `opcache.validate_timestamps=0`), the reload is what picks up new code.

4. Check: `php artisan tinker --execute="Cache::put('ping', 1, 10); dump(Cache::store('redis')->get('ping'));"`

The first requests after the switch run against a cold cache and are as slow
as an uncached request; the cache fills on its own.

**Rollback:** `CACHE_STORE=database`, then `php artisan config:cache`.
