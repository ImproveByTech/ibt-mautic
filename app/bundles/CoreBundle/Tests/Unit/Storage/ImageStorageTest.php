<?php

declare(strict_types=1);

namespace Mautic\CoreBundle\Tests\Unit\Storage;

use Mautic\CoreBundle\Exception\MediaStorageException;
use Mautic\CoreBundle\Storage\ImageStorage;
use Mautic\CoreBundle\Storage\MediaStorage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ImageStorageTest extends TestCase
{
    private string $dir;

    /**
     * @var MediaStorage&MockObject
     */
    private MediaStorage $mediaStorage;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/is-test-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->mediaStorage = $this->createMock(MediaStorage::class);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);
    }

    public function testGetKeyUsesBasenameUnderImagesPrefix(): void
    {
        $storage = new ImageStorage($this->mediaStorage);

        $this->assertSame('media/images/a.png', $storage->getKey('../../a.png'));
        $this->assertSame('media/images/sub/a.png', $storage->getKey('a.png', 'sub'));
    }

    public function testGetKeyRejectsInvalidNames(): void
    {
        $storage = new ImageStorage($this->mediaStorage);

        foreach (['', '.', '..', '/', "a\0b"] as $name) {
            try {
                $storage->getKey($name);
                $this->fail('Expected exception for '.json_encode($name));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testStoreRenamesPushesAndRemovesLocalFile(): void
    {
        file_put_contents($this->dir.'/logo.png', 'x');
        $this->mediaStorage->method('exists')->willReturn(false);
        $this->mediaStorage->expects($this->once())->method('putFile')->willReturnCallback(
            function (string $key, string $local) {
                $this->assertFileExists($local);
                $this->assertStringEndsWith(basename($key), $local);

                return 'https://s3.test/'.$key;
            }
        );

        $result = (new ImageStorage($this->mediaStorage))->store($this->dir, 'logo.png');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}-logo\.png$/', $result['name']);
        $this->assertSame('media/images/'.$result['name'], $result['key']);
        $this->assertSame('https://s3.test/'.$result['key'], $result['url']);
        $this->assertSame([], glob($this->dir.'/*'));
    }

    public function testStoreFailsAfterSecondCollisionAndCleansLocalFile(): void
    {
        file_put_contents($this->dir.'/logo.png', 'x');
        $this->mediaStorage->expects($this->exactly(2))->method('exists')->willReturn(true);
        $this->mediaStorage->expects($this->never())->method('putFile');

        try {
            (new ImageStorage($this->mediaStorage))->store($this->dir, 'logo.png');
            $this->fail('Expected MediaStorageException');
        } catch (MediaStorageException) {
        }

        $this->assertSame([], glob($this->dir.'/*'));
    }

    public function testDeleteQuietlySwallowsFailures(): void
    {
        $this->mediaStorage->expects($this->exactly(2))->method('delete')
            ->willReturnCallback(function (string $key) {
                if ('media/images/a' === $key) {
                    throw new MediaStorageException('x');
                }
            });

        (new ImageStorage($this->mediaStorage))->deleteQuietly(['media/images/a', 'media/images/b']);
    }
}
