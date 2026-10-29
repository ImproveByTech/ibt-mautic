<?php

declare(strict_types=1);

namespace Mautic\CoreBundle\Storage;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Aws\S3\S3ClientInterface;
use Mautic\CoreBundle\Exception\MediaStorageException;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Storage\DTO\MediaObject;
use Symfony\Component\Mime\MimeTypes;

/**
 * Stores user uploads in S3 under a per-client root directory.
 *
 * Keys passed in and returned are always relative to the root directory
 * (e.g. "media/images/a.png"). Enabled only when bucket, region, both
 * credentials and root directory are all configured.
 */
class MediaStorage
{
    public const FILES_PREFIX      = 'media/files/';
    public const IMAGES_PREFIX     = 'media/images/';
    public const FORMS_PREFIX      = 'media/files/form/';
    public const DASHBOARDS_PREFIX = 'media/dashboards/';

    private const PARAMETERS = ['s3_bucket', 's3_region', 's3_access_key_id', 's3_secret_access_key', 's3_root_directory'];

    public function __construct(
        private CoreParametersHelper $parameters,
        private ?S3ClientInterface $client = null,
    ) {
    }

    public function isEnabled(): bool
    {
        foreach (self::PARAMETERS as $name) {
            if ('' === trim((string) $this->parameters->get($name, ''))) {
                return false;
            }
        }

        return true;
    }

    public function putFile(string $key, string $localPath): string
    {
        $this->assertEnabled();
        $mime = MimeTypes::getDefault()->guessMimeType($localPath) ?: 'application/octet-stream';

        $handle = @fopen($localPath, 'rb');
        if (false === $handle) {
            throw new MediaStorageException(sprintf('Cannot read local file "%s"', $localPath));
        }

        try {
            $this->execute('PutObject', [
                'Key'         => $this->fullKey($key),
                'Body'        => $handle,
                'ContentType' => $mime,
            ]);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        return $this->getPublicUrl($key);
    }

    public function putContents(string $key, string $contents): string
    {
        $this->assertEnabled();
        $mime = MimeTypes::getDefault()->getMimeTypes(strtolower(pathinfo($key, PATHINFO_EXTENSION)))[0] ?? 'application/octet-stream';

        $this->execute('PutObject', [
            'Key'         => $this->fullKey($key),
            'Body'        => $contents,
            'ContentType' => $mime,
        ]);

        return $this->getPublicUrl($key);
    }

    public function getContents(string $key): string
    {
        $this->assertEnabled();
        $result = $this->execute('GetObject', ['Key' => $this->fullKey($key)]);

        return (string) $result['Body'];
    }

    public function downloadTo(string $key, string $localPath): void
    {
        $this->assertEnabled();
        $this->execute('GetObject', ['Key' => $this->fullKey($key), 'SaveAs' => $localPath]);
    }

    public function delete(string $key): void
    {
        $this->assertEnabled();
        $this->execute('DeleteObject', ['Key' => $this->fullKey($key)]);
    }

    public function deletePrefix(string $prefix): void
    {
        $this->assertEnabled();
        if ('' === trim($prefix, '/')) {
            throw new MediaStorageException('Refusing to delete an empty prefix');
        }

        $batch = [];
        foreach ($this->listObjects($prefix) as $object) {
            $batch[] = ['Key' => $this->fullKey($object->key)];
            if (1000 === count($batch)) {
                $this->deleteBatch($batch);
                $batch = [];
            }
        }
        if ($batch) {
            $this->deleteBatch($batch);
        }
    }

    public function exists(string $key): bool
    {
        $this->assertEnabled();
        try {
            $this->client()->headObject(['Bucket' => $this->bucket(), 'Key' => $this->fullKey($key)]);

            return true;
        } catch (AwsException $e) {
            if (404 === $e->getStatusCode() || in_array($e->getAwsErrorCode(), ['NotFound', 'NoSuchKey'], true)) {
                return false;
            }

            throw new MediaStorageException($e->getMessage(), 0, $e);
        }
    }

    /**
     * @return list<MediaObject>
     */
    public function listObjects(string $prefix): array
    {
        $this->assertEnabled();
        $objects = [];
        $token   = null;
        $root    = $this->root().'/';

        do {
            $args = ['Prefix' => $this->fullKey($prefix)];
            if (null !== $token) {
                $args['ContinuationToken'] = $token;
            }
            $result = $this->execute('ListObjectsV2', $args);

            foreach ($result['Contents'] ?? [] as $item) {
                $fullKey = (string) $item['Key'];
                if (!str_starts_with($fullKey, $root) || str_ends_with($fullKey, '/')) {
                    continue;
                }
                $key       = substr($fullKey, strlen($root));
                $modified  = $item['LastModified'];
                $objects[] = new MediaObject(
                    $key,
                    $this->getPublicUrl($key),
                    (int) $item['Size'],
                    $modified instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface($modified) : new \DateTimeImmutable((string) $modified),
                );
            }

            $token = !empty($result['IsTruncated']) ? ($result['NextContinuationToken'] ?? null) : null;
        } while (null !== $token);

        return $objects;
    }

    public function getPublicUrl(string $key): string
    {
        $this->assertEnabled();

        return $this->baseUrl().$this->encodePath($this->fullKey($key));
    }

    /**
     * Returns the root-relative key, or null when the URL is not under our bucket/root.
     */
    public function keyFromPublicUrl(string $url): ?string
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $base = $this->baseUrl().$this->encodePath($this->root()).'/';
        if (!str_starts_with($url, $base)) {
            return null;
        }

        $path = explode('?', explode('#', substr($url, strlen($base)), 2)[0], 2)[0];
        if ('' === $path) {
            return null;
        }

        $key = implode('/', array_map('rawurldecode', explode('/', $path)));
        if (in_array('..', explode('/', $key), true)) {
            return null;
        }

        return $key;
    }

    private function assertEnabled(): void
    {
        if (!$this->isEnabled()) {
            throw new MediaStorageException('S3 media storage is not configured');
        }
    }

    private function bucket(): string
    {
        return trim((string) $this->parameters->get('s3_bucket'));
    }

    private function region(): string
    {
        return trim((string) $this->parameters->get('s3_region'));
    }

    private function root(): string
    {
        return trim((string) $this->parameters->get('s3_root_directory'), " \t/");
    }

    private function baseUrl(): string
    {
        return sprintf('https://%s.s3.%s.amazonaws.com/', $this->bucket(), $this->region());
    }

    private function fullKey(string $key): string
    {
        return $this->root().'/'.ltrim($key, '/');
    }

    private function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    private function client(): S3ClientInterface
    {
        return $this->client ??= new S3Client([
            'version'     => 'latest',
            'region'      => $this->region(),
            'credentials' => [
                'key'    => (string) $this->parameters->get('s3_access_key_id'),
                'secret' => (string) $this->parameters->get('s3_secret_access_key'),
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $args
     */
    private function execute(string $operation, array $args): \Aws\ResultInterface
    {
        try {
            return $this->client()->execute($this->client()->getCommand($operation, ['Bucket' => $this->bucket()] + $args));
        } catch (AwsException $e) {
            throw new MediaStorageException($e->getMessage(), 0, $e);
        }
    }

    /**
     * @param list<array{Key: string}> $objects
     */
    private function deleteBatch(array $objects): void
    {
        $result = $this->execute('DeleteObjects', ['Delete' => ['Objects' => $objects, 'Quiet' => true]]);
        if (!empty($result['Errors'])) {
            throw new MediaStorageException(sprintf('Failed to delete %d object(s) from S3', count($result['Errors'])));
        }
    }
}
