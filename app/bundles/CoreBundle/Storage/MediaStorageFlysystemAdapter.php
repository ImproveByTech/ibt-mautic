<?php

declare(strict_types=1);

namespace Mautic\CoreBundle\Storage;

use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToCheckDirectoryExistence;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToListContents;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\Visibility;
use Mautic\CoreBundle\Exception\MediaStorageException;
use Mautic\CoreBundle\Storage\DTO\MediaObject;
use Symfony\Component\Mime\MimeTypes;

/**
 * Flysystem adapter (used by elFinder) over MediaStorage, rooted at "media/images/".
 *
 * All paths handed in are relative to that root. S3 has no directories: they are derived from key prefixes,
 * so createDirectory() is a no-op and empty directories do not exist.
 */
class MediaStorageFlysystemAdapter implements FilesystemAdapter
{
    public function __construct(private MediaStorage $storage)
    {
    }

    public function fileExists(string $path): bool
    {
        try {
            return $this->storage->exists($this->key($path));
        } catch (MediaStorageException|\InvalidArgumentException $e) {
            throw UnableToCheckFileExistence::forLocation($path, $e);
        }
    }

    public function directoryExists(string $path): bool
    {
        try {
            $relative = $this->normalize($path);
            if ('' === $relative) {
                return true;
            }

            return [] !== $this->storage->listObjects(MediaStorage::IMAGES_PREFIX.$relative.'/');
        } catch (MediaStorageException|\InvalidArgumentException $e) {
            throw UnableToCheckDirectoryExistence::forLocation($path, $e);
        }
    }

    public function write(string $path, string $contents, Config $config): void
    {
        try {
            $this->storage->putContents($this->key($path), $contents);
        } catch (MediaStorageException|\InvalidArgumentException $e) {
            throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $data = is_resource($contents) ? stream_get_contents($contents) : false;
        if (false === $data) {
            throw UnableToWriteFile::atLocation($path, 'Cannot read the given stream');
        }

        $this->write($path, $data, $config);
    }

    public function read(string $path): string
    {
        try {
            return $this->storage->getContents($this->key($path));
        } catch (MediaStorageException|\InvalidArgumentException $e) {
            throw UnableToReadFile::fromLocation($path, $e->getMessage(), $e);
        }
    }

    public function readStream(string $path)
    {
        $contents = $this->read($path);
        $stream   = fopen('php://temp', 'w+b');
        if (false === $stream) {
            throw UnableToReadFile::fromLocation($path, 'Cannot open a temporary stream');
        }
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }

    public function delete(string $path): void
    {
        try {
            $relative = $this->normalize($path);
            if ('' === $relative) {
                throw new \InvalidArgumentException('Cannot delete the root');
            }
            $this->storage->delete(MediaStorage::IMAGES_PREFIX.$relative);
        } catch (MediaStorageException|\InvalidArgumentException $e) {
            throw UnableToDeleteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function deleteDirectory(string $path): void
    {
        try {
            $relative = $this->normalize($path);
            if ('' === $relative) {
                throw new \InvalidArgumentException('Refusing to delete the root');
            }
            $this->storage->deletePrefix(MediaStorage::IMAGES_PREFIX.$relative.'/');
        } catch (MediaStorageException|\InvalidArgumentException $e) {
            throw UnableToDeleteDirectory::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function createDirectory(string $path, Config $config): void
    {
        try {
            $this->normalize($path);
        } catch (\InvalidArgumentException $e) {
            throw UnableToCreateDirectory::atLocation($path, $e->getMessage(), $e);
        }
        // S3 has no directories; they appear once an object is stored under the prefix.
    }

    public function setVisibility(string $path, string $visibility): void
    {
        // Access is governed by the bucket policy, not per object.
    }

    public function visibility(string $path): FileAttributes
    {
        return new FileAttributes($this->normalize($path), null, Visibility::PUBLIC);
    }

    public function mimeType(string $path): FileAttributes
    {
        $this->head($path, 'mimeType');
        $mime   = MimeTypes::getDefault()->getMimeTypes(strtolower(pathinfo($path, PATHINFO_EXTENSION)))[0] ?? null;
        if (null === $mime) {
            throw UnableToRetrieveMetadata::mimeType($path, 'Unknown mime type');
        }

        return new FileAttributes($this->normalize($path), null, null, null, $mime);
    }

    public function lastModified(string $path): FileAttributes
    {
        $object = $this->head($path, 'lastModified');

        return new FileAttributes($this->normalize($path), null, null, $object->lastModified->getTimestamp());
    }

    public function fileSize(string $path): FileAttributes
    {
        $object = $this->head($path, 'fileSize');

        return new FileAttributes($this->normalize($path), $object->size);
    }

    /**
     * @return iterable<StorageAttributes>
     */
    public function listContents(string $path, bool $deep): iterable
    {
        try {
            $relative = $this->normalize($path);
            $dirPath  = '' === $relative ? '' : $relative.'/';
            $objects  = $this->storage->listObjects(MediaStorage::IMAGES_PREFIX.$dirPath);
        } catch (MediaStorageException|\InvalidArgumentException $e) {
            throw UnableToListContents::atLocation($path, $deep, $e);
        }

        $directories = [];
        $rootLength  = strlen(MediaStorage::IMAGES_PREFIX);

        foreach ($objects as $object) {
            $filePath = substr($object->key, $rootLength);
            $rest     = substr($filePath, strlen($dirPath));

            if ($deep) {
                $segments = explode('/', $rest);
                array_pop($segments);
                $current = rtrim($dirPath, '/');
                foreach ($segments as $segment) {
                    $current = '' === $current ? $segment : $current.'/'.$segment;
                    if (!isset($directories[$current])) {
                        $directories[$current] = true;
                        yield new DirectoryAttributes($current);
                    }
                }
            } elseif (str_contains($rest, '/')) {
                $dir = $dirPath.explode('/', $rest, 2)[0];
                if (!isset($directories[$dir])) {
                    $directories[$dir] = true;
                    yield new DirectoryAttributes($dir);
                }

                continue;
            }

            yield new FileAttributes($filePath, $object->size, Visibility::PUBLIC, $object->lastModified->getTimestamp());
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        try {
            $this->storage->putContents($this->key($destination), $this->storage->getContents($this->key($source)));
            if ($this->normalize($source) !== $this->normalize($destination)) {
                $this->storage->delete($this->key($source));
            }
        } catch (MediaStorageException|\InvalidArgumentException $e) {
            throw UnableToMoveFile::because($e->getMessage(), $source, $destination);
        }
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        try {
            $this->storage->putContents($this->key($destination), $this->storage->getContents($this->key($source)));
        } catch (MediaStorageException|\InvalidArgumentException $e) {
            throw UnableToCopyFile::because($e->getMessage(), $source, $destination);
        }
    }

    private function head(string $path, string $type): MediaObject
    {
        try {
            $key = $this->key($path);
            foreach ($this->storage->listObjects($key) as $object) {
                if ($object->key === $key) {
                    return $object;
                }
            }
            $reason = 'File not found';
        } catch (MediaStorageException|\InvalidArgumentException $e) {
            throw UnableToRetrieveMetadata::create($path, $type, $e->getMessage(), $e);
        }

        throw UnableToRetrieveMetadata::create($path, $type, $reason);
    }

    private function key(string $path): string
    {
        $relative = $this->normalize($path);
        if ('' === $relative) {
            throw new \InvalidArgumentException('A file path is required');
        }

        return MediaStorage::IMAGES_PREFIX.$relative;
    }

    /**
     * @throws \InvalidArgumentException for NUL bytes, absolute paths and ".." segments
     */
    private function normalize(string $path): string
    {
        if (str_contains($path, "\0") || str_starts_with($path, '/') || str_contains($path, '\\')) {
            throw new \InvalidArgumentException(sprintf('Invalid path "%s"', str_replace("\0", '', $path)));
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ('..' === $segment) {
                throw new \InvalidArgumentException(sprintf('Invalid path "%s"', $path));
            }
            if ('' !== $segment && '.' !== $segment) {
                $segments[] = $segment;
            }
        }

        return implode('/', $segments);
    }
}
