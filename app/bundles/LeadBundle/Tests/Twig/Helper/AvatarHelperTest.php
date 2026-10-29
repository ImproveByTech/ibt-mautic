<?php

declare(strict_types=1);

namespace Mautic\LeadBundle\Tests\Twig\Helper;

use Mautic\CoreBundle\Exception\MediaStorageException;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\PathsHelper;
use Mautic\CoreBundle\Storage\MediaStorage;
use Mautic\CoreBundle\Twig\Helper\AssetsHelper;
use Mautic\CoreBundle\Twig\Helper\GravatarHelper;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Twig\Helper\AvatarHelper;
use Mautic\LeadBundle\Twig\Helper\DefaultAvatarHelper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Asset\Packages;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RequestStack;

final class AvatarHelperTest extends TestCase
{
    private string $tmpDir;

    /**
     * @var MockObject&MediaStorage
     */
    private MockObject $mediaStorage;

    private PathsHelper $pathsHelper;

    private AssetsHelper $assetsHelper;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/avatar-helper-test-'.uniqid();
        mkdir($this->tmpDir);

        $this->mediaStorage = $this->createMock(MediaStorage::class);
        $this->pathsHelper  = $this->createMock(PathsHelper::class);
        $this->pathsHelper->method('getSystemPath')->willReturnCallback(
            fn (string $name, bool $absolute = false) => $absolute ? $this->tmpDir : 'media/images'
        );
        $this->assetsHelper = new AssetsHelper($this->createStub(Packages::class));
        $this->assetsHelper->setPathsHelper($this->pathsHelper);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir.'/{,lead_avatars/}*', GLOB_BRACE) ?: [] as $file) {
            is_file($file) && unlink($file);
        }
        @rmdir($this->tmpDir.'/lead_avatars');
        @rmdir($this->tmpDir);
    }

    private static function PNG(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    }

    private function helper(): AvatarHelper
    {
        $default = new DefaultAvatarHelper($this->assetsHelper);

        return new AvatarHelper(
            $this->assetsHelper,
            $this->pathsHelper,
            new GravatarHelper($default, $this->createStub(CoreParametersHelper::class), $this->createStub(RequestStack::class)),
            $default,
            $this->mediaStorage,
        );
    }

    private function lead(int $id = 42, ?\DateTime $modified = null): Lead
    {
        $lead = $this->createMock(Lead::class);
        $lead->method('getId')->willReturn($id);
        $lead->method('getPreferredProfileImage')->willReturn('custom');
        $lead->method('getSocialCache')->willReturn([]);
        $lead->method('getEmail')->willReturn('');
        $lead->method('getDateModified')->willReturn($modified);

        return $lead;
    }

    public function testAvatarKey(): void
    {
        $this->assertSame('media/images/lead_avatars/avatar42', AvatarHelper::avatarKey(42));
        $this->assertSame('media/images/lead_avatars/avatar7', AvatarHelper::avatarKey($this->lead(7)));
    }

    public function testStoreUploadedAvatarPutsToS3(): void
    {
        $path = $this->tmpDir.'/upload.png';
        file_put_contents($path, self::PNG());
        $file = new UploadedFile($path, 'upload.png', null, null, true);

        $this->mediaStorage->method('isEnabled')->willReturn(true);
        $this->mediaStorage->expects($this->once())
            ->method('putFile')
            ->with('media/images/lead_avatars/avatar42', $file->getPathname())
            ->willReturn('https://x/y');

        $this->helper()->storeUploadedAvatar($this->lead(), $file);
        $this->assertDirectoryDoesNotExist($this->tmpDir.'/lead_avatars');
    }

    public function testStoreUploadedAvatarPropagatesS3Failure(): void
    {
        $path = $this->tmpDir.'/upload.png';
        file_put_contents($path, self::PNG());
        $file = new UploadedFile($path, 'upload.png', null, null, true);

        $this->mediaStorage->method('isEnabled')->willReturn(true);
        $this->mediaStorage->method('putFile')->willThrowException(new MediaStorageException('boom'));

        $this->expectException(MediaStorageException::class);
        $this->helper()->storeUploadedAvatar($this->lead(), $file);
    }

    public function testCreateAvatarFromFilePutsToS3AndKeepsExtensionCheck(): void
    {
        $png = $this->tmpDir.'/in.png';
        $txt = $this->tmpDir.'/in.txt';
        file_put_contents($png, self::PNG());
        file_put_contents($txt, 'text');

        $this->mediaStorage->method('isEnabled')->willReturn(true);
        $this->mediaStorage->expects($this->once())
            ->method('putFile')
            ->with('media/images/lead_avatars/avatar42', $png)
            ->willReturn('https://x/y');

        $helper = $this->helper();
        $helper->createAvatarFromFile($this->lead(), $png);
        $this->assertDirectoryDoesNotExist($this->tmpDir.'/lead_avatars');

        $this->expectExceptionMessage('File is not image');
        $helper->createAvatarFromFile($this->lead(), $txt);
    }

    public function testCustomAvatarUrlPointsToS3(): void
    {
        $this->mediaStorage->method('isEnabled')->willReturn(true);
        $this->mediaStorage->expects($this->never())->method('exists');
        $this->mediaStorage->expects($this->once())
            ->method('getPublicUrl')
            ->with('media/images/lead_avatars/avatar42')
            ->willReturn('https://b.s3.r.amazonaws.com/root/media/images/lead_avatars/avatar42');

        $modified = new \DateTime('@1700000000');
        $this->assertSame(
            'https://b.s3.r.amazonaws.com/root/media/images/lead_avatars/avatar42?1700000000',
            $this->helper()->getAvatar($this->lead(42, $modified))
        );
    }

    public function testCustomAvatarUrlWithoutDateModifiedOmitsTimestamp(): void
    {
        $this->mediaStorage->method('isEnabled')->willReturn(true);
        $this->mediaStorage->method('getPublicUrl')->willReturn('https://b/avatar42');

        $this->assertSame('https://b/avatar42', $this->helper()->getAvatar($this->lead(42, null)));
    }

    public function testDisabledKeepsLocalBehavior(): void
    {
        $this->mediaStorage->method('isEnabled')->willReturn(false);
        $this->mediaStorage->expects($this->never())->method('putFile');
        $this->mediaStorage->expects($this->never())->method('getPublicUrl');

        $png = $this->tmpDir.'/in.png';
        file_put_contents($png, self::PNG());
        $helper = $this->helper();
        $helper->createAvatarFromFile($this->lead(), $png);
        $this->assertFileExists($this->tmpDir.'/lead_avatars/avatar42');

        $upload = $this->tmpDir.'/up.png';
        file_put_contents($upload, self::PNG());
        $helper->storeUploadedAvatar($this->lead(43), new UploadedFile($upload, 'up.png', null, null, true));
        $this->assertSame(self::PNG(), file_get_contents($this->tmpDir.'/lead_avatars/avatar43'));
    }

    public function testHtmlContentWithImageExtensionIsRejected(): void
    {
        $this->mediaStorage->method('isEnabled')->willReturn(true);
        $this->mediaStorage->expects($this->never())->method('putFile');

        $evil = $this->tmpDir.'/evil.png';
        file_put_contents($evil, '<html><script>alert(1)</script></html>');
        $helper = $this->helper();

        try {
            $helper->createAvatarFromFile($this->lead(), $evil);
            $this->fail('Expected exception');
        } catch (\Exception $e) {
            $this->assertSame('File is not image', $e->getMessage());
        }

        $this->expectExceptionMessage('File is not image');
        $helper->storeUploadedAvatar($this->lead(), new UploadedFile($evil, 'evil.png', null, null, true));
    }

    public function testHtmlContentIsRejectedWhenDisabledAndNothingCopied(): void
    {
        $this->mediaStorage->method('isEnabled')->willReturn(false);
        $evil = $this->tmpDir.'/evil.png';
        file_put_contents($evil, '<svg onload="alert(1)"></svg>');

        try {
            $this->helper()->createAvatarFromFile($this->lead(), $evil);
            $this->fail('Expected exception');
        } catch (\Exception) {
        }
        $this->assertFileDoesNotExist($this->tmpDir.'/lead_avatars/avatar42');
    }

    public function testExistingLocalAvatarStillServedLocallyWhenS3Enabled(): void
    {
        mkdir($this->tmpDir.'/lead_avatars');
        file_put_contents($this->tmpDir.'/lead_avatars/avatar42', self::PNG());

        $this->mediaStorage->method('isEnabled')->willReturn(true);
        $this->mediaStorage->expects($this->never())->method('getPublicUrl');

        // The Packages stub yields an empty URL; what matters is that S3 was not consulted.
        $this->assertSame('', $this->helper()->getAvatar($this->lead(42, new \DateTime('@1700000000'))));
    }
}
