<?php

declare(strict_types=1);

namespace Mautic\CoreBundle\Tests\Functional\Controller;

use Mautic\CoreBundle\Exception\MediaStorageException;
use Mautic\CoreBundle\Storage\DTO\MediaObject;
use Mautic\CoreBundle\Storage\MediaStorage;
use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Covers the S3 branches of the editor file controller (upload/list/delete) with MediaStorage mocked.
 */
final class FileControllerS3Test extends MauticMysqlTestCase
{
    private ?string $tmpFile = null;

    /**
     * @return MediaStorage&MockObject
     */
    private function enabledStorage(): MockObject
    {
        $storage = $this->createMock(MediaStorage::class);
        $storage->method('isEnabled')->willReturn(true);
        static::getContainer()->set(MediaStorage::class, $storage);
        static::getContainer()->set('mautic.storage.media', $storage);

        return $storage;
    }

    public function testUploadPushesToS3AndReturnsBucketUrl(): void
    {
        $storage = $this->enabledStorage();
        $storage->method('exists')->willReturn(false);
        $storage->expects($this->once())->method('putFile')
            ->with($this->matchesRegularExpression('#^media/images/[0-9a-f]{16}-.*\.png$#'))
            ->willReturnCallback(fn (string $key): string => 'https://bkt.s3.us-east-1.amazonaws.com/root/'.$key);

        $this->client->request('POST', 's/file/upload?editor=ckeditor', [], ['upload' => $this->upload()]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertTrue($data['uploaded']);
        $this->assertStringStartsWith('https://bkt.s3.us-east-1.amazonaws.com/root/media/images/', $data['url']);
    }

    public function testUploadFailureOnS3IsReportedNotSuccessful(): void
    {
        $storage = $this->enabledStorage();
        $storage->method('exists')->willReturn(false);
        $storage->method('putFile')->willThrowException(new MediaStorageException('boom'));

        $this->client->request('POST', 's/file/upload?editor=ckeditor', [], ['upload' => $this->upload()]);

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertFalse($data['uploaded']);
        $this->assertArrayNotHasKey('url', $data);
    }

    public function testListReturnsOnlyTopLevelImagesFromS3(): void
    {
        $storage = $this->enabledStorage();
        $storage->method('listObjects')->with('media/images/')->willReturn([
            new MediaObject('media/images/a.png', 'https://x/media/images/a.png', 1, new \DateTimeImmutable()),
            new MediaObject('media/images/lead_avatars/avatar1', 'https://x/media/images/lead_avatars/avatar1', 1, new \DateTimeImmutable()),
            new MediaObject('media/images/doc.txt', 'https://x/media/images/doc.txt', 1, new \DateTimeImmutable()),
        ]);

        $this->client->request('GET', 's/file/list');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertCount(1, $data);
        $this->assertSame('a.png', $data[0]['name']);
        $this->assertSame('https://x/media/images/a.png', $data[0]['url']);
    }

    public function testListFailureReturnsError(): void
    {
        $storage = $this->enabledStorage();
        $storage->method('listObjects')->willThrowException(new MediaStorageException('boom'));

        $this->client->request('GET', 's/file/list');

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testDeleteRemovesObjectFromS3(): void
    {
        $storage = $this->enabledStorage();
        $storage->method('exists')->with('media/images/a.png')->willReturn(true);
        $storage->expects($this->once())->method('delete')->with('media/images/a.png');

        $this->client->request('POST', 's/file/delete', ['src' => 'https://x/media/images/a.png']);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertTrue($data['deleted']);
    }

    public function testDeleteFailureReturnsServerError(): void
    {
        $storage = $this->enabledStorage();
        $storage->method('exists')->willReturn(true);
        $storage->method('delete')->willThrowException(new MediaStorageException('boom'));

        $this->client->request('POST', 's/file/delete', ['src' => 'a.png']);

        $this->assertResponseStatusCodeSame(500);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertArrayNotHasKey('deleted', $data);
    }

    private function upload(): UploadedFile
    {
        $fixtures      = (string) realpath(__DIR__.'/../../Fixtures/').'/';
        $this->tmpFile = $fixtures.'tmp-s3-test.png';
        copy($fixtures.'png-test.png', $this->tmpFile);

        return new UploadedFile($this->tmpFile, 'tmp-s3-test.png', 'image/png');
    }

    protected function beforeTearDown(): void
    {
        if ($this->tmpFile && file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
        }
    }
}
