<?php

declare(strict_types=1);

namespace Mautic\DashboardBundle\Helper;

use Mautic\CoreBundle\Exception\MediaStorageException;
use Mautic\CoreBundle\Helper\PathsHelper;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\CoreBundle\Storage\MediaStorage;
use Psr\Log\LoggerInterface;

/**
 * Mirrors the current user's dashboard JSON files to S3. The local directory stays the
 * working copy; S3 is the durable copy.
 */
final class DashboardFileSync
{
    public function __construct(
        private MediaStorage $storage,
        private PathsHelper $paths,
        private UserHelper $userHelper,
        private LoggerInterface $mauticLogger,
    ) {
    }

    /**
     * Downloads every remote object that is missing locally. Never throws.
     */
    public function pull(): void
    {
        if (!$this->storage->isEnabled() || null === ($userId = $this->getUserId())) {
            return;
        }

        try {
            $dir = $this->paths->getSystemPath('dashboard.user');
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new MediaStorageException('Cannot create '.$dir);
            }

            $prefix = $this->prefix($userId);
            foreach ($this->storage->listObjects($prefix) as $object) {
                if (!str_starts_with($object->key, $prefix)) {
                    continue;
                }
                $name = substr($object->key, strlen($prefix));
                if (!$this->isValidName($name)) {
                    continue;
                }
                if (file_exists($dir.'/'.$name)) {
                    continue;
                }
                $this->storage->downloadTo($object->key, $dir.'/'.$name);
            }
        } catch (\Throwable $e) {
            $this->mauticLogger->error('Could not pull dashboards from S3: '.$e->getMessage());
        }
    }

    /**
     * @throws MediaStorageException
     */
    public function push(string $absolutePath): void
    {
        if (!$this->storage->isEnabled()) {
            return;
        }

        $key = $this->keyFor($absolutePath);
        if (null === $key) {
            $this->mauticLogger->warning('Refusing to push dashboard file to S3: '.$absolutePath);

            return;
        }

        $this->storage->putFile($key, $absolutePath);
    }

    /**
     * Never throws: the local delete has already happened. Returns false on a logged failure.
     */
    public function remove(string $absolutePath): bool
    {
        if (!$this->storage->isEnabled()) {
            return true;
        }

        try {
            $key = $this->keyFor($absolutePath);
            if (null === $key) {
                $this->mauticLogger->warning('Refusing to delete dashboard file from S3: '.$absolutePath);

                return false;
            }
            $this->storage->delete($key);

            return true;
        } catch (\Throwable $e) {
            $this->mauticLogger->error('Could not delete dashboard from S3: '.$e->getMessage());

            return false;
        }
    }

    private function getUserId(): ?int
    {
        $id = $this->userHelper->getUser(true)?->getId();

        return $id ? (int) $id : null;
    }

    private function prefix(int $userId): string
    {
        return MediaStorage::DASHBOARDS_PREFIX.$userId.'/';
    }

    private function isValidName(string $name): bool
    {
        return '' !== $name
            && '.' !== $name
            && '..' !== $name
            && !preg_match('#[/\\\\\0]#', $name)
            && str_ends_with($name, '.json');
    }

    /**
     * Returns the S3 key only if the path is a valid file directly inside the user's dashboard directory.
     */
    private function keyFor(string $absolutePath): ?string
    {
        $userId = $this->getUserId();
        if (null === $userId || str_contains($absolutePath, "\0")) {
            return null;
        }

        $name = basename($absolutePath);
        if (!$this->isValidName($name)) {
            return null;
        }

        $dir     = realpath($this->paths->getSystemPath('dashboard.user'));
        $fileDir = realpath(dirname($absolutePath));
        if (false === $dir || false === $fileDir || $dir !== $fileDir) {
            return null;
        }

        return $this->prefix($userId).$name;
    }
}
