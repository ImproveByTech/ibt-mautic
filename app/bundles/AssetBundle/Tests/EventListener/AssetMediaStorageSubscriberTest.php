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
        $this->storage->method('getPublicUrl')->willReturnCallback(fn (string $k): string => 'https://cdn.example.com/root/'.$k);
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
            AssetEvents::ASSET_POST_SAVE   => ['onAssetPostSave', 0],
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
        $this->storage->method('keyFromPublicUrl')->willReturnCallback(fn (string $u): ?string => str_contains($u, 'old.pdf') ? 'media/files/old.pdf' : 'media/files/new.pdf');
        $this->storage->method('delete')->willReturnCallback(function (string $key) use (&$calls): void {
            $calls[] = 'delete:'.$key;
        });

        $this->subscriber->onAssetPreSave(new AssetEvent($asset, false));
        $this->assertSame(['put:media/files/new.pdf'], $calls);
        $this->subscriber->onAssetPostSave(new AssetEvent($asset, false));

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
        $this->storage->method('getRootUrl')->willReturn('https://cdn.example.com/root/');
        $this->repo->expects($this->once())->method('getOtherRemotePathsWithPrefix')->willReturn([$url]);
        $this->storage->expects($this->never())->method('delete');
        $asset = new Asset();
        $asset->setStorageLocation('remote');
        $asset->setRemotePath($url);

        $this->subscriber->onAssetPostDelete(new AssetEvent($asset, false));
    }

    public function testDeleteSkippedForNonFilesPrefixKey(): void
    {
        $this->storage->method('keyFromPublicUrl')->willReturn('media/images/a.png');
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
        $this->storage->method('getRootUrl')->willReturn('https://cdn.example.com/root/');
        $this->repo->method('getOtherRemotePathsWithPrefix')->willReturn(['https://cdn.example.com/root/media/files/old.pdf']);
        $this->storage->expects($this->never())->method('delete');

        $this->subscriber->onAssetPreSave(new AssetEvent($asset, false));
        $this->subscriber->onAssetPostSave(new AssetEvent($asset, false));
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
        $this->storage->method('keyFromPublicUrl')->willReturnCallback(fn (string $u): ?string => str_contains($u, 'old.pdf') ? 'media/files/old.pdf' : 'media/files/new.pdf');
        $this->storage->method('delete')->willThrowException(new MediaStorageException('nope'));
        $this->logger->expects($this->once())->method('error');

        $this->subscriber->onAssetPreSave(new AssetEvent($asset, false));
        $this->subscriber->onAssetPostSave(new AssetEvent($asset, false));

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
        $this->storage->method('keyFromPublicUrl')->willReturnCallback(fn (string $u): ?string => str_contains($u, 'old.pdf') ? 'media/files/old.pdf' : 'media/files/new.pdf');
        $this->storage->expects($this->once())->method('putFile')
            ->with('media/files/new.pdf')->willReturn('https://cdn.example.com/root/media/files/new.pdf');
        $this->storage->expects($this->once())->method('delete')->with('media/files/old.pdf');

        $this->subscriber->onAssetPreSave(new AssetEvent($asset, false));
        $this->subscriber->onAssetPostSave(new AssetEvent($asset, false));

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

    public function testDeleteSkippedWhenAnotherAssetSharesTheObjectViaEncodedSegmentVariant(): void
    {
        $this->testDeleteSkippedWhenAnotherAssetSharesTheObjectViaUrlVariant();
    }

    public function testDeleteSkippedWhenAnotherAssetSharesTheObjectViaUrlVariant(): void
    {
        $base = 'https://cdn.example.com/root/';
        $this->storage->method('getRootUrl')->willReturn($base);
        // Mirrors the real normalization: strip query/fragment, decode percent-encoding.
        $this->storage->method('keyFromPublicUrl')->willReturnCallback(function (string $u) use ($base): ?string {
            if (!str_starts_with($u, $base)) {
                return null;
            }
            $p = explode('?', explode('#', substr($u, strlen($base)), 2)[0], 2)[0];

            return rawurldecode($p);
        });
        $this->storage->expects($this->never())->method('delete');

        foreach (['media/files/abc.pdf?x=1', 'media/files/ab%63.pdf', '%6Dedia/files/abc.pdf'] as $variant) {
            $this->repo = $this->createMock(AssetRepository::class);
            $this->repo->method('getOtherRemotePathsWithPrefix')->willReturn([$base.$variant]);
            $params = $this->createMock(CoreParametersHelper::class);
            $sub    = new AssetMediaStorageSubscriber($this->storage, $params, $this->repo, $this->logger);
            $asset  = new Asset();
            $asset->setStorageLocation('remote');
            $asset->setRemotePath($base.'media/files/abc.pdf');

            $sub->onAssetPostDelete(new AssetEvent($asset, false));
        }
    }

    public function testRemotePathPointingAtOurBucketIsCanonicalized(): void
    {
        $this->storage->method('keyFromPublicUrl')->willReturn('media/files/abc.pdf');
        $asset = new Asset();
        $asset->setStorageLocation('remote');
        $asset->setRemotePath('https://cdn.example.com/root/media/files/abc.pdf?x=1');

        $this->subscriber->onAssetPreSave(new AssetEvent($asset, false));

        $this->assertSame('https://cdn.example.com/root/media/files/abc.pdf', $asset->getRemotePath());
    }

    public function testOrphanRemovalDecision(): void
    {
        // New upload moved in: remove.
        $this->assertTrue(AssetMediaStorageSubscriber::shouldRemoveOrphanedUpload(true, null, 'new.pdf', false));
        // No new file (legacy local asset re-saved): never remove its only copy.
        $this->assertFalse(AssetMediaStorageSubscriber::shouldRemoveOrphanedUpload(false, 'old.pdf', 'old.pdf', false));
        $this->assertFalse(AssetMediaStorageSubscriber::shouldRemoveOrphanedUpload(true, 'same.pdf', 'same.pdf', false));
        // Shared with another local asset: keep.
        $this->assertFalse(AssetMediaStorageSubscriber::shouldRemoveOrphanedUpload(true, null, 'new.pdf', true));
    }

    public function testReplacingWithSameKeyDifferentSpellingDoesNotDelete(): void
    {
        $asset = $this->localAsset('same.pdf');
        $asset->setRemotePath('https://cdn.example.com/root/media/files/same.pdf?v=1');
        $this->storage->method('putFile')->willReturn('https://cdn.example.com/root/media/files/same.pdf');
        $this->storage->method('keyFromPublicUrl')->willReturn('media/files/same.pdf');
        $this->storage->expects($this->never())->method('delete');

        $this->subscriber->onAssetPreSave(new AssetEvent($asset, false));
        $this->subscriber->onAssetPostSave(new AssetEvent($asset, false));
    }

    public function testOursRemotePathIsCanonicalizedEvenWhenLocal(): void
    {
        $asset = new Asset();
        $asset->setStorageLocation('local');
        $asset->setRemotePath('https://cdn.example.com/root/media/files/a.pdf?x=1');
        $this->storage->method('keyFromPublicUrl')->willReturn('media/files/a.pdf');

        $this->subscriber->onAssetPreSave(new AssetEvent($asset, false));

        $this->assertSame('https://cdn.example.com/root/media/files/a.pdf', $asset->getRemotePath());
    }

    public function testOldObjectIsDeletedOnlyOnPostSaveAndNotWhenPostSaveNeverFires(): void
    {
        $asset = $this->localAsset('new.pdf');
        $asset->setRemotePath('https://cdn.example.com/root/media/files/old.pdf');
        $this->storage->method('putFile')->willReturn('https://cdn.example.com/root/media/files/new.pdf');
        $this->storage->method('keyFromPublicUrl')->willReturnCallback(fn (string $u): ?string => str_contains($u, 'old.pdf') ? 'media/files/old.pdf' : 'media/files/new.pdf');
        $this->storage->expects($this->once())->method('delete')->with('media/files/old.pdf');

        $this->subscriber->onAssetPreSave(new AssetEvent($asset, false));
        $this->subscriber->onAssetPostSave(new AssetEvent($asset, false));
        // A second post-save has nothing left to delete.
        $this->subscriber->onAssetPostSave(new AssetEvent($asset, false));
    }

    public function testNoDeleteWhenPostSaveNeverFires(): void
    {
        $asset = $this->localAsset('new.pdf');
        $asset->setRemotePath('https://cdn.example.com/root/media/files/old.pdf');
        $this->storage->method('putFile')->willReturn('https://cdn.example.com/root/media/files/new.pdf');
        $this->storage->method('keyFromPublicUrl')->willReturnCallback(fn (string $u): ?string => str_contains($u, 'old.pdf') ? 'media/files/old.pdf' : 'media/files/new.pdf');
        $this->storage->expects($this->never())->method('delete');

        $this->subscriber->onAssetPreSave(new AssetEvent($asset, false));
    }

    public function testFormSubmissionObjectIsNeverDeletedOnPostDelete(): void
    {
        $url = 'https://cdn.example.com/root/media/files/form/1/2/x.pdf';
        $this->storage->method('keyFromPublicUrl')->willReturn('media/files/form/1/2/x.pdf');
        $this->storage->expects($this->never())->method('delete');
        $asset = new Asset();
        $asset->setStorageLocation('remote');
        $asset->setRemotePath($url);

        $this->subscriber->onAssetPostDelete(new AssetEvent($asset, false));
    }

    public function testFormSubmissionObjectIsNeverDeletedOnReplace(): void
    {
        $asset = $this->localAsset('new.pdf');
        $asset->setRemotePath('https://cdn.example.com/root/media/files/form/1/2/x.pdf');
        $this->storage->method('putFile')->willReturn('https://cdn.example.com/root/media/files/new.pdf');
        $this->storage->method('keyFromPublicUrl')->willReturnCallback(fn (string $u): ?string => str_contains($u, 'form/1/2') ? 'media/files/form/1/2/x.pdf' : 'media/files/new.pdf');
        $this->storage->expects($this->never())->method('delete');

        $this->subscriber->onAssetPreSave(new AssetEvent($asset, false));
        $this->subscriber->onAssetPostSave(new AssetEvent($asset, false));
    }

    public function testFormSubmissionObjectIsNotCanonicalizedAsOwned(): void
    {
        $this->storage->method('keyFromPublicUrl')->willReturn('media/files/form/1/2/x.pdf');
        $this->storage->expects($this->never())->method('getPublicUrl');
        $asset = new Asset();
        $asset->setStorageLocation('remote');
        $asset->setRemotePath('https://cdn.example.com/root/media/files/form/1/2/x.pdf?x=1');

        $this->subscriber->onAssetPreSave(new AssetEvent($asset, false));

        $this->assertSame('https://cdn.example.com/root/media/files/form/1/2/x.pdf?x=1', $asset->getRemotePath());
    }
}
