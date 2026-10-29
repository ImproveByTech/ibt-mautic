<?php

declare(strict_types=1);

namespace Mautic\AssetBundle\Tests\EventListener;

use Mautic\AssetBundle\AssetEvents;
use Mautic\AssetBundle\Entity\Asset;
use Mautic\AssetBundle\Entity\AssetRepository;
use Mautic\AssetBundle\Event\AssetEvent;
use Mautic\AssetBundle\EventListener\AssetMediaStorageSubscriber;
use Mautic\CoreBundle\Exception\MediaStorageException;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Storage\MediaStorage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class AssetMediaStorageSubscriberTest extends TestCase
{
    private string $dir;

    /**
     * @var MediaStorage&MockObject
     */
    private MediaStorage $storage;

    /**
     * @var AssetRepository&MockObject
     */
    private AssetRepository $repo;

    /**
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface $logger;

    private AssetMediaStorageSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->dir = (string) realpath(sys_get_temp_dir()).'/asset-s3-'.uniqid();
        mkdir($this->dir);
        $this->storage = $this->createMock(MediaStorage::class);
        $this->storage->method('isEnabled')->willReturn(true);
        $params = $this->createMock(CoreParametersHelper::class);
        $params->method('get')->with('upload_dir')->willReturn($this->dir);
        $this->repo       = $this->createMock(AssetRepository::class);
        $this->logger     = $this->createMock(LoggerInterface::class);
        $this->subscriber = new AssetMediaStorageSubscriber($this->storage, $params, $this->repo, $this->logger);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
        array_map('unlink', glob($this->dir.'-outside*') ?: []);
    }

    private function localAsset(string $name, bool $createFile = true): Asset
    {
        if ($createFile) {
            file_put_contents($this->dir.'/'.$name, 'data');
        }
        $asset = new Asset();
        $asset->setStorageLocation('local');
        $asset->setPath($name);

        return $asset;
    }

    public function testSubscribedEvents(): void
    {
        $this->assertSame([
            AssetEvents::ASSET_PRE_SAVE    => ['onAssetPreSave', 0],
            AssetEvents::ASSET_POST_DELETE => ['onAssetPostDelete', 0],
        ], AssetMediaStorageSubscriber::getSubscribedEvents());
    }

    public function testLocalAssetIsPushedAndBecomesRemote(): void
    {
        $asset = $this->localAsset('abc.pdf');
        $this->storage->expects($this->once())->method('putFile')
            ->with('media/files/abc.pdf', $this->dir.'/abc.pdf')
            ->willReturn('https://cdn.example.com/root/media/files/abc.pdf');

        $this->subscriber->onAssetPreSave(new AssetEvent($asset, true));

        $this->assertSame('remote', $asset->getStorageLocation());
        $this->assertSame('https://cdn.example.com/root/media/files/abc.pdf', $asset->getRemotePath());
        $this->assertFileDoesNotExist($this->dir.'/abc.pdf');
    }

    public function testNothingHappensWhenDisabled(): void
    {
        $storage = $this->createMock(MediaStorage::class);
        $storage->method('isEnabled')->willReturn(false);
        $storage->expects($this->never())->method('putFile');
        $subscriber = new AssetMediaStorageSubscriber($storage, $this->createMock(CoreParametersHelper::class), $this->repo, $this->logger);
        $asset      = $this->localAsset('abc.pdf');

        $subscriber->onAssetPreSave(new AssetEvent($asset, true));

        $this->assertSame('local', $asset->getStorageLocation());
        $this->assertFileExists($this->dir.'/abc.pdf');
    }

    public function testExternalRemoteAssetIsNeverTouched(): void
    {
        $asset = new Asset();
        $asset->setStorageLocation('remote');
        $asset->setRemotePath('https://example.com/x.pdf');
        $this->storage->expects($this->never())->method('putFile');
        $this->storage->method('keyFromPublicUrl')->willReturn(null);
        $this->storage->expects($this->never())->method('delete');

        $this->subscriber->onAssetPreSave(new AssetEvent($asset, false));
        $this->subscriber->onAssetPostDelete(new AssetEvent($asset, false));
    }

    public function testSavingS3AssetWithoutNewFileDoesNothing(): void
    {
        $asset = new Asset();
        $asset->setStorageLocation('remote');
        $asset->setRemotePath('https://cdn.example.com/root/media/files/a.pdf');
        $this->storage->expects($this->never())->method('putFile');
        $this->storage->expects($this->never())->method('delete');

        $this->subscriber->onAssetPreSave(new AssetEvent($asset, false));
    }

    public function testReplacingFileDeletesOldObjectAfterPut(): void
    {
        $asset = $this->localAsset('new.pdf');
        $asset->setRemotePath('https://cdn.example.com/root/media/files/old.pdf');
        $calls = [];
        $this->storage->method('putFile')->willReturnCallback(function (string $key) use (&$calls): string {
            $calls[] = 'put:'.$key;

            return 'https://cdn.example.com/root/'.$key;
        });
        $this->storage->method('keyFromPublicUrl')->willReturn('media/files/old.pdf');
        $this->storage->method('delete')->willReturnCallback(function (string $key) use (&$calls): void {
            $calls[] = 'delete:'.$key;
        });

        $this->subscriber->onAssetPreSave(new AssetEvent($asset, false));

        $this->assertSame(['put:media/files/new.pdf', 'delete:media/files/old.pdf'], $calls);
    }

    public function testPutFailureKeepsLocalFileAndThrows(): void
    {
        $asset = $this->localAsset('abc.pdf');
        $this->storage->method('putFile')->willThrowException(new MediaStorageException('boom'));

        try {
            $this->subscriber->onAssetPreSave(new AssetEvent($asset, true));
            $this->fail('Expected MediaStorageException');
        } catch (MediaStorageException) {
        }

        $this->assertFileExists($this->dir.'/abc.pdf');
        $this->assertSame('local', $asset->getStorageLocation());
    }

    public function testPostDeleteDeletesOwnObject(): void
    {
        $asset = new Asset();
        $asset->setStorageLocation('remote');
        $asset->setRemotePath('https://cdn.example.com/root/media/files/a.pdf');
        $this->storage->method('keyFromPublicUrl')->with('https://cdn.example.com/root/media/files/a.pdf')->willReturn('media/files/a.pdf');
        $this->storage->expects($this->once())->method('delete')->with('media/files/a.pdf');

        $this->subscriber->onAssetPostDelete(new AssetEvent($asset, false));
    }

    public function testPathOutsideUploadDirIsNeverPushedOrUnlinked(): void
    {
        $outside = $this->dir.'-outside.txt';
        file_put_contents($outside, 'secret');
        $this->storage->expects($this->never())->method('putFile');
        $this->logger->expects($this->atLeastOnce())->method('warning');

        foreach (['../'.basename($outside), $outside] as $path) {
            $asset = new Asset();
            $asset->setStorageLocation('local');
            $asset->setPath($path);
            $this->subscriber->onAssetPreSave(new AssetEvent($asset, true));
            $this->assertSame('local', $asset->getStorageLocation());
        }

        $this->assertFileExists($outside);
    }

    public function testDeleteSkippedWhenAnotherAssetSharesTheObject(): void
    {
        $url = 'https://cdn.example.com/root/media/files/a.pdf';
        $this->storage->method('keyFromPublicUrl')->willReturn('media/files/a.pdf');
        $this->repo->expects($this->once())->method('existsOtherWithRemotePath')->with($url, null)->willReturn(true);
        $this->storage->expects($this->never())->method('delete');
        $asset = new Asset();
        $asset->setStorageLocation('remote');
        $asset->setRemotePath($url);

        $this->subscriber->onAssetPostDelete(new AssetEvent($asset, false));
    }

    public function testDeleteSkippedForNonFilesPrefixKey(): void
    {
        $this->storage->method('keyFromPublicUrl')->willReturn('media/images/a.png');
        $this->repo->method('existsOtherWithRemotePath')->willReturn(false);
        $this->storage->expects($this->never())->method('delete');
        $asset = new Asset();
        $asset->setStorageLocation('remote');
        $asset->setRemotePath('https://cdn.example.com/root/media/images/a.png');

        $this->subscriber->onAssetPostDelete(new AssetEvent($asset, false));
    }

    public function testReplacingFileKeepsOldObjectUsedByAnotherAsset(): void
    {
        $asset = $this->localAsset('new.pdf');
        $asset->setRemotePath('https://cdn.example.com/root/media/files/old.pdf');
        $this->storage->method('putFile')->willReturn('https://cdn.example.com/root/media/files/new.pdf');
        $this->storage->method('keyFromPublicUrl')->willReturn('media/files/old.pdf');
        $this->repo->method('existsOtherWithRemotePath')->willReturn(true);
        $this->storage->expects($this->never())->method('delete');

        $this->subscriber->onAssetPreSave(new AssetEvent($asset, false));
    }

    public function testLocalFileKeptWhenAnotherLocalAssetUsesIt(): void
    {
        $asset = $this->localAsset('abc.pdf');
        $this->storage->method('putFile')->willReturn('https://cdn.example.com/root/media/files/abc.pdf');
        $this->repo->method('existsOtherLocalWithPath')->willReturn(true);

        $this->subscriber->onAssetPreSave(new AssetEvent($asset, true));

        $this->assertSame('remote', $asset->getStorageLocation());
        $this->assertFileExists($this->dir.'/abc.pdf');
    }

    public function testOldObjectDeleteFailureDoesNotFailSave(): void
    {
        $asset = $this->localAsset('new.pdf');
        $asset->setRemotePath('https://cdn.example.com/root/media/files/old.pdf');
        $this->storage->method('putFile')->willReturn('https://cdn.example.com/root/media/files/new.pdf');
        $this->storage->method('keyFromPublicUrl')->willReturn('media/files/old.pdf');
        $this->storage->method('delete')->willThrowException(new MediaStorageException('nope'));
        $this->logger->expects($this->once())->method('error');

        $this->subscriber->onAssetPreSave(new AssetEvent($asset, false));

        $this->assertSame('remote', $asset->getStorageLocation());
    }

    public function testPostDeleteFailureDoesNotThrow(): void
    {
        $this->storage->method('keyFromPublicUrl')->willReturn('media/files/a.pdf');
        $this->storage->method('delete')->willThrowException(new MediaStorageException('nope'));
        $this->logger->expects($this->once())->method('error');
        $asset = new Asset();
        $asset->setStorageLocation('remote');
        $asset->setRemotePath('https://cdn.example.com/root/media/files/a.pdf');

        $this->subscriber->onAssetPostDelete(new AssetEvent($asset, false));
        $this->addToAssertionCount(1);
    }

    public function testReplacementUploadOnS3BackedAssetIsPushed(): void
    {
        $asset = $this->localAsset('new.pdf');
        $asset->setStorageLocation('remote');
        $asset->setRemotePath('https://cdn.example.com/root/media/files/old.pdf');
        $this->storage->method('keyFromPublicUrl')->willReturn('media/files/old.pdf');
        $this->storage->expects($this->once())->method('putFile')
            ->with('media/files/new.pdf')->willReturn('https://cdn.example.com/root/media/files/new.pdf');
        $this->storage->expects($this->once())->method('delete')->with('media/files/old.pdf');

        $this->subscriber->onAssetPreSave(new AssetEvent($asset, false));

        $this->assertSame('https://cdn.example.com/root/media/files/new.pdf', $asset->getRemotePath());
        $this->assertFileDoesNotExist($this->dir.'/new.pdf');
    }

    public function testExternalRemoteAssetWithStaleFileIsNotPushed(): void
    {
        $asset = $this->localAsset('stale.pdf');
        $asset->setStorageLocation('remote');
        $asset->setRemotePath('https://example.com/x.pdf');
        $this->storage->method('keyFromPublicUrl')->willReturn(null);
        $this->storage->expects($this->never())->method('putFile');

        $this->subscriber->onAssetPreSave(new AssetEvent($asset, false));

        $this->assertFileExists($this->dir.'/stale.pdf');
    }
}
