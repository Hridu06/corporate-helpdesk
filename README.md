# Corporate Helpdesk & Support Management System

A role-based support desk for a company: customers open tickets and talk to staff, agents work the tickets they are assigned, administrators route and oversee them.

The project is two repositories that run side by side:

| Part | What it is | Repository folder |
|---|---|---|
| **API** (this repository) | Laravel REST API. It owns all business rules and is the security boundary. | `corporate-helpdesk` |
| **Web app** | React single-page app that talks to the API with a Bearer token. | `corporate-helpdesk-frontend` |

Every permission check in the web app is a convenience only; the API authorises each request on its own.

## Contents

1. [Features](#features)
2. [Roles and permissions](#roles-and-permissions)
3. [Technology stack](#technology-stack)
4. [Local development setup](#local-development-setup)
5. [Environment configuration](#environment-configuration)
6. [Database: migrations and seeding](#database-migrations-and-seeding)
7. [Tests, lint and build](#tests-lint-and-build)
8. [Attachments: security and storage](#attachments-security-and-storage)
9. [Scheduler and cleanup jobs](#scheduler-and-cleanup-jobs)
10. [Queue worker and ticket emails](#queue-worker-and-ticket-emails)
11. [Deployment](#deployment)
12. [Project structure](#project-structure)
13. [Current limitations](#current-limitations)

## Features

Implemented and covered by automated tests:

- **Accounts and sign-in:** public registration (always creates a Customer), email verification required before sign-in, login and logout with Sanctum Bearer tokens (a longer-lived "remember me" token is optional), password reset by emailed link (single use; all tokens are revoked after a reset), rate limits on login, registration and reset.
- **User management** (Admin and Super Admin): create, edit, change role, activate/deactivate. New users get an emailed link to set their own password. Self-protection rules stop an administrator from locking themselves out or acting above their level. Sensitive actions are written to an append-only audit log.
- **Login activity:** history of sign-ins with IP and device, kept for a configurable number of days and pruned daily. Unknown-email attempts and passwords are never stored.
- **Departments:** create, edit, deactivate, and manage which agents belong to them.
- **Tickets:** create, list (filters, search by subject or `TCK-000123`), detail view, status workflow (Open → In Progress → Resolved → Closed, with reopen), priority, assignment to an agent, and a history of every change. Customers can close or reopen their own resolved tickets.
- **Conversation:** public replies and staff-only **internal notes**. Internal notes are never returned to customers by any endpoint. A customer reply on a Resolved ticket reopens it.
- **Attachments** on new tickets, replies and internal notes (see [Attachments](#attachments-security-and-storage)).
- **Ticket notification emails** (see [Queue worker and ticket emails](#queue-worker-and-ticket-emails)): the customer hears when a ticket is received, replied to, resolved, closed or reopened; the assignee when a ticket is assigned to them, the customer replies, or a note is added. They are short notices with a sign-in link and never contain message text.
- **Reports** (for people with `report.view`): tickets created and resolved, tickets open and unassigned now, average resolution time, average first-response time, reopened tickets, a per-day (per-week on long periods) chart, breakdowns by status, priority, department and age of open tickets, and a table per agent. Any period up to 366 days, optionally for one department. The API returns aggregate numbers only (never a subject, message or customer detail); the page explains how each figure is counted. Days are counted in `REPORTS_TIMEZONE` (default UTC).
- **Web app screens:** sign in, register, verify email, forgot/reset password, role-aware dashboard and sidebar, tickets (list, new, detail), users, departments, login activity, reports.

Present in the navigation as "coming soon" placeholders, **not built yet:** Roles & Permissions, System Settings.

## Roles and permissions

Roles and permissions use [spatie/laravel-permission](https://spatie.be/docs/laravel-permission). Permission names follow `resource.action`. The defaults live in `app/Support/Permissions.php` and are loaded by the seeder.

| Role | Default abilities |
|---|---|
| **Super Admin** | Everything, implicitly. Created only from the command line (`helpdesk:create-super-admin`), never through the API. |
| **Admin** | View/create/update users; view/update departments; see all tickets; assign, change priority and status; reply and write internal notes; `report.view` (Reports). |
| **Agent** | View departments; see **only tickets assigned to them**; change status, reply, write internal notes. |
| **Customer** | Create tickets; see **only their own tickets**; reply; close or reopen their own resolved tickets. Never sees internal notes. |

Ticket visibility is the same rule everywhere: a user with `ticket.view_all`, the customer who opened the ticket, or its assignee. Seeding is idempotent and never overwrites permissions that were changed on an existing role.

## Technology stack

**Backend (this repository)**

- PHP 8.3+, Laravel 13
- Laravel Sanctum 4 (personal access tokens), spatie/laravel-permission 8
- MySQL for development and production; in-memory SQLite for the test suite
- PHPUnit 12, Laravel Pint

**Frontend (`corporate-helpdesk-frontend`)**

- React 19, Vite 8, JavaScript (no TypeScript)
- Tailwind CSS 4
- Redux Toolkit (session state), React Router 7
- React Hook Form with Zod validation, Axios, react-hot-toast
- Vitest 5 with Testing Library, oxlint

## Local development setup

Requirements: PHP 8.3+ with `mbstring`, `pdo_mysql`, `pdo_sqlite`, `fileinfo`, `gd`, `zip`, `intl`; Composer; MySQL 8; Node.js 22+ and npm.

### 1. API

```bash
composer install
cp .env.example .env            # Windows (cmd): copy .env.example .env
php artisan key:generate
```

Create an empty MySQL database (the default name is `corporate_helpdesk`) and set the `DB_*` values in `.env`. Then:

```bash
php artisan migrate
php artisan db:seed                       # roles and permissions only
php artisan helpdesk:create-super-admin   # asks for name, email, password (12+ characters)
php artisan serve                         # http://localhost:8000
```

In development `MAIL_MAILER=log`, so **no email is really sent**: verification and password-reset links appear in `storage/logs/laravel.log`. Newly registered users must open the verification link before they can sign in. (An account created with `helpdesk:create-super-admin` or by an administrator is already verified.)

For the scheduled jobs while developing, run `php artisan schedule:work` in a second terminal (see [Scheduler](#scheduler-and-cleanup-jobs)).

The `composer setup` and `composer dev` scripts exist but also run `migrate --force` and Laravel's stock asset tooling; the explicit steps above are the ones this project is developed with.

### 2. Web app

```bash
cd ../corporate-helpdesk-frontend
npm install
cp .env.example .env        # VITE_API_BASE_URL=http://localhost:8000/api
npm run dev                 # http://localhost:5173
```

The API allows CORS from `FRONTEND_URL` (default `http://localhost:5173`), and Vite is pinned to port 5173 (`strictPort`), so keep both in step if you change either.

## Environment configuration

Copy `.env.example` (development) or `.env.production.example` (production template); never commit a real `.env`. Values below are grouped by purpose; see the two example files for every setting.

| Group | Variables | Notes |
|---|---|---|
| Application | `APP_NAME`, `APP_ENV`, `APP_DEBUG`, `APP_KEY`, `APP_URL` | `APP_DEBUG` must be `false` in production. |
| Database | `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Use a dedicated, non-root account in production. |
| Frontend / CORS | `FRONTEND_URL`, `CORS_ALLOWED_ORIGINS` | `FRONTEND_URL` is also used in emailed links. |
| Mail | `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_SCHEME`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | `log` by default (development only). |
| Sign-in tokens | `AUTH_TOKEN_TTL`, `AUTH_REMEMBER_TOKEN_TTL`, `SANCTUM_EXPIRATION` | Minutes. Defaults: 720 (12 h), 43200 (30 days). |
| Retention | `LOGIN_ACTIVITY_RETENTION_DAYS` | Default 90. |
| Reports | `REPORTS_TIMEZONE` | Time zone whose calendar days Reports counts in, e.g. `Asia/Dhaka` (default `UTC`). Tickets are stored in UTC either way. |
| Attachments | `ATTACHMENT_MAX_SIZE_KB`, `ATTACHMENT_MAX_FILES`, `ATTACHMENT_MAX_REQUEST_KB`, `ATTACHMENT_MAX_TICKET_FILES`, `ATTACHMENT_MAX_TICKET_KB` | Defaults: 5 MB per file, 5 files, 15 MB per request, 25 files and 50 MB per ticket. |
| Queue / ticket emails | `QUEUE_CONNECTION`, `TICKET_NOTIFICATIONS_ENABLED` | `sync` in development; **`database` in production, with a running worker**. The switch (default `true`) turns all ticket emails off. |
| Network | `TRUSTED_PROXIES`, `HSTS_ENABLED`, `HSTS_MAX_AGE` | See the deployment guide. |
| Logging | `LOG_CHANNEL`, `LOG_STACK`, `LOG_LEVEL`, `LOG_DAILY_DAYS` | |

PHP's `upload_max_filesize` and `post_max_size` must be at least as large as the attachment limits, and the command-line PHP that runs `artisan serve` reads a different `php.ini` from the web server's PHP on some systems (notably WAMP). `php artisan helpdesk:preflight` checks this.

The web app has a single variable, `VITE_API_BASE_URL` (the API address including `/api`).

## Database: migrations and seeding

```bash
php artisan migrate               # apply pending migrations
php artisan migrate:status        # see what has run
php artisan db:seed               # roles and permissions (safe to run again)
php artisan helpdesk:create-super-admin
```

- Migrations are additive. **Take a database backup before running `migrate` on any database that holds real data.**
- Seeding creates roles and permissions only. It creates **no users and no sample data**; the first administrator comes from `helpdesk:create-super-admin`.
- Tables: users, roles/permissions (spatie), personal access tokens, audit logs, login activities, departments (and members), tickets, ticket events, ticket messages, ticket attachments.

## Tests, lint and build

**API**

```bash
php artisan test                  # or: composer test (clears cached config first)
vendor/bin/pint --test            # code style check; run without --test to fix
composer audit                    # known vulnerabilities in dependencies
```

The suite runs on in-memory SQLite (see `phpunit.xml`), so it never touches your MySQL data. It needs the `pdo_sqlite`, `gd`, `fileinfo` and `zip` PHP extensions. A full run takes a few minutes.

**Web app**

```bash
npm test                          # Vitest, single run (npm run test:watch to keep watching)
npm run lint                      # oxlint
npm run build                     # production build into dist/
npm audit --omit=dev              # known vulnerabilities in production dependencies
```

Both repositories contain a GitHub Actions workflow (`.github/workflows/ci.yml`) that runs these checks. It has not been run on GitHub yet.

## Attachments: security and storage

Attachments can be added when opening a ticket and to replies and internal notes. Files arrive in the same request as the message, so a message and its files succeed or fail together.

- **Private storage.** Files live on the `attachments` disk at `storage/app/private/attachments/tickets/{ticket id}/{random id}`, outside `public/`. The stored name is server-generated with no extension and nothing the user typed. No public URL exists, and Laravel's built-in file-serving route is switched off for the `attachments` and `local` disks.
- **Allowed types:** `jpg, jpeg, png, webp, gif, pdf, txt, csv, docx, xlsx`. Everything else (including `svg`, `html`, scripts, executables, archives and macro-enabled Office files) is refused.
- **Checked by content, not by name.** The client's file name and MIME type are never trusted. The file's real type is detected on the server and must match the extension; images must decode and stay under a pixel limit; PDFs must start with `%PDF-`; text files must be free of binary data; `.docx`/`.xlsx` must be genuine Office files without macros or path tricks and must not unpack to something enormous. If a file cannot be inspected for any reason it is rejected, never accepted.
- **Limits:** 5 MB per file, 5 files and 15 MB per message, 25 files and 50 MB per ticket, 30 uploads per hour per user. Closed tickets accept no new files.
- **Downloads only through the API:** `GET /api/tickets/{ticket}/attachments/{attachment}` with the Bearer token (the web app fetches the file and saves it; the token is never placed in a URL). The user must be able to see the ticket. Files attached to an **internal note** answer `404` to customers, so they cannot be discovered by guessing ids.
- **Safe delivery.** Downloads are sent as an attachment (never displayed inline) with the verified content type, `X-Content-Type-Options: nosniff`, `Content-Security-Policy: default-src 'none'; sandbox` and `Cache-Control: private, no-store`. Names shown to users are sanitised (no path parts, control or direction-override characters).
- **Consistency.** Every file is inspected before anything is written; files are written inside the database transaction; if anything fails the written files are removed.
- **Backups.** The files are **not** in a database dump. Back up `storage/app/private/attachments` together with the database.

Not done: virus scanning, removal of image metadata (EXIF, e.g. GPS), and editing or deleting an attachment after it is sent.

## Scheduler and cleanup jobs

Laravel's scheduler runs three jobs (`php artisan schedule:list`):

| Job | When | Purpose |
|---|---|---|
| `helpdesk:prune-login-activities` | daily | Delete sign-in history older than `LOGIN_ACTIVITY_RETENTION_DAYS`. |
| `helpdesk:prune-orphan-attachments` | daily | Delete attachment files that no database row points to (left by an interrupted upload or a deleted ticket). Only files older than 24 hours are touched. It also reports database rows whose file is missing, and never deletes those. |
| heartbeat | every minute | Lets `helpdesk:preflight` confirm the scheduler is really running. |

Locally run `php artisan schedule:work`. In production add one cron entry:

```
* * * * * cd /path/to/corporate-helpdesk && php artisan schedule:run >> /dev/null 2>&1
```

Both cleanup commands can be run by hand. `php artisan helpdesk:prune-orphan-attachments --dry-run` lists what would be deleted; `--hours=N` changes the age threshold.

## Queue worker and ticket emails

Emails about ticket activity are queued jobs. Account emails (verification, password reset, invitations) are not: they are sent during the request.

| Event | Who is emailed |
|---|---|
| Ticket opened | The customer (confirmation). If the ticket has **no department**, also the active, verified people holding `ticket.assign` (admins by default). |
| Assigned to an agent | That agent |
| Staff reply | The customer |
| Customer reply | The assignee, or the `ticket.assign` holders if unassigned. If the reply reopens a resolved ticket it is **one** "replied and reopened" email. |
| Internal note | The assignee, if someone else wrote it. **Never the customer.** |
| Resolved, closed, or reopened by staff | The customer |
| Closed or reopened by the customer | The assignee |

Nobody is emailed about their own action (except the customer's own "ticket received" confirmation), nobody inactive or with an unverified email is emailed, and one action sends at most one email to any person. Priority changes and Open ↔ In progress moves send nothing. Emails are sent only after the change has been saved, and are re-checked when they go out (a person who lost access meanwhile gets nothing). A failure of the email system never breaks the ticket operation.

- **Development:** `QUEUE_CONNECTION=sync` (the default in `.env.example`) sends immediately; with `MAIL_MAILER=log` read them in `storage/logs/laravel.log`. If your own `.env` says `database`, run `php artisan queue:listen` in another terminal.
- **Production:** `QUEUE_CONNECTION=database` and a worker kept alive by Supervisor (`php artisan queue:work --tries=3 --max-time=3600`); run `php artisan queue:restart` after each deploy. **Without a worker no ticket email is delivered.** `php artisan helpdesk:preflight` warns about a stalled queue, failed jobs, and `sync`. The Supervisor configuration is in [`docs/deployment.md`](docs/deployment.md).
- Inspect failures with `php artisan queue:failed`, retry with `php artisan queue:retry all`.

## Deployment

Read [`docs/deployment.md`](docs/deployment.md) (checklist, Nginx and PHP-FPM examples, cron, backups, rollback, post-deploy verification) and start from [`.env.production.example`](.env.production.example). Two commands help on the server:

```bash
php artisan helpdesk:preflight                 # fails on unsafe settings (debug on, http URLs, log mailer, upload limits, ...)
php artisan helpdesk:mail-test you@example.org # sends one test email through the configured transport
```

The deployment guide has not yet been followed on a real server.

## Project structure

**API**

```
app/
  Console/Commands/     helpdesk:* commands (super admin, preflight, mail test, cleanup jobs)
  Enums/                roles, ticket status and priority, message type, user status
  Http/
    Controllers/Api/    one controller per area (auth, users, departments, tickets, messages, attachments)
    Middleware/         account-active check, security headers
    Requests/           validation (FormRequests)
    Resources/          JSON shapes returned by the API
  Models/               Eloquent models
  Notifications/        the queued ticket-activity email
  Policies/             authorisation rules (tickets, users, departments, login activity)
  Providers/            rate limiters, trusted proxies, super-admin gate
  Support/              Permissions (single source of names/defaults), TicketWorkflow (status rules),
                        Attachments/ (inspection and storage), Notifications/ (who is emailed, and
                        the one place that sends), Mailing, Preflight
config/helpdesk.php     project settings (token lifetimes, retention, attachment limits, proxies)
database/               migrations, factories, seeders
docs/deployment.md      deployment guide
routes/api.php          all API routes; routes/console.php holds the schedule
tests/Feature/          tests by area (Auth, Users, Departments, Tickets, Attachments, Production)
```

**Web app**

```
src/
  api/          shared Axios client (token, error normalisation, session-expiry handling)
  app/          Redux store and auth slice
  components/   common UI (Button, Modal, Badge, ...) and form controls
  features/     auth, users, departments, tickets, activity: pages, components, API calls, tests
  layouts/      authenticated shell and public layouts
  pages/        dashboard, error pages
  routes/       router and the module list that drives the sidebar and dashboard
  test/         Vitest setup and helpers
```

## Current limitations

- Ticket emails cannot be answered by email, carry no message text, and have no per-user opt-out or digest.
- No real-time updates; a page shows new messages after a reload.
- Role/permission editing and system settings screens are not built.
- Reports have no CSV export or caching, and agent figures follow each ticket's current assignee (assignment history is not replayed). There are no service-level targets (SLA) yet.
- Sign-in tokens are kept in the browser's `sessionStorage` (or `localStorage` with "remember me").
- Account emails are sent during the request; a mail outage is logged and does not change API answers.
- Ticket emails need a queue worker in production; a retried job could, rarely, send a second copy.
