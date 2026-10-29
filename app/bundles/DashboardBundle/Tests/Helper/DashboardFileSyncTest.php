<?php

declare(strict_types=1);

namespace Mautic\DashboardBundle\Tests\Helper;

use Mautic\CoreBundle\Helper\PathsHelper;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\CoreBundle\Storage\DTO\MediaObject;
use Mautic\CoreBundle\Storage\MediaStorage;
use Mautic\DashboardBundle\Helper\DashboardFileSync;
use Mautic\UserBundle\Entity\User;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DashboardFileSyncTest extends TestCase
{
    private string $dir;
    private MediaStorage&MockObject $storage;
    private DashboardFileSync $sync;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/dash-sync-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->dir = realpath($this->dir);

        $this->storage = $this->createMock(MediaStorage::class);
        $this->storage->method('isEnabled')->willReturn(true);

        $paths = $this->createStub(PathsHelper::class);
        $paths->method('getSystemPath')->willReturn($this->dir);

        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);
        $userHelper = $this->createStub(UserHelper::class);
        $userHelper->method('getUser')->willReturn($user);

        $this->sync = new DashboardFileSync($this->storage, $paths, $userHelper, $this->createStub(LoggerInterface::class));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
    }

    public function testPushUsesUserScopedKey(): void
    {
        file_put_contents($this->dir.'/my.json', '{}');
        $this->storage->expects($this->once())->method('putFile')->with('media/dashboards/7/my.json', $this->dir.'/my.json');
        $this->sync->push($this->dir.'/my.json');
    }

    public function testPushRefusesBadPaths(): void
    {
        file_put_contents($this->dir.'/a.txt', '{}');
        $this->storage->expects($this->never())->method('putFile');
        $this->sync->push($this->dir.'/a.txt');
        $this->sync->push('/etc/passwd');
        $this->sync->push($this->dir.'/../x.json');
        $this->sync->push($this->dir.'/sub/x.json');
    }

    public function testPullDownloadsOnlyMissingFiles(): void
    {
        file_put_contents($this->dir.'/have.json', '{}');
        $mk = fn (string $k) => new MediaObject($k, 'u', 1, new \DateTimeImmutable());
        $this->storage->method('listObjects')->with('media/dashboards/7/')->willReturn([
            $mk('media/dashboards/7/have.json'),
            $mk('media/dashboards/7/new.json'),
            $mk('media/dashboards/7/evil.php'),
            $mk('media/dashboards/7/..'),
        ]);
        $this->storage->expects($this->once())->method('downloadTo')->with('media/dashboards/7/new.json', $this->dir.'/new.json');
        $this->sync->pull();
    }

    public function testRemoveDeletesKey(): void
    {
        $this->storage->expects($this->once())->method('delete')->with('media/dashboards/7/gone.json');
        $this->sync->remove($this->dir.'/gone.json');
    }

    public function testNoopWhenDisabled(): void
    {
        $storage = $this->createMock(MediaStorage::class);
        $storage->method('isEnabled')->willReturn(false);
        $storage->expects($this->never())->method('putFile');
        $storage->expects($this->never())->method('listObjects');
        $storage->expects($this->never())->method('delete');
        $sync = new DashboardFileSync($storage, $this->createStub(PathsHelper::class), $this->createStub(UserHelper::class), $this->createStub(LoggerInterface::class));
        $sync->pull();
        $sync->push('/x/a.json');
        $sync->remove('/x/a.json');
    }
}
