#!/usr/bin/env bash
#
# Brings the staging box to the state the application expects: Redis, a
# supervised queue worker, PHP tuned for a deployed (not edited) codebase, and
# logs that cannot fill the disk.
#
# Idempotent, and run by the pipeline before every deploy as root. Anything
# already in place is left alone, so a run with nothing to do takes a second.
# See docs/staging-deploy.md.

set -euo pipefail

APP_DIR=/var/www/dollartraq-api
APP_USER=ubuntu
PHP_VERSION=8.5
QUEUES=default,audit,drayage,vin,eld   # the same list routes/console.php drains

log() { echo "==> $*"; }

changed() {
    # Writes $2 to $1 only when it differs; returns 0 when it did.
    local file=$1 content=$2
    if [[ -f "$file" ]] && [[ "$(cat "$file")" == "$content" ]]; then
        return 1
    fi
    printf '%s\n' "$content" > "$file"
    return 0
}

# ── Packages ─────────────────────────────────────────────────────────────────

missing=()
for pkg in redis-server "php${PHP_VERSION}-redis" supervisor; do
    dpkg -s "$pkg" >/dev/null 2>&1 || missing+=("$pkg")
done

if (( ${#missing[@]} )); then
    log "Installing ${missing[*]}"
    apt-get update -qq
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "${missing[@]}"
fi

# ── .env ─────────────────────────────────────────────────────────────────────
#
# Only the keys this script owns. Everything else in .env is the team's, and a
# copy is kept before the first change so a hand edit is never lost.

ENV_FILE="$APP_DIR/.env"
env_backed_up=0

env_get() {
    grep -E "^$1=" "$ENV_FILE" | tail -n1 | cut -d= -f2- | sed -E 's/^"(.*)"$/\1/' || true
}

env_set() {
    local key=$1 value=$2
    [[ "$(env_get "$key")" == "$value" ]] && return 0

    if (( ! env_backed_up )); then
        cp -p "$ENV_FILE" "$ENV_FILE.bak-provision-$(date +%Y%m%d-%H%M%S)"
        env_backed_up=1
    fi

    if grep -qE "^$key=" "$ENV_FILE"; then
        sed -i "s|^$key=.*|$key=$value|" "$ENV_FILE"
    else
        printf '%s=%s\n' "$key" "$value" >> "$ENV_FILE"
    fi
    log ".env: $key set"
}

redis_password=$(env_get REDIS_PASSWORD)
if [[ -z "$redis_password" || "$redis_password" == "null" ]]; then
    redis_password=$(openssl rand -hex 24)
fi

env_set REDIS_CLIENT phpredis
env_set REDIS_HOST 127.0.0.1
env_set REDIS_PORT 6379
env_set REDIS_PASSWORD "$redis_password"
env_set CACHE_STORE failover

# ── Redis ────────────────────────────────────────────────────────────────────
#
# Local only, password protected, and bounded: past 256 MB the least recently
# used keys go, which for a cache is the right thing to lose.

REDIS_INCLUDE=/etc/redis/dollartraq.conf

if changed "$REDIS_INCLUDE" "# Managed by deploy/staging/provision.sh
bind 127.0.0.1 -::1
protected-mode yes
maxmemory 256mb
maxmemory-policy allkeys-lru
requirepass $redis_password"; then
    chown root:redis "$REDIS_INCLUDE"
    chmod 640 "$REDIS_INCLUDE"
    grep -qxF "include $REDIS_INCLUDE" /etc/redis/redis.conf \
        || echo "include $REDIS_INCLUDE" >> /etc/redis/redis.conf
    log "Redis configured"
    systemctl enable --quiet redis-server
    systemctl restart redis-server
fi

systemctl is-active --quiet redis-server || systemctl start redis-server

# ── PHP ──────────────────────────────────────────────────────────────────────
#
# validate_timestamps=0: PHP stops checking every file for changes on every
# request. Code only changes through a deploy, and the deploy reloads php-fpm.
# A hand edit on the server will not show until `systemctl reload php8.5-fpm`.

php_changed=0

if changed "/etc/php/$PHP_VERSION/fpm/conf.d/99-dollartraq.ini" "; Managed by deploy/staging/provision.sh
opcache.enable=1
opcache.memory_consumption=192
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0
realpath_cache_size=4096K
realpath_cache_ttl=600"; then
    php_changed=1
fi

# Five workers meant a sixth simultaneous request waited for one to free up.
# Ten fit in this box's memory with room to spare (about 60 MB each).
POOL="/etc/php/$PHP_VERSION/fpm/pool.d/www.conf"

pool_set() {
    local key=$1 value=$2
    grep -qE "^$key = $value\$" "$POOL" && return 0
    if grep -qE "^;?$key = " "$POOL"; then
        sed -i -E "s|^;?$key = .*|$key = $value|" "$POOL"
    else
        echo "$key = $value" >> "$POOL"
    fi
    php_changed=1
}

pool_set pm.max_children 10
pool_set pm.start_servers 3
pool_set pm.min_spare_servers 2
pool_set pm.max_spare_servers 5
pool_set pm.max_requests 500

if (( php_changed )); then
    "php-fpm$PHP_VERSION" -t 2>/dev/null
    systemctl reload "php$PHP_VERSION-fpm"
    log "PHP-FPM tuned"
fi

# ── Queue worker ─────────────────────────────────────────────────────────────
#
# A long-lived worker, so a job starts within seconds rather than at the next
# minute's scheduler run. The scheduler's own --stop-when-empty worker in
# routes/console.php keeps running beside it; two workers on the database
# queue never take the same job.

if changed /etc/supervisor/conf.d/dollartraq-queue.conf "; Managed by deploy/staging/provision.sh
[program:dollartraq-queue]
command=/usr/bin/php $APP_DIR/artisan queue:work database --queue=$QUEUES --sleep=3 --max-time=3600
directory=$APP_DIR
user=$APP_USER
umask=002
numprocs=1
autostart=true
autorestart=true
startsecs=5
stopasgroup=true
killasgroup=true
stopwaitsecs=600
redirect_stderr=true
stdout_logfile=$APP_DIR/storage/logs/queue-worker.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=3"; then
    log "Queue worker configured"
    systemctl enable --quiet supervisor
    systemctl start supervisor
    supervisorctl reread >/dev/null
    supervisorctl update
fi

systemctl is-active --quiet supervisor || systemctl start supervisor

# ── WebSockets ───────────────────────────────────────────────────────────────
#
# Reverb, on localhost only. Browsers reach it through nginx at
# wss://staggingapi.dollartraq.com/app/{key} (snippet below), so port 8080 is
# never exposed. The API publishes to it directly on 127.0.0.1:8080
# (REVERB_HOST/REVERB_PORT in .env), which is why /apps/* is not proxied.

if changed /etc/supervisor/conf.d/dollartraq-reverb.conf "; Managed by deploy/staging/provision.sh
[program:dollartraq-reverb]
command=/usr/bin/php $APP_DIR/artisan reverb:start --host=127.0.0.1 --port=8080 --no-interaction
directory=$APP_DIR
user=$APP_USER
umask=002
numprocs=1
autostart=true
autorestart=true
startsecs=3
stopasgroup=true
killasgroup=true
stopwaitsecs=10
minfds=10240
redirect_stderr=true
stdout_logfile=$APP_DIR/storage/logs/reverb.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=3"; then
    log "Reverb configured"
    supervisorctl reread >/dev/null
    supervisorctl update
fi

# ── nginx ────────────────────────────────────────────────────────────────────
#
# Ubuntu's nginx.conf turns gzip on for text/html only, so the frontend's
# 3.6 MB bundle and every JSON response from the API went out uncompressed.
# This applies to every site on the box.
#
# The frontend's hashed /assets/ files never change under the same name, so
# browsers may keep them for a year; index.html is revalidated every time so
# a deploy shows at once.

FRONTEND_SITE=/etc/nginx/sites-available/staging.dollartraq.com
FRONTEND_SNIPPET=/etc/nginx/snippets/dollartraq-frontend-cache.conf
nginx_changed=0

changed /etc/nginx/conf.d/dollartraq-gzip.conf "# Managed by deploy/staging/provision.sh
gzip_vary on;
gzip_proxied any;
gzip_comp_level 5;
gzip_min_length 1024;
gzip_types text/plain text/css text/javascript application/javascript application/json application/xml image/svg+xml application/wasm;" && nginx_changed=1

changed "$FRONTEND_SNIPPET" "# Managed by deploy/staging/provision.sh
location /assets/ {
    add_header Cache-Control \"public, max-age=31536000, immutable\";
    try_files \$uri =404;
}

location = /index.html {
    add_header Cache-Control \"no-cache\";
}" && nginx_changed=1

# Response times for the API, in their own log beside the usual access log:
# path, status, total seconds, seconds PHP took. Rotated by Ubuntu's nginx
# logrotate (/var/log/nginx/*.log).
API_SITE=/etc/nginx/sites-available/staggingapi.dollartraq.com
API_TIMING_LINE="access_log /var/log/nginx/staggingapi.timing.log dollartraq_timing;"

changed /etc/nginx/conf.d/dollartraq-timing.conf "# Managed by deploy/staging/provision.sh
# The path as requested: \$uri has become /index.php by the time it is logged.
# Query string dropped, so no token in one can reach the log.
map \$request_uri \$dollartraq_path {
    ~^(?<path>[^?]*) \$path;
}
log_format dollartraq_timing '\$time_iso8601 \$request_method \$dollartraq_path \$status \$request_time \$upstream_response_time \$body_bytes_sent';" && nginx_changed=1

REVERB_SNIPPET=/etc/nginx/snippets/dollartraq-reverb.conf

changed "$REVERB_SNIPPET" "# Managed by deploy/staging/provision.sh
# The WebSocket handshake only. Reverb's /apps/* HTTP API is for the backend
# and stays on localhost.
location ~ ^/app/[A-Za-z0-9_-]+\$ {
    proxy_http_version 1.1;
    proxy_set_header Host \$http_host;
    proxy_set_header Scheme \$scheme;
    proxy_set_header SERVER_PORT \$server_port;
    proxy_set_header REMOTE_ADDR \$remote_addr;
    proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
    proxy_set_header Upgrade \$http_upgrade;
    proxy_set_header Connection \"Upgrade\";
    proxy_read_timeout 120s;
    proxy_send_timeout 120s;
    proxy_pass http://127.0.0.1:8080;
}" && nginx_changed=1

api_backup=""

backup_api_site() {
    [[ -n "$api_backup" ]] && return 0
    api_backup="/root/$(basename "$API_SITE").bak-$(date +%Y%m%d-%H%M%S)"
    cp -p "$API_SITE" "$api_backup"
}

if [[ -f "$API_SITE" ]] && ! grep -qF "$API_TIMING_LINE" "$API_SITE"; then
    backup_api_site
    sed -i "0,/^\s*access_log .*staggingapi.access.log;/s||&\n    $API_TIMING_LINE|" "$API_SITE"
    nginx_changed=1
fi

if [[ -f "$API_SITE" ]] && ! grep -qF "include $REVERB_SNIPPET;" "$API_SITE"; then
    backup_api_site
    # Into the HTTPS server block, right after its index line.
    sed -i "0,/^\s*index index.php index.html;/s||&\n\n    include $REVERB_SNIPPET;|" "$API_SITE"
    nginx_changed=1
fi

site_backup=""

if [[ -f "$FRONTEND_SITE" ]] && ! grep -qF "include $FRONTEND_SNIPPET;" "$FRONTEND_SITE"; then
    site_backup="/root/$(basename "$FRONTEND_SITE").bak-$(date +%Y%m%d-%H%M%S)"
    cp -p "$FRONTEND_SITE" "$site_backup"
    # Into the HTTPS server block, right after its index line.
    sed -i "0,/^\s*index index.html;/s||    index index.html;\n\n    include $FRONTEND_SNIPPET;|" "$FRONTEND_SITE"
    nginx_changed=1
fi

if (( nginx_changed )); then
    if nginx -t 2>/dev/null; then
        systemctl reload nginx
        log "nginx: compression and asset caching"
    else
        nginx -t || true
        # Put the box back as it was, so the next reload by anyone still works.
        [[ -n "$site_backup" ]] && cp -p "$site_backup" "$FRONTEND_SITE"
        [[ -n "$api_backup" ]] && cp -p "$api_backup" "$API_SITE"
        rm -f /etc/nginx/conf.d/dollartraq-gzip.conf /etc/nginx/conf.d/dollartraq-timing.conf "$FRONTEND_SNIPPET" "$REVERB_SNIPPET"
        echo "nginx config test failed; changes reverted, nginx not reloaded" >&2
        exit 1
    fi
fi

# ── Logs and disk ────────────────────────────────────────────────────────────
#
# laravel.log only ever grew. The dated channel logs (drayage-*.log) are
# rotated by Laravel itself and are left out.

changed /etc/logrotate.d/dollartraq-api "# Managed by deploy/staging/provision.sh
$APP_DIR/storage/logs/laravel.log $APP_DIR/storage/logs/queue-worker.log {
    su $APP_USER www-data
    daily
    maxsize 50M
    rotate 14
    missingok
    notifempty
    compress
    delaycompress
    copytruncate
}" && log "Log rotation configured" || true

apt-get clean
journalctl --vacuum-size=150M >/dev/null 2>&1 || true

log "Provisioned ($(df -h / | awk 'NR==2 {print $4}') free on /)"
