<?php

declare(strict_types=1);

namespace MauticPlugin\GrapesJsBuilderBundle\Tests\Unit\Helper;

use Mautic\CoreBundle\Exception\FileUploadException;
use Mautic\CoreBundle\Exception\MediaStorageException;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\FileUploader;
use Mautic\CoreBundle\Helper\PathsHelper;
use Mautic\CoreBundle\Storage\DTO\MediaObject;
use Mautic\CoreBundle\Storage\ImageStorage;
use Mautic\CoreBundle\Storage\MediaStorage;
use MauticPlugin\GrapesJsBuilderBundle\Helper\FileManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

class FileManagerTest extends TestCase
{
    private string $dir;

    /**
     * @var FileUploader&MockObject
     */
    private FileUploader $fileUploader;

    /**
     * @var CoreParametersHelper&MockObject
     */
    private CoreParametersHelper $coreParametersHelper;

    /**
     * @var PathsHelper&MockObject
     */
    private PathsHelper $pathsHelper;

    /**
     * @var MediaStorage&MockObject
     */
    private MediaStorage $mediaStorage;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/fm-test-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/images', 0777, true);

        $this->fileUploader         = $this->createMock(FileUploader::class);
        $this->coreParametersHelper = $this->createMock(CoreParametersHelper::class);
        $this->pathsHelper          = $this->createMock(PathsHelper::class);
        $this->mediaStorage         = $this->createMock(MediaStorage::class);

        $this->pathsHelper->method('getSystemPath')->willReturnCallback(
            fn (string $name, bool $full = false) => $full ? $this->dir.'/images' : 'media/images'
        );
        $this->coreParametersHelper->method('get')->willReturnCallback(
            fn (string $name) => match ($name) {
                'image_path_exclude' => ['flags', 'mejs'],
                'site_url'           => 'https://mautic.test',
                default              => null,
            }
        );
        $this->fileUploader->method('getAllowedImageExtensions')->willReturn(['jpg', 'jpeg', 'png', 'gif', 'webp']);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->dir);
    }

    public function testUploadReturnsS3UrlAndRemovesLocalFile(): void
    {
        $this->mediaStorage->method('isEnabled')->willReturn(true);
        $this->fakeUploader(['abc.png']);
        $pushed = [];
        $this->mediaStorage->expects($this->once())->method('putFile')
            ->willReturnCallback(function (string $key, string $local) use (&$pushed) {
                $pushed[] = [$key, $local, is_file($local)];

                return 'https://s3.test/'.$key;
            });

        $urls = $this->manager()->uploadFiles($this->request());

        $this->assertCount(1, $urls);
        $this->assertSame('https://s3.test/'.$pushed[0][0], $urls[0]);
        $this->assertTrue($pushed[0][2], 'renamed local file existed when pushed');
        $this->assertSame([], glob($this->dir.'/images/*'));
    }

    public function testStoredImageNameHasRandomPrefixAndKeepsExtension(): void
    {
        $this->mediaStorage->method('isEnabled')->willReturn(true);
        $this->fakeUploader(['logo.png']);
        $this->mediaStorage->method('putFile')->willReturnCallback(fn (string $key) => 'https://s3.test/'.$key);

        $urls = $this->manager()->uploadFiles($this->request());

        $this->assertMatchesRegularExpression('#^https://s3\.test/media/images/[0-9a-f]{16}-logo\.png$#', $urls[0]);
    }

    public function testSameOriginalNameUploadedTwiceGetsDistinctKeys(): void
    {
        $this->mediaStorage->method('isEnabled')->willReturn(true);
        $this->fakeUploader(['logo.png', 'logo.png']);
        $this->mediaStorage->method('putFile')->willReturnCallback(fn (string $key) => 'https://s3.test/'.$key);

        $first  = $this->manager()->uploadFiles($this->request());
        $second = $this->manager()->uploadFiles($this->request());

        $this->assertNotSame($first[0], $second[0]);
    }

    public function testCollisionRegeneratesOnceThenFails(): void
    {
        $this->mediaStorage->method('isEnabled')->willReturn(true);
        $this->fakeUploader(['logo.png', 'logo.png']);
        $this->mediaStorage->method('putFile')->willReturnCallback(fn (string $key) => 'https://s3.test/'.$key);

        // First attempt collides, second is free: upload succeeds.
        $this->mediaStorage->expects($this->exactly(4))->method('exists')->willReturnOnConsecutiveCalls(true, false, true, true);
        $this->assertCount(1, $this->manager()->uploadFiles($this->request()));

        // Two collisions in a row: the upload fails and nothing is left locally.
        try {
            $this->manager()->uploadFiles($this->request());
            $this->fail('Expected FileUploadException');
        } catch (FileUploadException) {
        }
        $this->assertSame([], glob($this->dir.'/images/*'));
    }

    public function testUploadFailureRemovesLocalFileAndThrows(): void
    {
        $this->mediaStorage->method('isEnabled')->willReturn(true);
        $this->fakeUploader(['abc.png']);
        $this->mediaStorage->method('putFile')->willThrowException(new MediaStorageException('boom'));

        try {
            $this->manager()->uploadFiles($this->request());
            $this->fail('Expected FileUploadException');
        } catch (FileUploadException) {
        }

        $this->assertSame([], glob($this->dir.'/images/*'));
    }

    public function testMultiFileFailureDeletesOnlyThisRequestsObjects(): void
    {
        $this->mediaStorage->method('isEnabled')->willReturn(true);
        $this->fakeUploader(['a.png', 'b.png']);
        $pushed = [];
        $calls  = 0;
        $this->mediaStorage->method('putFile')->willReturnCallback(function (string $key) use (&$pushed, &$calls) {
            if (2 === ++$calls) {
                throw new MediaStorageException('boom');
            }
            $pushed[] = $key;

            return 'https://s3.test/'.$key;
        });
        $deleted = [];
        $this->mediaStorage->method('delete')->willReturnCallback(function (string $key) use (&$deleted) {
            $deleted[] = $key;
        });

        try {
            $this->manager()->uploadFiles($this->request(2));
            $this->fail('Expected FileUploadException');
        } catch (FileUploadException) {
        }

        $this->assertCount(1, $pushed);
        $this->assertSame($pushed, $deleted);
        $this->assertSame([], glob($this->dir.'/images/*'));
    }

    public function testMediaFilesListsBucketNewestFirstAndPaginates(): void
    {
        $this->mediaStorage->method('isEnabled')->willReturn(true);
        $this->mediaStorage->method('listObjects')->with('media/images/')->willReturn([
            $this->object('old.png', '2024-01-01'),
            $this->object('new.png', '2024-03-01'),
            $this->object('mid.png', '2024-02-01'),
        ]);

        $result = $this->manager()->getMediaFiles(1, 2);

        $this->assertSame(3, $result['totalItems']);
        $this->assertSame(2, $result['totalPages']);
        $this->assertTrue($result['hasNextPage']);
        $this->assertFalse($result['hasPreviousPage']);
        $this->assertSame([
            ['src' => 'https://s3.test/media/images/new.png', 'type' => 'image'],
            ['src' => 'https://s3.test/media/images/mid.png', 'type' => 'image'],
        ], $result['data']);
    }

    public function testMediaFilesSkipsExcludedFolders(): void
    {
        $this->mediaStorage->method('isEnabled')->willReturn(true);
        $this->mediaStorage->method('listObjects')->willReturn([
            $this->object('flags/br.png', '2024-01-01'),
            $this->object('a.png', '2024-01-02'),
            $this->object('doc.pdf', '2024-01-03'),
        ]);

        $result = $this->manager()->getMediaFiles(1, 10);

        $this->assertSame(2, $result['totalItems']);
        $this->assertSame([
            ['src' => 'https://s3.test/media/images/doc.pdf', 'type' => 'document'],
            ['src' => 'https://s3.test/media/images/a.png', 'type' => 'image'],
        ], $result['data']);
    }

    public function testDeleteRemovesObject(): void
    {
        $this->mediaStorage->method('isEnabled')->willReturn(true);
        $this->mediaStorage->expects($this->once())->method('delete')->with('media/images/a.png');

        $this->manager()->deleteFile('../../a.png');
    }

    public function testDeleteRejectsInvalidNames(): void
    {
        $this->mediaStorage->method('isEnabled')->willReturn(true);
        $this->mediaStorage->expects($this->never())->method('delete');

        foreach (['', '.', '..', '/', "a\0b"] as $name) {
            try {
                $this->manager()->deleteFile($name);
                $this->fail('Expected exception for '.json_encode($name));
            } catch (\InvalidArgumentException) {
            }
        }
    }

    public function testUsesLocalDiskWhenDisabled(): void
    {
        $this->mediaStorage->method('isEnabled')->willReturn(false);
        $this->mediaStorage->expects($this->never())->method('listObjects');
        $this->mediaStorage->expects($this->never())->method('putFile');
        $this->mediaStorage->expects($this->never())->method('delete');
        $this->fileUploader->method('upload')->willReturn('abc.png');

        file_put_contents($this->dir.'/images/note.pdf', 'x');

        $result = $this->manager()->getMediaFiles(1, 10);
        $this->assertSame(1, $result['totalItems']);
        $this->assertSame([['src' => 'https://mautic.test/media/images/note.pdf', 'type' => 'document']], $result['data']);

        $this->assertSame(['https://mautic.test/media/images/abc.png'], $this->manager()->uploadFiles($this->request()));

        $this->fileUploader->expects($this->once())->method('delete')->with($this->dir.'/images/note.pdf');
        $this->manager()->deleteFile('note.pdf');
    }

    private function manager(): FileManager
    {
        return new FileManager($this->fileUploader, $this->coreParametersHelper, $this->pathsHelper, $this->mediaStorage, new ImageStorage($this->mediaStorage));
    }

    /**
     * @param string[] $names names returned by consecutive FileUploader::upload() calls (files are created locally)
     */
    private function fakeUploader(array $names): void
    {
        $names = array_values($names);
        $i     = 0;
        $this->fileUploader->method('upload')->willReturnCallback(function () use (&$i, $names) {
            $name = $names[$i++ % count($names)];
            file_put_contents($this->dir.'/images/'.$name, 'png');

            return $name;
        });
    }

    private function request(int $count = 1): Request
    {
        $uploads = [];
        for ($n = 0; $n < $count; ++$n) {
            $tmp = tempnam(sys_get_temp_dir(), 'up');
            file_put_contents($tmp, 'png');
            $uploads[] = new UploadedFile($tmp, 'x.png', 'image/png', null, true);
        }
        $request = new Request();
        $request->files->set('files', $uploads);

        return $request;
    }

    private function object(string $name, string $date): MediaObject
    {
        return new MediaObject('media/images/'.$name, 'https://s3.test/media/images/'.$name, 1, new \DateTimeImmutable($date));
    }

    private function removeDir(string $dir): void
    {
        foreach (glob($dir.'/*') ?: [] as $entry) {
            is_dir($entry) ? $this->removeDir($entry) : unlink($entry);
        }
        @rmdir($dir);
    }
}
