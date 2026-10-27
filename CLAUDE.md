# CLAUDE.md

**Idioma:** sempre converse com o usuário em português do Brasil (pt-BR).

Read and follow all instructions in `./AGENTS.md` — it's upstream Mautic's
own contributor guide (ddev, tests, coding standards). Keep it aligned
with upstream; it's the single source of truth for *core Mautic
development* conventions.

This fork's goal is a **bidirectional Mautic ↔ Salesforce integration**
(native `MauticCrmBundle` Salesforce plugin + custom IBT integrations on
top). For everything specific to this fork — the goal, why it's built the
way it is, and what's broken before and how it got fixed — **read
`docs/project/OVERVIEW.md` first**, then the active spec
`docs/specs/2026-10-27-salesforce-integration.md`. OVERVIEW is short on
purpose and links out to:

- `docs/project/DEPLOYMENT.md` — EasyPanel step-by-step runbook.
- `docs/project/DECISIONS.md` — why things are the way they are.
- `docs/project/TROUBLESHOOTING.md` — every incident hit so far, symptom-first.
- `docs/specs/` and `docs/brainstorm/` — planning space for new work; check
  `docs/specs/` for anything in flight before starting new work, and use
  these folders for any new feature/initiative instead of one-off chat-only
  planning.

When you make a deployment-relevant decision, land a fix for a real
incident, or ship a spec, update the relevant file above in the same
change — that's what keeps this loop working.