<?php

declare(strict_types=1);

namespace Mautic\AssetBundle\EventListener;

use Mautic\AssetBundle\AssetEvents;
use Mautic\AssetBundle\Event\AssetEvent;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Storage\MediaStorage;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Moves locally uploaded assets to the S3 media storage when it is enabled.
 */
class AssetMediaStorageSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private MediaStorage $mediaStorage,
        private CoreParametersHelper $coreParametersHelper,
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
     * @throws \Mautic\CoreBundle\Exception\MediaStorageException
     */
    public function onAssetPreSave(AssetEvent $event): void
    {
        if (!$this->mediaStorage->isEnabled()) {
            return;
        }

        $asset = $event->getAsset();
        $path  = $asset->getPath();
        if (!$asset->isLocal() || null === $path || '' === $path) {
            return;
        }

        $localFile = rtrim((string) $this->coreParametersHelper->get('upload_dir'), '/').'/'.$path;
        if (!is_file($localFile)) {
            return;
        }

        $old = $asset->getRemotePath();
        $url = $this->mediaStorage->putFile(MediaStorage::FILES_PREFIX.$path, $localFile);

        $asset->setStorageLocation('remote');
        $asset->setRemotePath($url);
        @unlink($localFile);

        if (null !== $old && $old !== $url) {
            $oldKey = $this->mediaStorage->keyFromPublicUrl($old);
            if (null !== $oldKey) {
                $this->mediaStorage->delete($oldKey);
            }
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

        $key = $this->mediaStorage->keyFromPublicUrl($asset->getRemotePath());
        if (null !== $key) {
            $this->mediaStorage->delete($key);
        }
    }
}
