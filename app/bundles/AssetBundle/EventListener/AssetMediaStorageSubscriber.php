<?php

declare(strict_types=1);

namespace Mautic\AssetBundle\EventListener;

use Mautic\AssetBundle\AssetEvents;
use Mautic\AssetBundle\Entity\Asset;
use Mautic\AssetBundle\Entity\AssetRepository;
use Mautic\AssetBundle\Event\AssetEvent;
use Mautic\CoreBundle\Exception\MediaStorageException;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Storage\MediaStorage;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Moves locally uploaded assets to the S3 media storage when it is enabled.
 */
class AssetMediaStorageSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private MediaStorage $mediaStorage,
        private CoreParametersHelper $coreParametersHelper,
        private AssetRepository $assetRepository,
        private LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            AssetEvents::ASSET_PRE_SAVE    => ['onAssetPreSave', 0],
            AssetEvents::ASSET_POST_DELETE => ['onAssetPostDelete', 0],
        ];
    }

    /**
     * @throws MediaStorageException
     */
    public function onAssetPreSave(AssetEvent $event): void
    {
        if (!$this->mediaStorage->isEnabled()) {
            return;
        }

        $asset = $event->getAsset();
        $this->canonicalizeRemotePath($asset);

        $path = $asset->getPath();
        if (null === $path || '' === $path) {
            return;
        }

        $old = $asset->getRemotePath();
        // Push a new file for local assets, and for S3-backed assets whose file was replaced.
        if (!$asset->isLocal() && !(null !== $old && null !== $this->mediaStorage->keyFromPublicUrl($old))) {
            return;
        }

        $localFile = $this->resolveLocalFile($path);
        if (null === $localFile) {
            return;
        }

        $url = $this->mediaStorage->putFile(MediaStorage::FILES_PREFIX.$path, $localFile);

        $asset->setStorageLocation('remote');
        $asset->setRemotePath($url);

        // A cloned legacy asset may still point at the same local file.
        if (!$this->assetRepository->existsOtherLocalWithPath($path, $asset->getId())) {
            @unlink($localFile);
        }

        if (null !== $old && $old !== $url) {
            $this->deleteOwnObject($old, $asset->getId());
        }
    }

    public function onAssetPostDelete(AssetEvent $event): void
    {
        if (!$this->mediaStorage->isEnabled()) {
            return;
        }

        $asset = $event->getAsset();
        if (!$asset->isRemote() || !$asset->getRemotePath()) {
            return;
        }

        $this->deleteOwnObject($asset->getRemotePath(), $asset->getId());
    }

    /**
     * A remote path pointing at our bucket is rewritten to the canonical URL so that
     * query-string / percent-encoded variants cannot hide a shared object.
     */
    private function canonicalizeRemotePath(Asset $asset): void
    {
        $remotePath = $asset->getRemotePath();
        if (!$asset->isRemote() || null === $remotePath) {
            return;
        }

        $key = $this->mediaStorage->keyFromPublicUrl($remotePath);
        if (null === $key) {
            return;
        }

        $canonical = $this->mediaStorage->getPublicUrl($key);
        if ($canonical !== $remotePath) {
            $asset->setRemotePath($canonical);
        }
    }

    /**
     * Compares by normalized key, not by raw URL.
     */
    private function isSharedWithOtherAsset(string $key, ?int $excludeId): bool
    {
        $prefix = $this->mediaStorage->getPublicUrl(MediaStorage::FILES_PREFIX);
        foreach ($this->assetRepository->getOtherRemotePathsWithPrefix($prefix, $excludeId) as $otherUrl) {
            if ($this->mediaStorage->keyFromPublicUrl($otherUrl) === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * Only a file this request actually moved in may be removed after a failed push.
     */
    public static function shouldRemoveOrphanedUpload(bool $hadNewFile, ?string $pathBefore, ?string $pathAfter, bool $usedByOtherAsset): bool
    {
        return $hadNewFile && null !== $pathAfter && '' !== $pathAfter && $pathAfter !== $pathBefore && !$usedByOtherAsset;
    }

    /**
     * Resolve the asset file inside the upload dir, refusing anything that escapes it.
     */
    private function resolveLocalFile(string $path): ?string
    {
        if (str_starts_with($path, '/') || str_contains($path, "\0") || in_array('..', explode('/', str_replace('\\', '/', $path)), true)) {
            $this->logger->warning('Refusing to push asset with unsafe path to media storage.', ['path' => $path]);

            return null;
        }

        $uploadDir = realpath((string) $this->coreParametersHelper->get('upload_dir'));
        $file      = realpath((string) $this->coreParametersHelper->get('upload_dir').'/'.$path);
        if (false === $file) {
            return null;
        }

        if (false === $uploadDir || !str_starts_with($file, $uploadDir.DIRECTORY_SEPARATOR) || !is_file($file)) {
            $this->logger->warning('Refusing to push asset outside the upload directory to media storage.', ['path' => $path]);

            return null;
        }

        return $file;
    }

    /**
     * Delete an object only if it is ours, under media/files/ and no other asset still uses it.
     * Failures are logged: a leaked object is harmless, a failed save/delete is not.
     */
    private function deleteOwnObject(string $url, ?int $excludeId): void
    {
        try {
            $key = $this->mediaStorage->keyFromPublicUrl($url);
            if (null === $key || !str_starts_with($key, MediaStorage::FILES_PREFIX)) {
                return;
            }

            if ($this->isSharedWithOtherAsset($key, $excludeId)) {
                return;
            }

            $this->mediaStorage->delete($key);
        } catch (MediaStorageException $e) {
            $this->logger->error('Could not delete asset object from media storage: '.$e->getMessage(), ['url' => $url]);
        }
    }
}
