# Deployment runbook (EasyPanel)

Consolidated, corrected step-by-step. If something here contradicts an
earlier chat message, this file wins — it reflects everything learned
from actually deploying, including fixes made after mistakes.

See `docs/project/DECISIONS.md` for the *why* behind each step, and
`docs/project/TROUBLESHOOTING.md` if something breaks.

## 0. Prerequisites

- A MySQL **8.4+** or MariaDB **10.11+** service (8.0 is rejected by the
  installer).
- SMTP or SES credentials for outbound mail.
- A domain for the app.

## 1. Database

Add a MySQL/MariaDB service in the EasyPanel project (a managed service —
it handles its own persistence, no manual volume needed). Create a
database + user with full privileges on it. Note the service name (used as
`MAUTIC_DB_HOST` — services in the same EasyPanel project reach each other
by service name).

## 2. Create the "web" app

- **New App** → Source: Git → this repo, branch `7.x`.
- Build: **Dockerfile**, path `docker/Dockerfile`.
- **Domains**: your domain, target port `80`.
- **Deploy tab**: Replicas `1`. **Turn "Tempo de inatividade zero"
  (zero-downtime) OFF for the first deploy** — turn it back on only after
  the app is confirmed installed and stable (see Decisions: two containers
  installing at once).
- **Environment**:
```
MAUTIC_SITE_URL=https://mautic.yourdomain.com
MAUTIC_SECRET_KEY=<stable random string, e.g. `openssl rand -hex 32`>
MAUTIC_DB_HOST=<mysql service name>
MAUTIC_DB_PORT=3306
MAUTIC_DB_NAME=mautic
MAUTIC_DB_USER=mautic
MAUTIC_DB_PASSWORD=<password>
MAUTIC_MAILER_DSN=<smtp://... or mautic+ses+api://...>
MAUTIC_MAILER_FROM_NAME=Your Brand
MAUTIC_MAILER_FROM_EMAIL=no-reply@yourdomain.com
MAUTIC_ADMIN_EMAIL=you@yourdomain.com
MAUTIC_ADMIN_PASSWORD=<strong password, avoid quotes/backticks/$>
```
  `MAUTIC_ADMIN_EMAIL`/`_PASSWORD` only matter on the very first install
  (creating the admin user) — safe to remove after.

  **Optional, pins admin Configuration-screen settings** (set on all three
  apps). Each one, when set, is re-applied on every boot and wins over
  whatever was saved in the UI; leave it unset to manage that setting
  through the UI instead. API enabled is already `MAUTIC_API_ENABLED`
  (default `true`).
```
MAUTIC_LOCALE=pt_BR                        # pt_BR pack ships in the image
MAUTIC_DEFAULT_TIMEZONE=America/Sao_Paulo  # PHP timezone id, not "sao paulo"
MAUTIC_ASSET_MAX_SIZE=254                  # MB; also raises PHP's upload limit
MAUTIC_ASSET_ALLOWED_EXTENSIONS=csv,doc,docx,epub,gif,jpg,jpeg,mpg,mpeg,mp3,odt,odp,ods,pdf,png,ppt,pptx,tif,tiff,txt,xls,xlsx,wav,html,htm,shtml
```
- **Storage**: add **four separate Bind mounts** — `config/` (holds
  `local.php`, i.e. everything saved in the admin Configuration screen)
  plus `media/files`, `media/images`, and `media/assets` individually.
  Without the `config/` mount, Configuration changes (default language,
  allowed asset extensions, ...) are lost on every redeploy and aren't
  seen by `cron`/`worker`. **Do not** bind-mount
  the whole `/var/www/html/media` directory: it also holds files generated
  at build time (`media/js/libraries.js`, `media/css/libraries.css`,
  `media/css/offline.css`), and mounting an empty host directory over the
  whole tree hides those, forcing a slow/broken runtime regeneration on
  every boot (see `docs/project/TROUBLESHOOTING.md`). **Before saving**,
  SSH into the EasyPanel server and run:
  ```bash
  mkdir -p /etc/easypanel/mautic-media-<slug>/{files,images,assets}
  chmod -R 777 /etc/easypanel/mautic-media-<slug>
  mkdir -p /etc/easypanel/mautic-config-<slug>
  chown 33:33 /etc/easypanel/mautic-config-<slug>   # www-data; holds DB password, so no 777
  chmod 770 /etc/easypanel/mautic-config-<slug>
  ```
  Swarm bind mounts fail if the host path doesn't already exist. Then add:

  | Host | Container |
  |---|---|
  | `/etc/easypanel/mautic-config-<slug>` | `/var/www/html/config` |
  | `/etc/easypanel/mautic-media-<slug>/files` | `/var/www/html/media/files` |
  | `/etc/easypanel/mautic-media-<slug>/images` | `/var/www/html/media/images` |
  | `/etc/easypanel/mautic-media-<slug>/assets` | `/var/www/html/media/assets` |
- **Deploy**, and don't touch anything else until it finishes. Watch the
  logs for: `No Mautic schema found, running first-time install...` →
  `Install complete` → plugin reload → `Warming cache` → `Starting Apache`.

## 3. Create the "cron" app

- Same repo/branch/Dockerfile.
- **Deploy tab → Comando**: `/usr/local/bin/entrypoint.sh cron` (the bare word `cron` fails - see TROUBLESHOOTING.md)
- Same env vars as web, minus `MAUTIC_ADMIN_*`.
- Same four bind mounts, same host paths as web.
- No domain. Deploy.
- Expect, every minute: `job succeeded` for `mautic:messages:send`,
  `mautic:broadcasts:send`, `mautic:reports:scheduler` in the logs.

## 4. Create the "worker" app

- Same repo/branch/Dockerfile.
- **Comando**: `/usr/local/bin/entrypoint.sh worker`
- Same env vars (minus admin), same four bind mounts.
- No domain. Deploy.
- Expect: `Consuming messages from transport "failed".` — it stays quiet
  unless something fails (or unless async transports are turned on, see
  below).

## 5. Post-deploy checks

- Log into `https://mautic.yourdomain.com` with the admin account.
- **Settings → Plugins**: confirm "Amazon SES" is listed (installed
  automatically by `mautic:plugins:reload` on every web boot).
- If using the SES API DSN (`mautic+ses+api://...`), point an SNS topic's
  HTTPS subscription at `https://mautic.yourdomain.com/mailer/callback`
  for bounce/complaint handling.
- Test real sending from the web app's Terminal:
  ```bash
  php bin/console mailer:test you@yourdomain.com --env=prod
  ```
  (Tests the actual configured transport — more reliable than the
  panel's own "Test Send" button, which per `docs/project/DECISIONS.md`
  isn't wired to the same code path in some cases.)

## S3 media storage (user uploads)

Optional. When on, uploads (assets, builder images, avatars, form files,
dashboards) go to S3 instead of the VPS disk. Set these on **all three
apps** (web, cron and worker; the worker sends emails with attachments):
```
S3_BUCKET=mautic-selfhosted
S3_REGION=sa-east-1
S3_ACCESS_KEY_ID=<IAM user key>
S3_SECRET_ACCESS_KEY=<IAM user secret>
S3_ROOT_DIRECTORY=gpsdapena        # one per client, e.g. trilhasdearuanda
```
The feature is **on only when all five are set**; otherwise Mautic uses the
local disk exactly like upstream. Removing a variable turns it off on the
next boot. Public URL of an object:
`https://<bucket>.s3.<region>.amazonaws.com/<root>/media/...`.

- **Only uploads made after the feature is turned on** go to S3. Files
  already on disk are not migrated; they keep working on that server only.
- **Bucket**: in the bucket's "Block Public Access" settings, "Block public
  access to buckets and objects granted through *new* public bucket or
  access point policies" (and the "any" variant) must be OFF so the policy
  below can take effect. No object ACLs are used.
- **Bucket policy** (public read of `media/` only; `database/` and anything
  else stays private). Replace `mautic-selfhosted`:
```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "PublicReadMautic",
      "Effect": "Allow",
      "Principal": "*",
      "Action": "s3:GetObject",
      "Resource": "arn:aws:s3:::mautic-selfhosted/*/media/*"
    }
  ]
}
```
- **IAM user** needs `s3:PutObject`, `s3:GetObject`, `s3:DeleteObject` on
  `arn:aws:s3:::<bucket>/<root>/*` and `s3:ListBucket` on
  `arn:aws:s3:::<bucket>` (optionally limited with an `s3:prefix`
  condition to `<root>/*`).

## Mailer DSN reference

**Generic SMTP** (works with any SMTP credentials, including AWS SES SMTP /
Mail Manager SMTP users):
```
MAUTIC_MAILER_DSN=smtp://USERNAME:PASSWORD@HOST:587
```

**Amazon SES via API** (needs a real IAM Access Key/Secret — **not** SMTP
credentials; the plugin's own docs warn against reusing an SMTP user here):
```
MAUTIC_MAILER_DSN=mautic+ses+api://ACCESS_KEY:SECRET_KEY@default?region=us-east-1&ratelimit=14
```
IAM policy needed: `ses:SendEmail`, `ses:SendRawEmail`, `ses:GetSendQuota`.

## Async sending (currently ON in production)

By default `MAUTIC_MESSENGER_DSN_EMAIL`/`_HIT` fall back to `sync://`
(immediate, in-request sending — the `worker` app has nothing to do). This
fork currently has them set to the async DSNs below on all three apps, so
`web`/`cron` enqueue instead of sending inline, and `worker` does the real
SMTP send. Confirm with the worker's logs:
`Consuming messages from transports "email, hit, failed"`.

To (re)enable, set on **all three apps**:
```
MAUTIC_MESSENGER_DSN_EMAIL=doctrine://default?queue_name=email
MAUTIC_MESSENGER_DSN_HIT=doctrine://default?queue_name=hit
```
This reuses the existing MySQL — no Redis/RabbitMQ needed.

## Scaling web beyond 1 replica

Not yet safe: sessions are file-based on local disk. Would need a shared
session store (Redis) first. Not implemented in this fork yet — candidate
for `docs/specs/` if needed.
