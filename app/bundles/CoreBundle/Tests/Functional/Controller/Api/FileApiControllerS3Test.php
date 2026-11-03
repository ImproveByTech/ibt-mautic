<?php

declare(strict_types=1);

namespace Mautic\CoreBundle\Tests\Functional\Controller\Api;

use Mautic\CoreBundle\Exception\MediaStorageException;
use Mautic\CoreBundle\Storage\DTO\MediaObject;
use Mautic\CoreBundle\Storage\MediaStorage;
use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Covers the S3 branches of the files API (images create/list/delete) with MediaStorage mocked.
 */
final class FileApiControllerS3Test extends MauticMysqlTestCase
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

    public function testCreateStoresImageInS3(): void
    {
        $storage = $this->enabledStorage();
        $storage->method('exists')->willReturn(false);
        $storage->expects($this->once())->method('putFile')
            ->with($this->matchesRegularExpression('#^media/images/[0-9a-f]{16}-.*\.png$#'))
            ->willReturnCallback(fn (string $key): string => 'https://bkt.s3.us-east-1.amazonaws.com/root/'.$key);

        $this->client->request('POST', '/api/files/images/new', [], ['file' => $this->upload()]);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertStringStartsWith('https://bkt.s3.us-east-1.amazonaws.com/root/media/images/', $data['file']['link']);
        $this->assertNotEmpty($data['file']['name']);
    }

    public function testCreateFailureOnS3IsAnErrorNotSuccess(): void
    {
        $storage = $this->enabledStorage();
        $storage->method('exists')->willReturn(false);
        $storage->method('putFile')->willThrowException(new MediaStorageException('boom'));

        $this->client->request('POST', '/api/files/images/new', [], ['file' => $this->upload()]);

        $this->assertResponseStatusCodeSame(500);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertArrayNotHasKey('file', $data);
    }

    public function testListReturnsTopLevelNamesFromS3(): void
    {
        $storage = $this->enabledStorage();
        $storage->method('listObjects')->with('media/images/')->willReturn([
            new MediaObject('media/images/a.png', 'https://x/a.png', 1, new \DateTimeImmutable()),
            new MediaObject('media/images/sub/b.png', 'https://x/b.png', 1, new \DateTimeImmutable()),
        ]);

        $this->client->request('GET', '/api/files/images');

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame(['a.png'], array_values($data['files']));
    }

    public function testListFailureReturnsError(): void
    {
        $storage = $this->enabledStorage();
        $storage->method('listObjects')->willThrowException(new MediaStorageException('boom'));

        $this->client->request('GET', '/api/files/images');

        $this->assertResponseStatusCodeSame(500);
    }

    public function testDeleteRemovesObjectFromS3(): void
    {
        $storage = $this->enabledStorage();
        $storage->method('exists')->with('media/images/a.png')->willReturn(true);
        $storage->expects($this->once())->method('delete')->with('media/images/a.png');

        $this->client->request('DELETE', '/api/files/images/a.png/delete');

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
    }

    public function testDeleteFailureReturnsError(): void
    {
        $storage = $this->enabledStorage();
        $storage->method('exists')->willReturn(true);
        $storage->method('delete')->willThrowException(new MediaStorageException('boom'));

        $this->client->request('DELETE', '/api/files/images/a.png/delete');

        $this->assertResponseStatusCodeSame(500);
    }

    private function upload(): UploadedFile
    {
        $fixtures      = (string) realpath(__DIR__.'/../../../Fixtures/').'/';
        $this->tmpFile = $fixtures.'tmp-s3-api-test.png';
        copy($fixtures.'png-test.png', $this->tmpFile);

        return new UploadedFile($this->tmpFile, 'tmp-s3-api-test.png', 'image/png');
    }

    protected function beforeTearDown(): void
    {
        if ($this->tmpFile && file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
        }
    }
}
