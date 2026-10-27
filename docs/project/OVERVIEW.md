# Project Overview

**ImproveByTech/ibt-mautic** (branch `7.x`) — a Mautic 7 fork whose goal is
a **bidirectional integration between Mautic and Salesforce**.

This file is the always-loaded entry point for AI agents (see `CLAUDE.md`).
It's meant to be short. For depth, follow the links below instead of
duplicating detail here.

- **`docs/specs/2026-10-27-salesforce-integration.md`** — the main initiative: scope, approach, open questions. **Start here.**
- **`docs/project/DECISIONS.md`** — why things are built the way they are, one entry per decision.
- **`docs/project/DEPLOYMENT.md`** — EasyPanel runbook inherited from the previous fork (see "Origin" below).
- **`docs/project/TROUBLESHOOTING.md`** — incidents hit so far, symptom-first.
- **`docs/specs/`** — specs for features/changes being planned. One file per initiative.
- **`docs/brainstorm/`** — freeform exploration notes, not yet a spec.
- **`AGENTS.md`** — upstream Mautic's own contributor guide (ddev, tests, coding standards). Keep this aligned with upstream; put fork-specific stuff here in `docs/` instead.

## Goal

Keep Mautic and Salesforce in sync in **both directions**:

- **Mautic → Salesforce**: leads/contacts, companies, points/stage and
  marketing activity (email opens/clicks, form submits, page hits) pushed
  to Salesforce so sales sees engagement.
- **Salesforce → Mautic**: leads/contacts/accounts and their updates pulled
  into Mautic so campaigns/segments run on CRM data.

## Approach

Two layers, in this order of preference:

1. **Native plugin first** — Mautic ships a Salesforce integration in
   `plugins/MauticCrmBundle/` (`Integration/SalesforceIntegration.php`,
   `Api/SalesforceApi.php`, plus `Integration/Salesforce/` and
   `Api/Salesforce/`). Use and configure it for everything it already
   covers.
2. **Custom integrations on top** — whatever the native plugin can't do
   (extra objects, custom field mappings, business rules, conflict
   handling, triggers) goes into this fork's own code. Prefer a separate
   IBT plugin under `plugins/` (built on `app/bundles/IntegrationsBundle`
   where it fits) over patching `MauticCrmBundle` directly, so upstream
   updates stay mergeable. Any core patch that is unavoidable must be
   recorded in `docs/project/DECISIONS.md`.

Non-profile Salesforce objects (ServiceContract, sales, custom objects)
are stored as **Custom Objects** — `plugins/CustomObjectsBundle`, Acquia's
plugin vendored and ported to Mautic 7 by us (we own that copy; see
`docs/project/DECISIONS.md`). Its API Platform REST endpoints were removed
in the port.

The details, gaps and open questions live in the spec linked above.

## Origin (inherited, reference only)

This repo was cloned from `impulsionantedigital/mautic` on 2026-10-27. That
fork was a Mautic deployment template for EasyPanel serving multiple client
instances (`trilhasdearuanda.com.br`, `gpsdapena.com.br`). **Those clients
are not part of this project.** What carries over is the code on top of
stock Mautic 7, which still applies here:

1. **`docker/` deployment**: a single `php:8.2-apache` image that runs as
   `web` (Apache), `cron` (supercronic), or `worker` (`messenger:consume`)
   depending on EasyPanel's "Command override" field. See
   `docs/project/DECISIONS.md`.
2. **Amazon SES plugin in `require`**: `etailors/mautic-amazon-ses` +
   `symfony/amazon-mailer`. Mail transport and messenger queues are
   controlled purely by env vars (`MAUTIC_MAILER_DSN`,
   `MAUTIC_MESSENGER_DSN_EMAIL`, `MAUTIC_MESSENGER_DSN_HIT`,
   `MAUTIC_MESSENGER_DSN_FAILED`), **not** by Mautic's admin UI.
3. **Remote assets forward campaign query params** (`utm_*`, `sck`,
   `gclid`, …): small core patch to `AssetBundle\Controller\PublicController`.
4. **`symfony/dom-crawler` + `symfony/css-selector` as runtime deps** of
   `mautic/core-lib` — without them every email save 500s under `--no-dev`.

Lessons from those deployments that still matter if this project is hosted
the same way: async messenger broke email delivery and was reverted to
`sync://` (root cause open — see TROUBLESHOOTING); no Docker `HEALTHCHECK`
yet; backups were never automated. Sync jobs with Salesforce run from
`cron`, so the `cron` role's health matters more here than it did before.

## Platform requirements (non-negotiable)

- MySQL **8.4.0+** or MariaDB **10.11.0+** — 8.0 is rejected by the installer.
- PHP 8.2 (pinned by `mautic/core-lib`).

## Current state (update this as things change)

- 2026-10-27: repo moved to `ImproveByTech/ibt-mautic`, project redefined
  around the Salesforce integration. Nothing Salesforce-specific built yet;
  next step is evaluating the native plugin against the spec.
- 2026-10-27: Custom Objects plugin installed and ported to Mautic 7 —
  boots and passes its functional tests apart from test-code issues;
  not yet exercised through the admin UI.
- Hosting/environment for this project not yet defined (local ddev for
  development; production target TBD).
