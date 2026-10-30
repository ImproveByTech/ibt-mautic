# Decisions

One entry per non-obvious technical decision in this fork — deployment
setup and the few code patches it carries on top of upstream. Newest at
the top. Link back here from commit messages when a
decision changes.

## User uploads live in S3 (`MediaStorage`)

Spec: `docs/specs/2026-10-28-s3-uploads.md`. Setup: `DEPLOYMENT.md`. Goal:
the VPS is disposable - every upload goes to the bucket and the DB keeps
the URL, so a new server only needs the same DB + env vars.

- **Per-client prefix in a shared bucket** (`S3_ROOT_DIRECTORY`), not a
  bucket per client.
- **Off unless all five vars are set** (`S3_BUCKET`, `S3_REGION`,
  `S3_ACCESS_KEY_ID`, `S3_SECRET_ACCESS_KEY`, `S3_ROOT_DIRECTORY`):
  `MediaStorage::isEnabled()`. Otherwise upstream behavior; tests and ddev
  need no S3. Set on web, cron and worker.
- **Public-read only `media/files/*` and `media/images/*`** (bucket
  policy, no object ACLs). `media/dashboards/` stays private and is read
  with the IAM credentials. Form uploads are public too (user's decision,
  LGPD exposure accepted).
- **Assets are pushed on save** and stored as `storageLocation=remote`
  with the canonical S3 URL. Downloads are still tracked by
  `PublicController`, then redirected. Editing such an asset shows
  "Remote" + URL in the form.
- **Admin preview of a remote asset redirects** instead of fetching
  server-side (SSRF / `file://` read); `Asset::getFileContents()` stays
  upstream.
- **Random 16-hex name prefix** for form uploads and builder images: an S3
  key must never be shared by two uploads (client file names are not unique
  once local dirs are emptied; same-key overwrite would change sent
  emails). Dashboards keep readable, user-scoped names (same-user overwrite
  is upstream's behavior).
- **elFinder `upload_overwrite=false`** (auto-rename), also in local mode.
  elFinder uses a small custom Flysystem adapter over `MediaStorage`
  (scoped to `media/images/`) instead of `league/flysystem-aws-s3-v3`:
  Composer was unavailable when this was built and hand-editing
  `composer.lock` was risky. It can be swapped for the official adapter
  later.
- **Content-type hardening** in `MediaStorage`: inline only
  png/jpeg/gif/webp/pdf; svg keeps its type but is `attachment`;
  html/xml/js become `application/octet-stream` + `attachment`; anything
  else is `attachment`. Anonymous visitors can upload via forms, so the
  public bucket must never serve active content inline.
- **Avatars are content-checked** with `getimagesize()` (also with S3 off:
  local avatars live on Mautic's own origin, so an HTML body there is a
  worse XSS vector).
- **Deletes**: only under our `<root>/media/` prefix (`keyFromPublicUrl`
  is the "is this ours?" check; user-typed remote URLs are never touched)
  and only when no other asset shares the key. S3 failures after an
  irreversible DB delete are logged, not thrown. A failed S3 write always
  surfaces as an error - never a "successful" upload left on local disk.
- **No migration**: files uploaded before the feature was on keep working
  on that server but are not copied to S3 (and not carried to a new VPS).

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

`docker/render-local-config.php` runs every time a container starts, for
all three roles. It **merges** into the existing `config/local.php`: the
infrastructure keys it manages (DB, `site_url`, `secret_key`, mailer
from, API flags, trusted proxies/hosts) are forced from env vars, and
every other key already in the file is kept. `config/` is a bind mount
shared by `web`, `cron` and `worker` (same host path), so it survives
redeploys and all three roles see the same file.

**Why:** EasyPanel/Docker env vars stay the source of truth for
infrastructure settings — editing those in the admin UI's Configuration
screen gets overwritten on the next restart. But Mautic stores *all* of
its UI-editable Configuration (default language, allowed asset
extensions, ...) in that same `local.php`, so the file itself has to
persist. The original version of this script overwrote the file from
scratch with no mount, which silently wiped every UI setting on each
redeploy (see TROUBLESHOOTING, "Configuration changes disappear").

A few UI settings can also be **pinned per client** from optional env vars
(`MAUTIC_LOCALE`, `MAUTIC_DEFAULT_TIMEZONE`, `MAUTIC_ASSET_MAX_SIZE`,
`MAUTIC_ASSET_ALLOWED_EXTENSIONS`). Set → forced on every boot like the
infra keys; unset → the UI owns it. Pinning was chosen over "default only
if missing" because Mautic writes *every* field of every tab to
`local.php` on the first Save, so a fill-if-missing default would stop
applying the moment anyone saves the Configuration screen.

Two things ride along so a pinned value actually works:
- `MAUTIC_LOCALE` → the **pt_BR pack is committed** in
  `translations/pt_BR/` (~830 KB, `.gitignore` exception), so it's in the
  image with no network dependency at boot. For any other locale,
  `docker/install-language-pack.php` downloads the pack from
  `language-packs.mautic.com` on every boot (`translations/` isn't
  persisted; same source/layout as the UI download). To refresh pt_BR:
  `rm -rf translations/pt_BR && MAUTIC_LOCALE=pt_BR php docker/install-language-pack.php`, then commit.
- `MAUTIC_ASSET_MAX_SIZE` above 32 → `entrypoint.sh` writes
  `zzz-mautic-upload.ini` raising PHP's `upload_max_filesize`/
  `post_max_size` (named `zzz-` so it loads after `zz-mautic.ini`).

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

## New translatable content defaults to the configured language

Upstream's `TranslationEntityTrait` hardcodes `$language = 'en'` for
landing pages, emails, dynamic content, SMS and notifications, so the
"Idioma" field on a new item always showed English even with Default
language = `pt_BR`. `CoreBundle\Form\Extension\DefaultTranslationLanguageExtension`
(a `FormType` extension, auto-registered) swaps that `'en'` for the
`locale` parameter on `PRE_SET_DATA`, only for **new** entities still on
the hardcoded `'en'`; existing items, explicit choices and English
instances are untouched. Chosen over patching the five models'
`getEntity()` so it's one small, upstreamable file with a unit test.
