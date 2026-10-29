<?php

declare(strict_types=1);

namespace Mautic\CoreBundle\Storage;

use Mautic\CoreBundle\Exception\MediaStorageException;

/**
 * Shared naming, validation and push logic for editor/builder images stored in S3.
 *
 * The local images dir is emptied after each push, so the uploader's local uniqueness check
 * does not protect S3 keys: every stored image gets a random prefix and a collision check.
 */
class ImageStorage
{
    public function __construct(private MediaStorage $mediaStorage)
    {
    }

    public function isEnabled(): bool
    {
        return $this->mediaStorage->isEnabled();
    }

    /**
     * Builds the key media/images/<prefix><name> from a user supplied name (basename only).
     *
     * @throws \InvalidArgumentException
     */
    public function getKey(string $name, string $subdir = ''): string
    {
        $name = basename($name);

        if ('' === $name || '.' === $name || '..' === $name || str_contains($name, '/') || str_contains($name, "\0")) {
            throw new \InvalidArgumentException('Invalid file name');
        }

        return $this->getPrefix($subdir).$name;
    }

    /**
     * Renames the freshly uploaded local file to a random-prefixed name without an existing S3 object,
     * pushes it, and removes the local file (created by this request) in every case.
     *
     * @return array{name: string, key: string, url: string}
     *
     * @throws MediaStorageException
     */
    public function store(string $localDir, string $fileName, string $subdir = ''): array
    {
        $from = rtrim($localDir, '/').'/'.$fileName;
        $path = $from;

        try {
            $name = '';
            $key  = '';
            for ($attempt = 0; $attempt < 2; ++$attempt) {
                $name = bin2hex(random_bytes(8)).'-'.basename($fileName);
                $key  = $this->getKey($name, $subdir);
                if (!$this->mediaStorage->exists($key)) {
                    break;
                }
                if (1 === $attempt) {
                    throw new MediaStorageException(sprintf('Could not find a free key for "%s"', $key));
                }
            }

            $path = rtrim($localDir, '/').'/'.$name;
            if (!@rename($from, $path)) {
                throw new MediaStorageException(sprintf('Could not rename "%s"', $from));
            }

            return ['name' => $name, 'key' => $key, 'url' => $this->mediaStorage->putFile($key, $path)];
        } catch (\InvalidArgumentException $e) {
            throw new MediaStorageException($e->getMessage(), 0, $e);
        } finally {
            foreach (array_unique([$from, $path]) as $local) {
                if (is_file($local)) {
                    @unlink($local);
                }
            }
        }
    }

    /**
     * Best-effort delete of keys this request created.
     *
     * @param string[] $keys
     */
    public function deleteQuietly(array $keys): void
    {
        foreach ($keys as $key) {
            try {
                $this->mediaStorage->delete($key);
            } catch (MediaStorageException) {
            }
        }
    }

    private function getPrefix(string $subdir): string
    {
        return MediaStorage::IMAGES_PREFIX.('' === $subdir ? '' : $subdir.'/');
    }
}
