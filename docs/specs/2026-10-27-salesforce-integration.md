# Mautic ↔ Salesforce integration

Status: **draft** — scope agreed (2026-10-27), sync design proposed,
details open.

## Problem

Marketing runs in Mautic and sales/operations in Salesforce. Without sync,
sales doesn't see marketing engagement, and Mautic campaigns can't react
to what happens in Salesforce (a service contract signed, a sale closed,
etc.).

## Goal

1. **Profile sync, bidirectional** — Lead, Contact, Account (and their
   custom fields) kept in sync between Salesforce and Mautic contacts/
   companies.
2. **Non-profile objects as automation triggers** — other Salesforce
   objects (e.g. `ServiceContract`, Opportunity/"sale", Order, custom
   objects `__c`) can **start or branch Mautic campaigns** when they are
   created/updated, and their fields can be used as **merge fields
   (tokens)** in emails/SMS/pages.
3. **Mautic → Salesforce**: points, stage and marketing activity (email
   open/click, form submit, page hit) pushed so sales sees engagement.

Non-goals (for now): replacing Salesforce reporting; mirroring every
Salesforce object — only objects explicitly configured.

## Decisions (agreed)

- **Salesforce sandbox available** for development.
- **Salesforce wins on conflict.** For a field mapped both ways, Mautic
  never overwrites a non-empty Salesforce value. Mautic only writes to
  Salesforce fields it *owns* (points, stage, engagement/activity fields)
  or to fields that are empty in Salesforce. Field ownership is declared
  per field in the mapping.
- **Salesforce → Mautic should be near real time** (update Mautic
  whenever a Salesforce object changes).
- Native `MauticCrmBundle` Salesforce plugin first; gaps go into a
  separate IBT plugin under `plugins/` (no patching `MauticCrmBundle`
  unless unavoidable, and then logged in `docs/project/DECISIONS.md`).

## Approach

### 1. Profile sync (Lead / Contact / Account)

Configure the native plugin on dev against the sandbox: OAuth, field
mapping, `mautic:integration:fetchleads` (pull) and
`mautic:integration:pushleadactivity` (activity push) — both confirmed to
exist on 7.x in `app/bundles/PluginBundle/Command/`. Record what it covers
and where it falls short (it is **polling only**, so it doesn't meet the
near-real-time goal by itself — see section 3).

### 2. Non-profile objects (triggers + merge fields)

Mautic has no native place to store arbitrary Salesforce objects, so they
need a data model in Mautic linked to the contact:

- **Option A — Custom Objects plugin** (`acquia/mc-cs-plugin-custom-objects`,
  not installed today): gives custom objects linked to contacts, segment
  filters, campaign conditions and email tokens out of the box. **Must
  verify Mautic 7 compatibility before choosing it.**
- **Option B — own tables in the IBT plugin**: store configured objects
  (Salesforce Id, object type, linked contact/company, fields as JSON or
  mapped columns) and implement ourselves:
  - campaign **trigger/decision** "Salesforce object created/updated"
    (filter by object type and field conditions, e.g.
    `ServiceContract.Status = Activated`);
  - campaign **conditions** on object fields;
  - **tokens** like `{sfobject=ServiceContract:EndDate}`.

For merge fields, a contact can have many records of the same object
(1:N), so tokens must resolve to a defined record: the one that fired the
campaign when there is one, otherwise the most recently modified.

**Chosen: Option A** (2026-10-27). The plugin is vendored at
`plugins/CustomObjectsBundle` and ported to Mautic 7 (details and what is
still unverified in `docs/project/DECISIONS.md`). Its REST API (API
Platform 2) was removed in the port, so the sync writes through the
plugin's models. Still to check in the UI: campaign conditions and
tokens cover the "trigger" and "merge field" needs, or we add a campaign
trigger in the IBT plugin.

### 3. Sync timing (proposal)

#### Salesforce → Mautic: event-driven + safety net

- **Real time**: a record-triggered **Flow + Outbound Message** in
  Salesforce for each configured object (create/update) calls a webhook
  endpoint in the IBT plugin. The endpoint only validates and **queues**
  the change; a job processes it (upsert contact/object, fire campaign
  triggers). Outbound Messages retry automatically for up to 24h if
  Mautic is down and need no extra Salesforce licence.
- **Reconciliation**: a cron job every ~15 min queries each object by
  `SystemModstamp > last run` to catch anything the webhook missed.
- Later, if volume grows: **Change Data Capture / Pub/Sub API** instead
  of Outbound Messages (needs a long-running subscriber — the `worker`
  role).

#### Mautic → Salesforce: batched

- Profile changes that matter (form submit, points threshold, stage
  change, new contact) are queued and pushed every **1–5 min** by cron.
- Activity timeline pushed every **15 min** in bulk.
- Batching keeps us within the org's daily API limits.

Note: the queues above should run on cron + DB table, not on Symfony
async messenger, until the messenger delivery issue in
`docs/project/TROUBLESHOOTING.md` is understood.

## Open questions

- Exact list of non-profile objects for phase 1 — is "sale" Opportunity,
  Order, or a custom object? Which `ServiceContract` fields/statuses
  matter?
- How each object links to the Mautic contact (`ContactId`, `AccountId`,
  lookup on a custom object?) and the matching key between systems
  (Salesforce Id vs email).
- Salesforce edition and expected volumes (records/day, API limit).
- Lead → Contact conversion in Salesforce: how the Mautic contact follows.
- Deletes/merges in either system: propagate or ignore?
- Where this runs in production (hosting not defined yet) — the webhook
  needs a public HTTPS URL reachable from Salesforce.
