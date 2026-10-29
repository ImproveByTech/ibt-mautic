<?php

declare(strict_types=1);

namespace MauticPlugin\GrapesJsBuilderBundle\Tests\Unit\Helper;

use Mautic\CoreBundle\Exception\FileUploadException;
use Mautic\CoreBundle\Exception\MediaStorageException;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\FileUploader;
use Mautic\CoreBundle\Helper\PathsHelper;
use Mautic\CoreBundle\Storage\DTO\MediaObject;
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
        $this->fileUploader->method('upload')->willReturnCallback(function () {
            file_put_contents($this->dir.'/images/abc.png', 'png');

            return 'abc.png';
        });
        $this->mediaStorage->expects($this->once())->method('putFile')
            ->with('media/images/abc.png', $this->dir.'/images/abc.png')
            ->willReturn('https://b.s3.r.amazonaws.com/root/media/images/abc.png');

        $urls = $this->manager()->uploadFiles($this->request());

        $this->assertSame(['https://b.s3.r.amazonaws.com/root/media/images/abc.png'], $urls);
        $this->assertFileDoesNotExist($this->dir.'/images/abc.png');
    }

    public function testUploadFailureRemovesLocalFileAndThrows(): void
    {
        $this->mediaStorage->method('isEnabled')->willReturn(true);
        $this->fileUploader->method('upload')->willReturnCallback(function () {
            file_put_contents($this->dir.'/images/abc.png', 'png');

            return 'abc.png';
        });
        $this->mediaStorage->method('putFile')->willThrowException(new MediaStorageException('boom'));

        try {
            $this->manager()->uploadFiles($this->request());
            $this->fail('Expected FileUploadException');
        } catch (FileUploadException) {
        }

        $this->assertFileDoesNotExist($this->dir.'/images/abc.png');
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

        foreach (['', '.', '..', '/',"a\0b"] as $name) {
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
        return new FileManager($this->fileUploader, $this->coreParametersHelper, $this->pathsHelper, $this->mediaStorage);
    }

    private function request(): Request
    {
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($tmp, 'png');
        $request = new Request();
        $request->files->set('files', [new UploadedFile($tmp, 'x.png', 'image/png', null, true)]);

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
