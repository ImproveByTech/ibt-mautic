<?php

namespace Mautic\LeadBundle\Twig\Helper;

use Mautic\CoreBundle\Exception\FileNotFoundException;
use Mautic\CoreBundle\Helper\PathsHelper;
use Mautic\CoreBundle\Storage\MediaStorage;
use Mautic\CoreBundle\Twig\Helper\AssetsHelper;
use Mautic\CoreBundle\Twig\Helper\GravatarHelper;
use Mautic\LeadBundle\Entity\Lead;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class AvatarHelper
{
    /**
     * @var array<string>
     */
    private array $imageTypes = ['jpg', 'jpeg', 'png', 'gif'];

    public function __construct(
        private readonly AssetsHelper $assetsHelper,
        private readonly PathsHelper $pathsHelper,
        private readonly GravatarHelper $gravatarHelper,
        private readonly DefaultAvatarHelper $defaultAvatarHelper,
        private readonly MediaStorage $mediaStorage,
    ) {
    }

    public static function avatarKey(Lead|int $lead): string
    {
        $id = $lead instanceof Lead ? $lead->getId() : $lead;

        return MediaStorage::IMAGES_PREFIX.'lead_avatars/avatar'.(int) $id;
    }

    /**
     * Stores an avatar uploaded through the contact form.
     */
    public function storeUploadedAvatar(Lead $lead, UploadedFile $file): void
    {
        $this->assertRealImage($file->getPathname());

        if ($this->mediaStorage->isEnabled()) {
            $this->mediaStorage->putFile(self::avatarKey($lead), $file->getPathname());

            return;
        }

        $avatarDir = $this->getAvatarPath(true);

        if (!file_exists($avatarDir)) {
            mkdir($avatarDir);
        }

        $file->move($avatarDir, 'avatar'.$lead->getId());
    }

    /**
     * @param string $filePath
     *
     * @throws FileNotFoundException
     */
    public function createAvatarFromFile(Lead $lead, $filePath): void
    {
        if (!file_exists($filePath)) {
            throw new FileNotFoundException();
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if (!in_array($ext, $this->imageTypes)) {
            throw new \Exception('File is not image');
        }

        $this->assertRealImage($filePath);

        if ($this->mediaStorage->isEnabled()) {
            $this->mediaStorage->putFile(self::avatarKey($lead), $filePath);

            return;
        }

        $avatarDir = $this->getAvatarPath(true);

        if (!file_exists($avatarDir)) {
            mkdir($avatarDir);
        }

        $fs = new Filesystem();
        $fs->copy($filePath, $avatarDir.DIRECTORY_SEPARATOR.'avatar'.$lead->getId(), true);
    }

    /**
     * Verifies the real content: the extension alone can hide HTML/SVG that would render from the public bucket.
     *
     * @throws \Exception
     */
    private function assertRealImage(string $path): void
    {
        $info = @getimagesize($path);
        if (false === $info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_GIF], true)) {
            throw new \Exception('File is not image');
        }
    }

    /**
     * @return mixed
     */
    public function getAvatar(Lead $lead)
    {
        $preferred  = $lead->getPreferredProfileImage();
        $socialData = $lead->getSocialCache();
        $leadEmail  = $lead->getEmail();

        $localAvatar = $this->getAvatarPath(true).'/avatar'.$lead->getId();
        if ('custom' == $preferred && $this->mediaStorage->isEnabled() && !file_exists($localAvatar)) {
            // No existence check: it would cost a network call per avatar on list pages.
            $img       = $this->mediaStorage->getPublicUrl(self::avatarKey($lead));
            $timestamp = $lead->getDateModified()?->getTimestamp();
            if (null !== $timestamp) {
                $img .= '?'.$timestamp;
            }
        } elseif ('custom' == $preferred) {
            $avatarPath = $this->getAvatarPath(true).'/avatar'.$lead->getId();
            if (file_exists($avatarPath) && $fmtime = filemtime($avatarPath)) {
                // Append file modified time to ensure the latest is used by browser
                $img = $this->assetsHelper->getUrl(
                    $this->getAvatarPath().'/avatar'.$lead->getId().'?'.$fmtime,
                    null,
                    null,
                    false,
                    true
                );
            }
        } elseif (isset($socialData[$preferred]) && !empty($socialData[$preferred]['profile']['profileImage'])) {
            $img = $socialData[$preferred]['profile']['profileImage'];
        }

        if (empty($img)) {
            // Default to gravatar if others failed
            if (!empty($leadEmail)) {
                $img = $this->gravatarHelper->getImage($leadEmail);
            } else {
                $img = $this->defaultAvatarHelper->getDefaultAvatar();
            }
        }

        return $img;
    }

    /**
     * @param bool $absolute
     */
    public function getAvatarPath($absolute = false): string
    {
        $imageDir = $this->pathsHelper->getSystemPath('images', $absolute);

        return $imageDir.'/lead_avatars';
    }

    public function getName(): string
    {
        return 'lead_avatar';
    }
}
