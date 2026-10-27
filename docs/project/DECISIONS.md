# Decisions

One entry per non-obvious technical decision in this fork — deployment
setup and the few code patches it carries on top of upstream. Newest at
the top. Link back here from commit messages when a
decision changes.

## Custom Objects plugin vendored into `plugins/CustomObjectsBundle` and ported to Mautic 7

Needed for the Salesforce integration (see
`docs/specs/2026-10-27-salesforce-integration.md`): non-profile Salesforce
objects (ServiceContract, sales, custom objects) live in Mautic as custom
objects linked to contacts, so they can drive campaigns, segments and
email tokens.

**Source:** `acquia/mc-cs-plugin-custom-objects`, branch `7.x`, commit
`f9c3d1d9` (2025-07-02). Despite the name, that branch still declares
`mautic/core-lib ^5.0` and did not boot on Mautic 7 / Symfony 7.4. It is
copied in (not a Composer dependency, which would refuse the `^5.0`
constraint) and unignored in the root `.gitignore`. **We own this copy
now** — upstream changes have to be merged by hand.

**Port changes (runtime code):**
- Symfony 7 signatures: `DataTransformerInterface::transform()/
  reverseTransform()` (`mixed`), `Command::execute(): int`,
  `Constraint::validatedBy(): string` / `getTargets(): array|string`;
  `CustomField::setIsPublished(): static` to match `FormEntity`.
- `SessionProviderFactory` takes `RequestStack` instead of the removed
  `session` service.
- Legacy service id `mautic.helper.export` (gone from core) re-aliased to
  `ExportHelper` in the plugin's `Config/services.php`. Note the legacy
  `config.php` format treats any argument containing `\` as a literal
  string, so class names can't be used there as references.
- **API Platform 2 ("Core") integration deleted**
  (`DataPersister/`, `Extension/CustomItemListeningExtension.php`,
  `Serializer/ApiNormalizer.php` and their tests). Mautic 7 ships API
  Platform 4, so these could never load; the plugin's
  `/api/v2/custom_*` endpoints are therefore unavailable. The `@ApiResource`
  docblock annotations on entities are left in place (inert). The
  Salesforce sync talks to the plugin's models directly, not over that API.
- Dead `CustomObjectsBundle::installAllTablesIfMissing()` removed (typed on
  the removed `MauticFactory`, not called by Mautic 7). Tables are created
  by `mautic:plugins:reload`, which `docker/entrypoint.sh` already runs on
  every boot.

**Port changes (tests):** `self::$container` → `static::getContainer()`.

**Verified (2026-10-27, local PHP 8.2 + MySQL 8.4):** prod and test
containers compile with the plugin; its 5 console commands register;
functional suite 56 tests — remaining 10 errors / 3 failures are test-code
incompatibilities (PHPUnit 10 removed `getDataSetAsString()`/`getName()`,
non-static data providers, `client->restart()` drops the login in
Mautic 7, fields persisted but not flushed used as collection keys), not
plugin runtime errors. Unit suite still has ~155 errors from mocks of the
removed `MauticFactory` and PHPUnit 10's removed `withConsecutive()` —
test code only, not ported yet.

**Not yet verified:** clicking through the admin UI on a real instance
(create object/fields/items, segment filter, campaign condition, email
token).

## Remote assets forward the click's campaign query params (core patch)

`AssetBundle\Controller\PublicController::remoteRedirectResponse()` used to
redirect to `$entity->getRemotePath()` verbatim, throwing away the whole
query string of the click. So a link like
`/asset/<slug>?utm_source=instagram&sck=bio` tracked the download in Mautic
but handed the destination (a checkout, a Hotmart page, an external file) a
naked URL — no UTMs, no `sck`/`src`, no `gclid`/`fbclid`. Attribution died at
the redirect.

**Fix:** `remoteRedirectResponse()` now merges the incoming query into the
remote URL, which is exactly what the core's own tracked-link redirect
(`PageBundle\Controller\PublicController::redirectAction`, via
`UrlHelper::appendQueryToUrl`) already did — remote assets were the
exception, not the rule.

Rules of the merge:

- Everything the visitor brings is forwarded, **except** Mautic's own
  parameters, listed in `PublicController::INTERNAL_QUERY_PARAMS`
  (`ct`, the clickthrough blob identifying the contact/channel in email
  links, and `stream`). `ct` in particular must not leak to a third-party
  host.
- Params already configured on the asset's remote URL are kept and merged,
  not duplicated; on a key collision **the value from the click wins** over
  the configured one (real campaign origin beats static config).
- A URL fragment stays at the end (`...?utm_source=x#pricing`).
- If nothing survives the filter, the remote URL is redirected to untouched.

**Why a core patch and not a plugin:** `AssetEvents` has no event that
exposes the download response, so there is no hook to rewrite the `Location`
header from outside — only `kernel.response` in a custom bundle this fork
doesn't otherwise need. The patch is ~25 lines in one method and is
upstreamable as-is (it aligns asset redirects with link redirects), which is
the real exit from the rebase-conflict risk: send it to `mautic/mautic`.

**Cookies are explicitly out of scope.** Mautic can only read cookies on its
own domain (`mtc_id`, `mautic_device_id`) and cannot set a cookie on the
destination domain. Anything that needs to cross has to cross as a query
param.

## Single Docker image, three roles via Command override

`docker/Dockerfile` builds one image. EasyPanel's per-app "Command override"
field (Deploy tab) picks the role by passing an argument to
`docker/entrypoint.sh`: `web` (default, Apache), `cron` (supercronic),
`worker` (`messenger:consume` loop). All three read the same env vars.

**Why:** one image to build/version/redeploy instead of three, and the
apps only differ in what they run, not what they contain.

## `config/local.php` is regenerated from env vars on every boot

`docker/render-local-config.php` writes `config/local.php` fresh every time
a container starts, for all three roles. Nothing persists it across
restarts or between containers.

**Why:** EasyPanel/Docker is the source of truth for configuration (env
vars per app), not a file baked into an image or living on a volume. This
also means the Mautic admin UI's "Configuration" screens are **not** where
you change infrastructure-level settings (DB, mailer, site URL) — they get
overwritten on the next restart. Only settings genuinely meant to be
edited by admins through the UI (most of Configuration) are safe there.

## Mail/queue transports are env vars, not `local.php` params

`app/config/config.php` wires `framework.mailer`/`framework.messenger`
straight from `%env(...)%`: `MAUTIC_MAILER_DSN`, `MAUTIC_MESSENGER_DSN_EMAIL`,
`MAUTIC_MESSENGER_DSN_HIT`, `MAUTIC_MESSENGER_DSN_FAILED`. There is no code
path connecting the `mailer_dsn` parameter the admin UI's Email Settings
screen edits to these. Changing SMTP in the UI does nothing to real
delivery in this codebase.

**Why discovered:** the user had this exact problem on a previous Mautic
instance — configured SMTP in the panel, it silently didn't apply. Traced
it to `MailerDsnEnvVarProcessor` reading straight from the OS environment.

**How to apply:** always set `MAUTIC_MAILER_DSN` (and, if using async
queues, `MAUTIC_MESSENGER_DSN_EMAIL`/`_HIT`) as EasyPanel environment
variables on all three apps, never through the Mautic UI.

## `mautic:install`'s "already installed" check needed a workaround

`InstallService::checkIfInstalled()` only checks that `db_driver` and
`site_url` are non-empty in `config/local.php` — it never looks at the
actual database. Since `local.php` is regenerated with those fields on
every boot (see above), this check is always true, which would make
`mautic:install` silently no-op forever, even against a genuinely empty
database.

**Fix:** `docker/is-schema-installed.php` checks for a real table
(`plugins`) directly via PDO, independent of `local.php`. `entrypoint.sh`
uses that instead of trusting `mautic:install`'s own check, and passes
`--db_*` credentials as CLI options (not via `local.php`) so the install
can run even when `local.php` doesn't exist yet.

**Consequence to watch:** EasyPanel's "Tempo de inatividade zero"
(zero-downtime deploy) starts the new container before stopping the old
one. If you redeploy while a first-time install is still running, you get
**two containers racing to install against the same empty database**,
which corrupts the schema. Turn zero-downtime off before the very first
deploy against a fresh database; safe to turn back on once installed.

## `node_modules` must stay in the runtime image

Originally deleted after `composer install`/`npm run build` to shrink the
image (Dockerfile used to `rm -rf node_modules`). This broke the login
page in production: `Mautic\CoreBundle\Helper\AssetGenerationHelper`
rebuilds `media/js/libraries.js` / `media/css/libraries.css` from raw
source files under `node_modules/` **at runtime**, whenever it decides the
merged file is stale — and a freshly-written `config/local.php` on every
boot is enough to trigger that. Deleting `node_modules` after build made
every page needing those assets (login included) throw
`Twig\Error\RuntimeError: These files are missing: .../node_modules/...`.

**Fix:** `docker/Dockerfile` keeps root `node_modules`; only
`plugins/GrapesJsBuilderBundle/node_modules` (its own separate npm project,
build-tool-only) gets removed.

## `imap` PHP extension is not installed

Debian trixie (the base of `php:8.2-apache`'s current tag) dropped
`libc-client-dev`, which the `imap` extension needs to build — it's gone
from the apt repos entirely, not just renamed.

**Why it's fine:** `imap` is only used by Mautic's optional "monitored
mailbox" bounce-handling feature. This deployment uses the
`etailors/mautic-amazon-ses` plugin's SNS callback (`/mailer/callback`)
for bounce/complaint handling instead, which needs no IMAP mailbox at all.
`composer install` needs `--ignore-platform-req=ext-imap` since
`mautic/core-lib` still lists it as a soft requirement.

## `symfony/amazon-mailer` + `etailors/mautic-amazon-ses` moved to `require`

They were in `require-dev` with `symfony/amazon-mailer: ^8.1.5` — a
constraint that can **never** resolve here: v8.1.5 needs PHP >=8.4, and
this app is pinned to PHP 8.2 (`mautic/core-lib: ^7.0` needs it). They also
weren't in `composer.lock` at all (added to `composer.json` by hand,
`composer update` never run), so even in a dev install they'd have been
silently absent. Fixed by moving both to `require` and correcting the
constraint to `^7.4` (matching this app's actual Symfony 7.4 line), then
running a real `composer update` for just those two packages.

**Why require, not require-dev:** SES sending/bounce-handling is a
production concern, and this Dockerfile runs `composer install --no-dev`.

## `symfony/dom-crawler` + `symfony/css-selector` added to `mautic/core-lib`'s `require`

`Mautic\EmailBundle\Validator\ValidEmailLinksValidator` (runs on every
email save) does `new Crawler($html)->filter('a[href]')`, but upstream
only lists `symfony/dom-crawler` in the root `require-dev`, and
`filter()` needs `symfony/css-selector`, which was only pulled in
transitively by dev tooling. Under this image's `composer install
--no-dev`, saving any email 500'd with `Class
"Symfony\Component\DomCrawler\Crawler" not found`.

**Fix:** both added to `app/composer.json` (`mautic/core-lib`, the
package whose runtime code uses them), and `composer update
mautic/core-lib` run. The lock change is purely moving `dom-crawler`,
`css-selector` and `masterminds/html5` from `packages-dev` to
`packages` at the same versions. Upstream bug, upstreamable as a PR.

**Checked:** no other non-test code in `app/bundles`/`plugins` imports
a namespace that's only provided by a `packages-dev` package.

## Migrations metadata table needs an explicit sync after fresh install

`mautic:install`'s final step runs
`doctrine:migrations:version --add --all --no-interaction` to mark all
migrations as already-applied (since a fresh install creates the schema
directly, not migration-by-migration). On a truly empty database this
fails — the migrations metadata storage table doesn't exist yet — logged
as a `console.CRITICAL` but the overall install continues (non-fatal to
`mautic:install`, but leaves migration tracking broken for future
`mautic:update:apply` runs).

**Fix:** `entrypoint.sh` runs
`doctrine:migrations:sync-metadata-storage` immediately before the
`version --add --all` step, on first-time install only.

## MySQL 8.4+ / MariaDB 10.11+ is a hard requirement

Mautic 7's installer explicitly checks and refuses MySQL 8.0 ("too old").
Discovered by testing locally against `mysql:8.0` before switching to
`mysql:8.4`. Make sure any EasyPanel MySQL/MariaDB service is provisioned
at a compatible version *before* creating the Mautic apps.

## Bind-mount only `media/files`, `media/images`, `media/assets` — not all of `media/`

First attempt bind-mounted the whole `/var/www/html/media` directory so
web/cron/worker share uploads. That's wrong: `media/` also holds files
generated **at build time** —`media/js/libraries.js`,
`media/css/libraries.css`, `media/css/offline.css` (the merged/minified
library bundle `AssetGenerationHelper` produces from `node_modules`, see
above). Mounting an empty host directory over the whole tree hides those
build artifacts, forcing Mautic to regenerate them at runtime on every
single boot — slow, and was intermittently hanging requests to `/s/login`
in production.

**Fix:** three separate bind mounts, one each for `media/files`,
`media/images`, `media/assets` (the actual user-upload directories),
leaving the rest of `media/` as whatever the image already built.

## EasyPanel bind mounts need the host directory to exist first

EasyPanel runs Docker in Swarm mode. Unlike plain `docker run`, Swarm does
**not** auto-create a bind mount's source path — it fails deploy with
`invalid mount config for type "bind": bind source path does not exist`.

**Fix:** SSH into the EasyPanel host and `mkdir -p` the path once (e.g.
`/etc/easypanel/mautic-media-<slug>`) before configuring the bind mount in
each app (web/cron/worker all point at the same host path so they share
uploaded media/files).
