<?php

declare(strict_types=1);

namespace Mautic\AssetBundle\Tests\EventListener;

use Mautic\AssetBundle\AssetEvents;
use Mautic\AssetBundle\Entity\Asset;
use Mautic\AssetBundle\Event\AssetEvent;
use Mautic\AssetBundle\EventListener\AssetMediaStorageSubscriber;
use Mautic\CoreBundle\Exception\MediaStorageException;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Storage\MediaStorage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class AssetMediaStorageSubscriberTest extends TestCase
{
    private string $dir;

    /**
     * @var MediaStorage&MockObject
     */
    private MediaStorage $storage;

    private AssetMediaStorageSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/asset-s3-'.uniqid();
        mkdir($this->dir);
        $this->storage = $this->createMock(MediaStorage::class);
        $this->storage->method('isEnabled')->willReturn(true);
        $params = $this->createMock(CoreParametersHelper::class);
        $params->method('get')->with('upload_dir')->willReturn($this->dir);
        $this->subscriber = new AssetMediaStorageSubscriber($this->storage, $params);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
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
        $subscriber = new AssetMediaStorageSubscriber($storage, $this->createMock(CoreParametersHelper::class));
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
}
