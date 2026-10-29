# S3 Uploads Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every user upload (assets, builder images, contact avatars, form files, user dashboards) is stored in S3 under `<S3_ROOT_DIRECTORY>/media/...`, so the VPS holds no user files.

**Architecture:** One core service, `Mautic\CoreBundle\Storage\MediaStorage`, wraps the AWS SDK S3 client and knows the per-client root directory. It is disabled (every call site keeps upstream behavior) unless all five `s3_*` parameters are set. Each upload surface keeps writing to its local temp/upload dir first (so validation, mime detection and the JPG rotation fix keep working), then pushes the file to S3, deletes the local copy, and stores or returns the S3 public URL.

**Tech Stack:** PHP 8.2, Mautic 7 / Symfony 7, `aws/aws-sdk-php` 3.x (already a runtime dependency via `mautic/core-lib`), `league/flysystem` 3.36 (+ new `league/flysystem-aws-s3-v3` ^3 in Task 7 only), PHPUnit.

**Spec:** `docs/specs/2026-10-28-s3-uploads.md`. Read it first.

## Global Constraints

- Env vars: `S3_BUCKET`, `S3_REGION`, `S3_ACCESS_KEY_ID`, `S3_SECRET_ACCESS_KEY`, `S3_ROOT_DIRECTORY`. Mautic parameters: `s3_bucket`, `s3_region`, `s3_access_key_id`, `s3_secret_access_key`, `s3_root_directory`.
- The feature is on only when **all five** are non-empty. Otherwise behavior is byte-for-byte upstream, and existing tests pass untouched.
- S3 keys are `<root>/<relative key>`. The relative keys are `media/files/<path>`, `media/images/<name>`, `media/images/lead_avatars/avatar<id>`, `media/files/form/<formId>/<fieldId>/<name>`, and `media/dashboards/<userId>/<name>.json`.
- Public URL: `https://<bucket>.s3.<region>.amazonaws.com/<root>/<key>`, with each path segment `rawurlencode`d.
- No object ACLs. Public read comes from the bucket policy.
- Only objects under our own `<root>/media/` may ever be deleted. Never delete based on a URL that isn't ours.
- A failed S3 write must surface as an error. It must never leave a "successful" upload that exists only on local disk.
- New Composer deps go in `require` (the image installs with `--no-dev`).
- Work on a branch `feature/s3-uploads` off `7.x`.
- Tests: `php -d memory_limit=-1 bin/phpunit -c app/phpunit.xml.dist <path>` (see TROUBLESHOOTING "Running the PHPUnit suite on a Mac").

## Review Focus

1. **Filenames with spaces/accents** (`foto ção.png`) must produce a working, correctly encoded public URL, and `keyFromPublicUrl()` must round-trip it. Tested in Task 1.
2. **S3 down or wrong credentials during an upload**: the asset save, image upload or form submit fails visibly, and the asset row is not saved pointing at a missing file. Tested in Tasks 2, 3 and 5.
3. **Assets whose `remotePath` is an external URL** typed by a user (the upstream remote feature): never pushed, never deleted. Tested in Task 2.
4. **Editing an S3-backed asset without uploading a new file**: no re-upload and no deletion. **Replacing the file**: the old object is deleted only after the new put succeeds. Tested in Task 2.
5. **Feature off**: every touched class behaves as upstream. Each task has a `*_when_disabled_*` test.

---

### Task 1: Configuration + `MediaStorage` service + deploy docs

**Files:**
- Modify: `docker/render-local-config.php` (add the 5 keys to `$parameters`, each `getenv('S3_…') ?: ''`). Use `''`, not `null`, so removing an env var really disables the feature; the `null` filter would otherwise keep the old value from `local.php`.
- Modify: `app/bundles/CoreBundle/Config/config.php` `parameters` (defaults `''` for the 5 keys).
- Create: `app/bundles/CoreBundle/Storage/MediaStorage.php` (autowired by the existing `services.php` `load()`), `app/bundles/CoreBundle/Storage/DTO/MediaObject.php` and `app/bundles/CoreBundle/Exception/MediaStorageException.php`. These two must sit in `DTO/` and `Exception/`, which `MauticCoreExtension::DEFAULT_EXCLUDES` skips. Otherwise the public autowiring tries to build `MediaObject` as a service and the container fails to compile.
- Test: `app/bundles/CoreBundle/Tests/Unit/Storage/MediaStorageTest.php`
- Docs: `docs/project/DEPLOYMENT.md`. Add the env vars (set on web, cron **and** worker), the bucket policy JSON (public `s3:GetObject` on `arn:aws:s3:::<bucket>/*/media/*`), the "Block Public Access must allow bucket policies" note, the IAM actions (`s3:PutObject`, `s3:GetObject`, `s3:DeleteObject`, `s3:ListBucket`), and the fact that the feature only covers uploads made after it's on.

**Interfaces (Produces):**
```php
namespace Mautic\CoreBundle\Storage;

class MediaStorage   // not final: later tasks mock it
{
    public const FILES_PREFIX      = 'media/files/';
    public const IMAGES_PREFIX     = 'media/images/';
    public const FORMS_PREFIX      = 'media/files/form/';
    public const DASHBOARDS_PREFIX = 'media/dashboards/';

    public function __construct(CoreParametersHelper $parameters, ?\Aws\S3\S3ClientInterface $client = null); // client built lazily from params when null
    public function isEnabled(): bool;
    public function putFile(string $key, string $localPath): string;            // returns public URL; ContentType guessed via Symfony MimeTypes
    public function putContents(string $key, string $contents): string;         // returns public URL
    public function getContents(string $key): string;
    public function downloadTo(string $key, string $localPath): void;
    public function delete(string $key): void;
    public function deletePrefix(string $prefix): void;                         // paginated ListObjectsV2 + DeleteObjects
    public function exists(string $key): bool;
    /** @return list<MediaObject> */
    public function listObjects(string $prefix): array;                          // all pages, recursive
    public function getPublicUrl(string $key): string;
    public function keyFromPublicUrl(string $url): ?string;                      // null unless https://<bucket>.s3.<region>.amazonaws.com/<root>/…
}

// Mautic\CoreBundle\Storage\DTO\MediaObject
final class MediaObject { public function __construct(public readonly string $key, public readonly string $url, public readonly int $size, public readonly \DateTimeImmutable $lastModified) {} }
// Mautic\CoreBundle\Exception\MediaStorageException
final class MediaStorageException extends \RuntimeException {}
```
`$key` is always relative to the root (for example `media/images/a.png`). Every AWS exception is rethrown as `MediaStorageException`. Every method other than `isEnabled()` throws `MediaStorageException('S3 media storage is not configured')` when disabled.

- [ ] **Step 1: Create branch** `git checkout -b feature/s3-uploads`
- [ ] **Step 2: Write failing tests.** Build a real `S3Client` with an `Aws\MockHandler` (`'handler' => $mock`, region `sa-east-1`, dummy credentials). Params: bucket `mautic-selfhosted`, region `sa-east-1`, root `gpsdapena`.
  - `testDisabledWhenAnyParameterIsEmpty`: data provider blanking each of the 5 params → `isEnabled()` false.
  - `testPutFileSendsKeyUnderRootAndReturnsPublicUrl`: `putFile('media/images/a.png', <tmp png>)` → last command `PutObject`, `Bucket` = `mautic-selfhosted`, `Key` = `gpsdapena/media/images/a.png`, `ContentType` = `image/png`, no `ACL` key. Returns `https://mautic-selfhosted.s3.sa-east-1.amazonaws.com/gpsdapena/media/images/a.png`.
  - `testPublicUrlEncodesSegments`: `getPublicUrl('media/images/foto ção.png')` === `https://mautic-selfhosted.s3.sa-east-1.amazonaws.com/gpsdapena/media/images/foto%20%C3%A7%C3%A3o.png`, and `keyFromPublicUrl()` of that string === `media/images/foto ção.png`.
  - `testKeyFromPublicUrlRejectsForeignUrls`: `https://example.com/x.pdf` → null. The same bucket with root `trilhasdearuanda/…` → null. A `http://` variant → null.
  - `testAwsErrorBecomesMediaStorageException`: the mock appends an `S3Exception` → `putFile` throws `MediaStorageException`.
  - `testListObjectsStripsRootAndFollowsPagination`: 2 mocked `ListObjectsV2` pages → keys returned relative (`media/images/…`), with url/size/lastModified filled.
  - `testDeletePrefixDeletesEveryListedKey`: the `DeleteObjects` payload contains the full keys under `gpsdapena/media/files/form/7/`.
- [ ] **Step 3: Run → FAIL** (class not found).
- [ ] **Step 4: Implement** `MediaStorage`, `MediaObject`, `MediaStorageException`, the config defaults, and the `render-local-config.php` keys.
- [ ] **Step 5: Run → PASS.** Also run `php docker/render-local-config.php` in a scratch copy with the S3 env set/unset and confirm the keys are written as values/`''`.
- [ ] **Step 6: Update DEPLOYMENT.md** as described above.
- [ ] **Step 7: Commit** `feat(storage): add S3 media storage service and env config`

### Task 2: Assets

**Files:**
- Create: `app/bundles/AssetBundle/EventListener/AssetMediaStorageSubscriber.php`
- Modify: `app/bundles/AssetBundle/Entity/Asset.php` `getFileContents()` (~L1060): skip the `file_exists` check when `isRemote()`, so the admin preview/download of an S3-backed asset reads the public URL.
- Test: `app/bundles/AssetBundle/Tests/EventListener/AssetMediaStorageSubscriberTest.php`, plus a case in the existing Asset entity test (or a new `app/bundles/AssetBundle/Tests/Entity/AssetFileContentsTest.php`)

**Interfaces:**
- Consumes: `MediaStorage` (Task 1), `CoreParametersHelper::get('upload_dir')`.
- Produces: `AssetMediaStorageSubscriber::getSubscribedEvents()` → `AssetEvents::ASSET_PRE_SAVE => 'onAssetPreSave'`, `AssetEvents::ASSET_POST_DELETE => 'onAssetPostDelete'`.

Behavior of `onAssetPreSave(AssetEvent $event)` (disabled → return):
- If the asset is `local`, has a `path`, and `<upload_dir>/<path>` exists:
  1. Remember `$old = $asset->getRemotePath()`.
  2. `$url = putFile('media/files/'.$path, $localFile)`. On exception, let it propagate; the local file and the entity stay unchanged.
  3. `setStorageLocation('remote')`, `setRemotePath($url)`, unlink the local file.
  4. If `$old` is ours (`keyFromPublicUrl($old) !== null`) and differs from `$url`, `delete()` it.
  - mime/size/extension were already set by `Asset::upload()` from the local file. Don't call `setFileInfoFromFile()` again (for remote assets that does a curl HEAD).
- Otherwise, do nothing. This covers a remote asset with an external URL, and an S3-backed asset saved without a new file.
- **My call:** assets that existed before the feature was turned on get pushed the next time they're saved. This is harmless, and it moves them off the VPS.

`onAssetPostDelete`: if remote and `keyFromPublicUrl(remotePath)` is not null → `delete(key)`.

Downloads need no change. `PublicController` already tracks the download and redirects remote assets (`remoteRedirectResponse`). Email attachments need no code change either: `MailHelper` passes `getFilePath()` (the URL) to Symfony Mime `attachFromPath`, which `fopen`s it. Step 6 verifies this.

- [ ] **Step 1: Failing tests** (MediaStorage mocked, real temp upload dir):
  - `testLocalAssetIsPushedAndBecomesRemote`: asset local, path `abc.pdf`, file exists → `putFile('media/files/abc.pdf', <dir>/abc.pdf)` called once. Afterwards `getStorageLocation()==='remote'`, `getRemotePath()===<returned url>`, and the local file is gone.
  - `testNothingHappensWhenDisabled`: `isEnabled()` false → `putFile` never called, asset still `local`.
  - `testExternalRemoteAssetIsNeverTouched`: remote `https://example.com/x.pdf` → no put on save; on post-delete `keyFromPublicUrl` returns null → `delete` never called.
  - `testSavingS3AssetWithoutNewFileDoesNothing`: remote with our URL, no local file → no put, no delete.
  - `testReplacingFileDeletesOldObjectAfterPut`: local with new path `new.pdf` (exists), `getRemotePath()` = our old URL → `putFile` then `delete('media/files/old.pdf')`, in that order.
  - `testPutFailureKeepsLocalFileAndThrows`: `putFile` throws → `MediaStorageException` propagates, local file still exists, asset still `local`.
  - `testPostDeleteDeletesOwnObject`.
  - `testRemoteGetFileContentsReadsUrl` (entity): remote asset with `remotePath = 'file://'.<tmp file>` → `getFileContents()` returns the file's contents.
- [ ] **Step 2: Run → FAIL.**
- [ ] **Step 3: Implement** the subscriber and the `getFileContents()` change.
- [ ] **Step 4: Run → PASS**, plus `app/bundles/AssetBundle/Tests` (disabled path = upstream).
- [ ] **Step 5: Check the error UX:** with a bad secret, saving a new asset in the UI must show an error, not a 200 with a broken asset. If the exception surfaces as a raw 500, catch `MediaStorageException` in `AssetController` new/edit (~L318, ~L480) and re-render the form with a flash error (`mautic.asset.asset.error.storage`, add to `Translations/en_US/messages.ini` + `pt_BR` if present).
- [ ] **Step 6: Manual check** (real bucket): create an asset → object at `<root>/media/files/<sha>.<ext>`. `/asset/<slug>` counts the download and 302s to S3. Send a test email with that asset attached → the attachment arrives intact.
- [ ] **Step 7: Commit** `feat(assets): store uploaded assets in S3`

### Task 3: Builder / editor images

**Files:**
- Modify: `plugins/GrapesJsBuilderBundle/Helper/FileManager.php` (`uploadFiles`, `deleteFile`, `getMediaFiles`, and `getImages` via the same listing)
- Modify: `app/bundles/CoreBundle/Controller/FileController.php` (`uploadAction`, `listAction`, `deleteAction`)
- Modify: `app/bundles/CoreBundle/Controller/Api/FileApiController.php`: only when `$dir === 'images'`. The `assets` dir must stay local, because Task 2's pre-save pushes it when the asset row is created.
- Test: `plugins/GrapesJsBuilderBundle/Tests/Unit/Helper/FileManagerTest.php` (new), and extend `plugins/GrapesJsBuilderBundle/Tests/Functional/Controller/FileManagerControllerFunctionalTest.php` only if the disabled path needs it.

**Interfaces:**
- Consumes: `MediaStorage`. Inject it into the `FileManager` constructor and add it as an action argument in both controllers.
- Produces: no new public API. The URLs returned to editors become `MediaStorage::getPublicUrl('media/images/<name>')` when enabled.

Behavior when enabled:
- upload = existing local validate + `fileUploader->upload()` to the images dir, then `putFile('media/images/'.$name, $local)`, unlink, and return the S3 URL. If the put fails, unlink the local file and report the failure the way the editor already reports upload errors (GrapesJS: exception → error JSON; FileController: `failureResponse($editor)`).
- list = `listObjects('media/images/')`, dropping keys whose first segment after `media/images/` is in `image_path_exclude`. GrapesJS sorts by `lastModified` desc and paginates with the existing response shape. Items are `{src, type: 'image'}` for image extensions (width/height omitted; my call, to avoid a HEAD per object), `{src, type:'document'}` for the existing document extensions, and the SVG branch reads dimensions via `getContents()` only for `.svg`.
- delete = `delete('media/images/'.basename($name))`.

- [ ] **Step 1: Failing tests** (FileManagerTest, MediaStorage + FileUploader mocked):
  - `testUploadReturnsS3UrlAndRemovesLocalFile`
  - `testUploadFailureRemovesLocalFileAndThrows`
  - `testMediaFilesListsBucketNewestFirstAndPaginates`: 3 objects, limit 2, page 1 → the 2 newest; `totalItems` 3; `hasNextPage` true.
  - `testMediaFilesSkipsExcludedFolders`: `media/images/flags/br.png` excluded.
  - `testDeleteRemovesObject`
  - `testUsesLocalDiskWhenDisabled`: the existing behavior is unchanged (Finder over a temp dir).
- [ ] **Step 2: Run → FAIL.**
- [ ] **Step 3: Implement** FileManager, then the same behavior in FileController and FileApiController (`images` only).
- [ ] **Step 4: Run → PASS**, plus the GrapesJS functional tests.
- [ ] **Step 5: Manual check:** upload an image in the GrapesJS email builder. The `<img src>` saved in the email HTML is the S3 URL. The image picker shows it. Deleting it removes the object.
- [ ] **Step 6: Commit** `feat(builder): store editor images in S3`

### Task 4: Contact avatars

**Files:**
- Modify: `app/bundles/LeadBundle/Twig/Helper/AvatarHelper.php`
- Modify: `app/bundles/LeadBundle/Controller/LeadController.php` `uploadAvatar()` (~L850)
- Create: `app/bundles/LeadBundle/EventListener/ContactAvatarMediaStorageSubscriber.php` (on `LeadEvents::LEAD_POST_DELETE`). `LeadModel::deleteEntity` keeps its local `unlink`, which is a harmless no-op when the file is in S3.
- Test: `app/bundles/LeadBundle/Tests/Twig/Helper/AvatarHelperTest.php` (new) and `app/bundles/LeadBundle/Tests/EventListener/ContactAvatarMediaStorageSubscriberTest.php`

**Interfaces (Produces, on AvatarHelper; MediaStorage added to its constructor):**
```php
public function storeUploadedAvatar(Lead $lead, UploadedFile $file): void;   // enabled: putFile(key, $file->getPathname()); disabled: current move() logic
public static function avatarKey(Lead|int $lead): string;                    // 'media/images/lead_avatars/avatar'.$id
```
- `createAvatarFromFile()`: when enabled, `putFile(avatarKey, $filePath)` replaces the local copy. The extension validation stays.
- `getAvatar()`: when enabled and preferred is `custom` → `getPublicUrl(avatarKey).'?'.$lead->getDateModified()?->getTimestamp()`, with no existence check (one network call per avatar on list pages would be too slow).
- The subscriber deletes `avatarKey($lead->deletedId)` when enabled. Confirm that `deletedId` is set on the event's lead.

- [ ] **Step 1: Failing tests:** `testStoreUploadedAvatarPutsToS3`, `testCreateAvatarFromFilePutsToS3AndKeepsExtensionCheck`, `testCustomAvatarUrlPointsToS3`, `testDisabledKeepsLocalBehavior`, `testDeletingContactDeletesAvatarObject`.
- [ ] **Step 2: Run → FAIL.**
- [ ] **Step 3: Implement**, and switch `LeadController::uploadAvatar` to `$avatarHelper->storeUploadedAvatar($lead, $file)`.
- [ ] **Step 4: Run → PASS**, plus `app/bundles/LeadBundle/Tests/Controller` avatar-related tests if any.
- [ ] **Step 5: Commit** `feat(contacts): store custom avatars in S3`

### Task 5: Form file uploads

**Files:**
- Modify: `app/bundles/FormBundle/Helper/FormUploader.php` (+ `MediaStorage` in the constructor; check the service definition in `app/bundles/FormBundle/Config/config.php` ~L207 and add the argument there)
- Modify: `app/bundles/FormBundle/Controller/ResultController.php` (both download actions, ~L218 and ~L247)
- Modify: `app/bundles/LeadBundle/EventListener/SetContactAvatarFormSubscriber.php` (~L52): use `getLocalCopy()`
- Test: extend `app/bundles/FormBundle/Tests/Model/FormUploaderTest.php`

**Interfaces (Produces, on FormUploader):**
```php
public function getFileKey(Field $field, string $fileName): string;    // 'media/files/form/<formId>/<fieldId>/<fileName>'
public function getFileUrl(Field $field, string $fileName): ?string;   // S3 public URL when enabled, else null
public function getLocalCopy(Field $field, string $fileName): string;  // local path if the file exists locally, else downloadTo(sys_get_temp_dir().'/mautic-form-'.uniqid().'-'.$fileName); keeps the extension (avatar code checks it)
```
- `uploadFiles`: after the existing upload + `fixRotationJPG`, when enabled → `putFile(getFileKey(...), $local)`, then unlink. On any failure, delete the keys already put in this submission plus the local files, then throw `FileUploadException($alias)` (same contract as today).
- `deleteAllFilesOfFormField` → `deletePrefix('media/files/form/<formId>/<fieldId>/')`. `deleteFilesOfForm` → `deletePrefix('media/files/form/<formId>/')`. `deleteFileOfFormField` → `delete(key)`. Each also keeps the existing local delete.
- ResultController: keep the permission checks as they are. Then, if the local file is missing and `getFileUrl()` is not null → `RedirectResponse($url)`. Otherwise use the existing `BinaryFileResponse`.

- [ ] **Step 1: Failing tests:** `testUploadPutsEachFileUnderFormFieldKeyAndRemovesLocal`, `testUploadFailureRollsBackPutObjectsAndThrowsFileUploadException`, `testDeleteFilesOfFormDeletesPrefix`, `testDeleteUploadedFilesDeletesEachKey`, `testGetLocalCopyDownloadsWhenMissingLocally`, `testDisabledKeepsLocalBehavior` (the existing tests must stay green).
- [ ] **Step 2: Run → FAIL.**
- [ ] **Step 3: Implement** FormUploader, then ResultController and SetContactAvatarFormSubscriber.
- [ ] **Step 4: Run → PASS**, plus `app/bundles/FormBundle/Tests` and the SetContactAvatarFormSubscriber tests.
- [ ] **Step 5: Manual check:** submit a form with a file field. The object lands under `<root>/media/files/form/<formId>/<fieldId>/`. The results screen download redirects to it. A file field mapped to the contact avatar still sets the avatar.
- [ ] **Step 6: Commit** `feat(forms): store form file uploads in S3`

### Task 6: User dashboards

**Files:**
- Create: `app/bundles/DashboardBundle/Helper/DashboardFileSync.php`
- Modify: `app/bundles/DashboardBundle/Model/DashboardModel.php` `saveSnapshot()` (~L124)
- Modify: `app/bundles/DashboardBundle/Controller/DashboardController.php`: `importAction` (~L432, after `move()` and before listing), `deleteDashboardFileAction` (~L361), and the apply action (~L390, before reading the file)
- Test: `app/bundles/DashboardBundle/Tests/Helper/DashboardFileSyncTest.php`

**Interfaces (Produces):**
```php
final class DashboardFileSync
{
    public function __construct(MediaStorage $storage, PathsHelper $paths);
    public function pull(): void;                        // download every media/dashboards/<userId>/ object missing from getSystemPath('dashboard.user')
    public function push(string $absolutePath): void;    // putFile('media/dashboards/<userId>/'.basename($absolutePath), …)
    public function remove(string $absolutePath): void;  // delete the same key
}
```
All methods are no-ops when disabled. The local dir stays the working copy; S3 is the durable copy. The global dashboards dir (shipped with Mautic) is untouched. **My call:** a mirror, because these files are tiny and rare, and a mirror avoids rewriting the import screen.

- [ ] **Step 1: Failing tests:** `testPushUsesUserScopedKey`, `testPullDownloadsOnlyMissingFiles`, `testRemoveDeletesKey`, `testNoopWhenDisabled`.
- [ ] **Step 2: Run → FAIL.**
- [ ] **Step 3: Implement** the sync class and wire `push` (saveSnapshot, import), `remove` (delete) and `pull` (import listing, apply).
- [ ] **Step 4: Run → PASS**, plus `app/bundles/DashboardBundle/Tests`.
- [ ] **Step 5: Commit** `feat(dashboard): mirror user dashboards to S3`

### Task 7: elFinder (legacy file manager)

**Files:**
- `composer require league/flysystem-aws-s3-v3:^3.0` (must resolve against `league/flysystem` 3.36. Commit `composer.json` + `composer.lock`.)
- Create: `app/bundles/CoreBundle/Service/ElFinderAdapterFactory.php`
- Modify: `app/bundles/CoreBundle/Config/config.php`: point `mautic.core.service.local_file_adapter` at the factory (`'factory' => [ElFinderAdapterFactory::class, 'create']`, or the services.php equivalent)
- Modify: `app/bundles/CoreBundle/Loader/EnvVars/ElFinderEnvVars.php`: when all 5 `s3_*` values are present in `$config`, set `MAUTIC_EL_FINDER_URL` to `https://<bucket>.s3.<region>.amazonaws.com/<root>/media/images`
- Test: `app/bundles/CoreBundle/Tests/Unit/Service/ElFinderAdapterFactoryTest.php`, plus the existing `ElFinderEnvVars` test (extend it)

**Interfaces:** `ElFinderAdapterFactory::create(): League\Flysystem\FilesystemAdapter` returns `AwsS3V3Adapter($client, $bucket, "<root>/media/images")` when enabled, else `new LocalFileAdapterService($elFinderPath)`. To reuse the client, `MediaStorage` exposes `public function getClient(): S3ClientInterface` and `public function getBucket(): string`. Add them in this task.

- [ ] **Step 1: Failing tests:** `testReturnsS3AdapterWhenEnabled`, `testReturnsLocalAdapterWhenDisabled`, `testElFinderUrlPointsToBucketWhenS3Configured`.
- [ ] **Step 2: Run → FAIL.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run → PASS.**
- [ ] **Step 5: Manual check:** open the legacy builder's file manager, upload, list and delete. Confirm thumbnails still render (`tmb_path` stays local). If thumbnails break, disable them for the S3 root rather than chasing it.
- [ ] **Step 6: Build check:** `docker build -f docker/Dockerfile .` succeeds (the `--no-dev` install includes the new package).
- [ ] **Step 7: Commit** `feat(elfinder): use S3 adapter when media storage is enabled`

### Task 8: Docs + end-to-end verification

**Files:** `docs/project/DECISIONS.md`, `docs/project/OVERVIEW.md`, `docs/project/TROUBLESHOOTING.md`, `docs/specs/2026-10-28-s3-uploads.md`

- [ ] **Step 1:** Move the spec's Decisions into `DECISIONS.md`. Add one line to `OVERVIEW.md` saying uploads live in S3 when `S3_*` is set. In the TROUBLESHOOTING "Amazon S3 plugin…" entry, point to this feature as the way to get uploads into the bucket. Mark the spec as shipped.
- [ ] **Step 2: Run the full touched suites:** `app/bundles/{CoreBundle,AssetBundle,FormBundle,LeadBundle,DashboardBundle}/Tests` and `plugins/GrapesJsBuilderBundle/Tests`. All green. Then `bin/phpstan` and `bin/php-cs-fixer fix --dry-run` on the changed files.
- [ ] **Step 3: Spec success check** on a staging client. Upload one asset, one builder image, one avatar and one form file, and send an email with the image. Redeploy on a fresh container with **no** media volume (same DB + env). Everything downloads and renders, and the already-sent email still shows the image.
- [ ] **Step 4: Commit** `docs: document S3 media storage`
