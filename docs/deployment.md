# Deploying the Corporate Helpdesk

Two parts: this Laravel API and the React app (`corporate-helpdesk-frontend`, a static build).
Both must be served over **HTTPS**. Nothing here has been run against a real server yet; treat
it as a checklist to follow and verify, not as a record of a working deployment.

## 1. Before the first deploy

- [ ] A MySQL database and a **dedicated** account (`helpdesk_app`) with rights on that schema only.
- [ ] PHP 8.3 with `mbstring`, `pdo_mysql`, `fileinfo`, `gd`, `zip`, `intl`, `openssl`.
- [ ] A working outgoing mail account (SMTP, SES, Postmark or Resend). **Without it nobody can verify an
      email address or reset a password.**
- [ ] Two DNS names with TLS certificates, e.g. `helpdesk.example.org` (app) and `api.helpdesk.example.org` (API).
- [ ] Decide who receives backups (section 6).

## 2. Backend

```bash
git clone <repo> && cd corporate-helpdesk
composer install --no-dev --optimize-autoloader
cp .env.production.example .env        # then edit .env; never commit it
php artisan key:generate
php artisan migrate --force
php artisan helpdesk:create-super-admin   # first administrator (see the command's help)
php artisan config:cache && php artisan route:cache && php artisan event:cache
php artisan helpdesk:mail-test you@yourcompany.org
php artisan helpdesk:preflight
```

`helpdesk:preflight` must end with **Ready**. It fails on: debug mode, non-HTTPS URLs, a `log`/`array`
mail transport, an example.com sender, a wildcard CORS origin, attachments inside `public/`, PHP upload
limits smaller than the attachment limit, and an unreachable database. It warns on: missing
`TRUSTED_PROXIES`/HSTS, a `debug` log level, uncached config/routes, a stale scheduler, `QUEUE_CONNECTION=sync`,
stuck or failed queue jobs.
Run it after **every** deploy (`--strict` makes warnings fail too).

After every deploy:

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan event:cache
php artisan queue:restart
php artisan helpdesk:preflight
```

Take a database backup **before** `migrate --force`.

### Scheduler (required)

Login-history retention, orphan attachment cleanup and the scheduler heartbeat run through Laravel's scheduler.
Add one cron entry:

```
* * * * * cd /var/www/corporate-helpdesk && php artisan schedule:run >> /dev/null 2>&1
```

`helpdesk:preflight` warns if the heartbeat is older than five minutes. If you run several web servers the
jobs use `onOneServer()`, which needs a shared cache (database or Redis, not `file`).

### Mail

Two kinds of email:

- **Account emails** (verification, password reset, invitation of a new user) are sent **inside the request**.
  A mail outage is logged and never turns into an error for the visitor, and never reveals whether an address
  has an account. Administrators creating a user see `email_sent: false` when the mail could not be sent.
- **Ticket notification emails** (ticket received, assigned, replied, resolved, ...) are **queued**, so they need
  the queue worker below. They contain no message text or attachments, only a short notice and a sign-in link.
  Set `TICKET_NOTIFICATIONS_ENABLED=false` to switch them all off.

### Queue worker (required for ticket emails)

Ticket emails are jobs on the database queue (`QUEUE_CONNECTION=database`, tables `jobs` and `failed_jobs`, which
the migrations create). **If no worker is running, jobs pile up and no ticket email is ever delivered**, and
nothing shows in the web app. Keep exactly one worker running under Supervisor (or systemd).

`/etc/supervisor/conf.d/helpdesk-worker.conf`:

```ini
[program:helpdesk-worker]
process_name=%(program_name)s
command=php /var/www/corporate-helpdesk/artisan queue:work --tries=3 --max-time=3600 --sleep=3
directory=/var/www/corporate-helpdesk
user=www-data
numprocs=1
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=3600
redirect_stderr=true
stdout_logfile=/var/log/helpdesk-worker.log
```

```bash
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl start helpdesk-worker
```

- **After every deploy** run `php artisan queue:restart` so the worker picks up the new code (Supervisor starts it
  again by itself).
- A job is tried up to 3 times (1, 5 and 15 minutes apart). Jobs that still fail land in `failed_jobs`:
  `php artisan queue:failed` lists them, `php artisan queue:retry all` retries them.
- `php artisan helpdesk:preflight` warns when a job has waited more than five minutes (worker not running), when
  `failed_jobs` is not empty, and when `QUEUE_CONNECTION=sync` (emails would be sent inside the web request).
- One worker is plenty for a helpdesk. Duplicates are prevented in the application (at most one email per person
  per action); a job that is retried after the mail was already accepted could, rarely, send a second copy.
- For local development use `QUEUE_CONNECTION=sync` (the default in `.env.example`): with `MAIL_MAILER=log` the
  emails appear in `storage/logs/laravel.log` at once and no worker is needed. If your own `.env` says `database`,
  run `php artisan queue:listen` (or `php artisan dev`) or nothing will be sent.

### Reverse proxy

If the API sits behind Nginx/Apache/a load balancer/Cloudflare, set `TRUSTED_PROXIES` to its address(es).
Otherwise every visitor shares the proxy's IP and the per-IP rate limits on login, registration and password
reset become one shared bucket (easy to lock everyone out, easy to abuse). Never use `*` unless the API is
unreachable except through the proxy.

## 3. Web server for the API (Nginx example)

```nginx
server {
    listen 443 ssl http2;
    server_name api.helpdesk.example.org;
    root /var/www/corporate-helpdesk/public;      # only public/ is exposed; storage/ and .env are not

    # Larger than ATTACHMENT_MAX_REQUEST_KB (15 MB) plus form overhead.
    client_max_body_size 20m;

    index index.php;
    location / { try_files $uri /index.php?$query_string; }
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
    location ~ /\.(?!well-known) { deny all; }
}
```

PHP-FPM (`php.ini` for FPM): `upload_max_filesize = 6M`, `post_max_size = 20M`, `max_file_uploads = 20`
(the preflight checks these against the attachment limits). Run PHP-FPM as a user that can write
`storage/` and `bootstrap/cache/` and nothing else.

## 4. Frontend

```bash
cd corporate-helpdesk-frontend
echo "VITE_API_BASE_URL=https://api.helpdesk.example.org/api" > .env.production.local
npm ci && npm run build          # output: dist/
```

Serve `dist/` as static files with a fallback to `index.html` (the app uses client-side routes):

```nginx
server {
    listen 443 ssl http2;
    server_name helpdesk.example.org;
    root /var/www/helpdesk-frontend/dist;

    add_header X-Content-Type-Options nosniff always;
    add_header X-Frame-Options DENY always;
    add_header Referrer-Policy no-referrer always;
    add_header Strict-Transport-Security "max-age=31536000" always;
    # Tighten `connect-src` to your API origin; 'unsafe-inline' for styles is needed by the build's CSS.
    add_header Content-Security-Policy "default-src 'self'; connect-src 'self' https://api.helpdesk.example.org; img-src 'self' data:; style-src 'self' 'unsafe-inline'; frame-ancestors 'none'" always;

    location /assets/ { expires 1y; add_header Cache-Control "public, immutable"; }
    location / { try_files $uri /index.html; add_header Cache-Control "no-cache"; }
}
```

The API's `FRONTEND_URL` must be exactly this site's origin (scheme + host, no trailing slash): it drives CORS
and the links in emails.

## 5. Verify after deploying

- [ ] `https://api.../up` returns 200.
- [ ] `php artisan helpdesk:preflight` → **Ready**.
- [ ] Register a new customer: the verification email arrives, the link opens the app, login works.
- [ ] "Forgot password" email arrives; the reset works.
- [ ] Open a ticket with an attachment, download it, and confirm a customer cannot see an internal note's file.
- [ ] `sudo supervisorctl status helpdesk-worker` says RUNNING. Open a test ticket as a customer: the "ticket received"
      email arrives within a minute (and the admins' "new ticket" email, if the ticket has no department). Then add an
      **internal note** as staff and confirm the customer received **nothing** about it.
- [ ] `php artisan queue:failed` is empty and `helpdesk:preflight` shows no queue warnings.
- [ ] A response header check: `curl -I https://api.../api/user` shows `X-Content-Type-Options`, `Cache-Control: no-store`,
      and (with `HSTS_ENABLED`) `Strict-Transport-Security`.
- [ ] Log in five times with a wrong password from your own connection: the sixth attempt is rate-limited (429),
      and a colleague on another connection is **not** affected (proves `TRUSTED_PROXIES` is right).
- [ ] Nothing in `storage/logs` shows stack traces to visitors; a 500 returns only `{"message":"Server Error"}`.

## 6. Backups

- **Database:** a nightly `mysqldump` (or your host's snapshot), kept off the server, restore-tested.
- **Attachment files:** `storage/app/private/attachments` is **not** in the database dump. Back it up with the
  same schedule or tickets will lose their files. Attachments are outside `public/` and only reachable through the API.
- `.env` and `APP_KEY`: store them in your password manager or secret store.

## 7. Rolling back

Keep the previous release directory. To roll back: point the web root at it, run `config:cache`/`route:cache`, and
restore the pre-deploy database backup **only if a migration ran**. Migrations in this project are additive.

## 8. Known limitations

- No antivirus scan on uploads (files are inspected by content, served only as downloads, never inline).
- Image metadata (EXIF, e.g. GPS) is kept in uploaded photos.
- Ticket emails carry no message text (sign in to read), cannot be answered by email, and there is no per-user
  opt-out or digest yet: everyone entitled to an email gets each one.
- Sign-in tokens live in the browser's `sessionStorage`/`localStorage`; an XSS bug in the app would expose them.
  The CSP above and React's escaping are the mitigations.
