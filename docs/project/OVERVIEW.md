# Project Overview

This is the **impulsionantedigital/mautic** fork (branch `7.x`), running on
**EasyPanel** (self-hosted Docker/Swarm PaaS) as three containers built from
one image: `docker/Dockerfile`.

**This same codebase/image is reused as a template for multiple separate
client Mautic instances** (own domain, own DB, own set of `web`/`cron`/
`worker` apps per client - e.g. `trilhasdearuanda.com.br`,
`gpsdapena.com.br`), sharing one AWS SES SMTP user across clients. When
deploying a new client on this stack, see
`docs/project/DEPLOYMENT.md` and note the per-client gotcha in
`docs/project/TROUBLESHOOTING.md` about SES sender verification being
per-domain.

This file is the always-loaded entry point for AI agents (see `CLAUDE.md`).
It's meant to be short. For depth, follow the links below instead of
duplicating detail here.

- **`docs/project/DEPLOYMENT.md`** — step-by-step EasyPanel setup, env vars, first deploy.
- **`docs/project/DECISIONS.md`** — why things are built the way they are, one entry per decision.
- **`docs/project/TROUBLESHOOTING.md`** — every real incident hit in production so far, with root cause and fix.
- **`docs/specs/`** — specs for features/changes being planned. One file per initiative.
- **`docs/brainstorm/`** — freeform exploration notes, not yet a spec.
- **`AGENTS.md`** — upstream Mautic's own contributor guide (ddev, tests, coding standards). Keep this aligned with upstream; put fork-specific stuff here in `docs/` instead.

## What this fork adds on top of stock Mautic 7

1. **`docker/` deployment**: a single `php:8.2-apache` image that runs as
   `web` (Apache), `cron` (supercronic), or `worker` (`messenger:consume`)
   depending on EasyPanel's "Command override" field. See
   `docs/project/DECISIONS.md` for why each role works the way it does.
2. **Amazon SES plugin enabled for production**: `etailors/mautic-amazon-ses`
   + `symfony/amazon-mailer` moved from `require-dev` to `require` in
   `composer.json` (they were unreachable in a `--no-dev` prod install and
   weren't even in `composer.lock`). Mail transport and messenger queues are
   controlled purely by environment variables (`MAUTIC_MAILER_DSN`,
   `MAUTIC_MESSENGER_DSN_EMAIL`, `MAUTIC_MESSENGER_DSN_HIT`,
   `MAUTIC_MESSENGER_DSN_FAILED`), **not** by Mautic's admin UI — that UI
   field writes to a `local.php` parameter nothing in this codebase reads
   for actual sending.
3. **Remote assets forward campaign query params**: a small core patch to
   `AssetBundle\Controller\PublicController` so that clicking a remote
   asset carries `utm_*`, `sck`, `gclid` etc. through to the destination
   instead of dropping them at the redirect (Mautic's own `ct`/`stream` are
   stripped). See `docs/project/DECISIONS.md`; upstreamable as a PR.
4. **`symfony/dom-crawler` + `symfony/css-selector` as runtime deps** of
   `mautic/core-lib` (`app/composer.json`) — upstream only has them in
   `require-dev`, which made every email save 500 under `--no-dev`. See
   `docs/project/DECISIONS.md`; upstreamable as a PR.

5. **New landing pages/emails/dynamic content/SMS/notifications default to
   the instance's default language** instead of upstream's hardcoded `en`:
   `CoreBundle\Form\Extension\DefaultTranslationLanguageExtension`. See
   `docs/project/DECISIONS.md`; upstreamable as a PR.

6. **User uploads live in S3 when `S3_*` is set**: assets, builder
   images, contact avatars, form files and dashboards go to the bucket
   instead of the VPS disk; off (plain upstream behavior) unless all five
   vars are set. See `docs/project/DEPLOYMENT.md` and
   `docs/project/DECISIONS.md`.

## Platform requirements (non-negotiable)

- MySQL **8.4.0+** or MariaDB **10.11.0+** — 8.0 is rejected by the installer.
- PHP 8.2 (pinned by `mautic/core-lib`).

## Current state (update this as things change)

- Live and working at `https://mautic.trilhasdearuanda.com.br/` as of
  2026-09-05, after a server reformat + clean redeploy. Custom domain DNS
  and the media bind-mount split (see Decisions/Troubleshooting) were the
  last two blockers, both resolved.
- All three apps (`web`, `cron`, `worker`) are created and confirmed
  running: cron jobs succeed every minute/5 minutes, real email delivery
  tested and working (`mailer:test`).
- **Async messenger tried, then turned back off (2026-09-05).**
  `MAUTIC_MESSENGER_DSN_EMAIL`/`_HIT` were set to
  `doctrine://default?queue_name=...` on all three apps, and `worker`
  logs did confirm `Consuming messages from transports "email, hit,
  failed"` — but test sends stopped arriving. Switching back to `sync://`
  (removing/unsetting both vars) fixed delivery immediately. **Root cause
  not yet diagnosed** — see `docs/project/TROUBLESHOOTING.md` for what's
  known. Currently running in **sync mode**: `web`/`cron` send inline,
  `worker` sits idle on the `failed` transport only, matching the original
  default deploy. Don't re-enable async without checking `worker` logs
  and the `messenger_messages` table during the test this time.
- **Configuration-screen settings now persist (2026-10-28)**: they used to
  vanish on every redeploy because `config/local.php` was rewritten from
  env vars only. Fixed in `docker/render-local-config.php` (merge) +
  a new `config/` bind mount on all three apps - existing client
  deployments must add that mount by hand, see Troubleshooting.
- No Docker `HEALTHCHECK` yet — zero-downtime redeploys can have a brief
  window where the domain shows "Service is not reachable" between the
  old container stopping and the new one finishing boot. See
  `docs/project/TROUBLESHOOTING.md`.
- Second client (`gpsdapena.com.br`) deployed on the same pattern; test
  email initially failed there (unverified SES sending domain, unrelated
  to this codebase - see Troubleshooting), fixed by verifying the domain
  in AWS SES.
- **Backups: deferred, not yet automated (2026-09-06).** DB backup has a
  native path (the MySQL service's own "Cópias de segurança" tab in
  EasyPanel - not yet configured). File backup for `media/files`,
  `media/images`, `media/dashboards` (real user data - not `media/assets`,
  that one's a disposable build artifact, see Decisions) needs a
  **host-level** `aws s3 sync` cron job, not EasyPanel's built-in "Criar
  Backup de Volume" - confirmed by testing that EasyPanel's "Volume"
  mount type does **not** share storage across apps even with an
  identical name (each app gets its own isolated volume), which would
  break the web/cron/worker sharing these Bind mounts provide. A full
  script + host crontab setup was worked out (AWS CLI on the host, a
  dedicated `mautic-backup` profile, `aws s3 sync` per client site) and
  is ready to hand over again whenever this gets picked back up - ask for
  it; it's intentionally not a repo file since it's host ops commands
  with credentials, not application code.
- An IAM access key for a `mautic` S3 user (bucket `mautic-selfhosted`,
  `sa-east-1`) was shared in chat while planning the backup above. If
  backups get set up later using that key, rotate it in IAM first since
  it passed through a chat transcript.
