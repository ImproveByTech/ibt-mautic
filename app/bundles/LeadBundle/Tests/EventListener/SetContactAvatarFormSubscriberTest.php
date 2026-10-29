<?php

declare(strict_types=1);

namespace Mautic\LeadBundle\Tests\EventListener;

use Doctrine\Common\Collections\ArrayCollection;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\PathsHelper;
use Mautic\CoreBundle\Storage\MediaStorage;
use Mautic\CoreBundle\Twig\Helper\AssetsHelper;
use Mautic\CoreBundle\Twig\Helper\GravatarHelper;
use Mautic\FormBundle\Entity\Field;
use Mautic\FormBundle\Entity\Form;
use Mautic\FormBundle\Event\SubmissionEvent;
use Mautic\FormBundle\Helper\FormUploader;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\EventListener\SetContactAvatarFormSubscriber;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\LeadBundle\Twig\Helper\AvatarHelper;
use Mautic\LeadBundle\Twig\Helper\DefaultAvatarHelper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Asset\Packages;
use Symfony\Component\HttpFoundation\RequestStack;

final class SetContactAvatarFormSubscriberTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private function createAvatarHelper(MediaStorage $storage): AvatarHelper
    {
        $assets  = new AssetsHelper($this->createStub(Packages::class));
        $default = new DefaultAvatarHelper($assets);

        return new AvatarHelper(
            $assets,
            $this->createStub(PathsHelper::class),
            new GravatarHelper($default, $this->createStub(CoreParametersHelper::class), $this->createStub(RequestStack::class)),
            $default,
            $storage,
        );
    }

    private function writePng(string $path): void
    {
        file_put_contents($path, base64_decode(self::PNG));
    }

    /**
     * @return array{SubmissionEvent, Field, Lead}
     */
    private function createEvent(): array
    {
        $field = $this->createStub(Field::class);
        $field->method('getType')->willReturn('file');
        $field->method('getAlias')->willReturn('pic');
        $field->method('getProperties')->willReturn(['profile_image' => true]);
        $form = $this->createStub(Form::class);
        $form->method('getFields')->willReturn(new ArrayCollection([$field]));
        $lead  = new Lead();
        $event = $this->createStub(SubmissionEvent::class);
        $event->method('getForm')->willReturn($form);
        $event->method('getLead')->willReturn($lead);
        $event->method('getResults')->willReturn(['pic' => 'a.png']);

        return [$event, $field, $lead];
    }

    public function testTempCopyFromS3IsDeletedAfterAvatarCreation(): void
    {
        [$event, $field, $lead] = $this->createEvent();
        $tmp                    = sys_get_temp_dir().'/mautic-form-test-'.uniqid().'-a.png';
        $this->writePng($tmp);

        $uploader = $this->createMock(FormUploader::class);
        $uploader->method('getLocalCopy')->willReturn($tmp);
        $uploader->method('getCompleteFilePath')->willReturn('/var/local/does-not-exist/a.png');

        $storage = $this->createMock(MediaStorage::class);
        $storage->method('isEnabled')->willReturn(true);
        $storage->expects($this->once())->method('putFile')->with($this->anything(), $tmp);
        $avatar = $this->createAvatarHelper($storage);

        $leadModel = $this->createMock(LeadModel::class);
        $leadModel->expects($this->once())->method('saveEntity');

        (new SetContactAvatarFormSubscriber($avatar, $uploader, $leadModel))->onFormSubmit($event);

        $this->assertFileDoesNotExist($tmp);
        $this->assertSame('custom', $lead->getPreferredProfileImage());
    }

    public function testTempCopyIsDeletedEvenWhenAvatarCreationFails(): void
    {
        [$event]                = $this->createEvent();
        $tmp                    = sys_get_temp_dir().'/mautic-form-test-'.uniqid().'-a.png';
        file_put_contents($tmp, 'x');

        $uploader = $this->createStub(FormUploader::class);
        $uploader->method('getLocalCopy')->willReturn($tmp);
        $uploader->method('getCompleteFilePath')->willReturn('/var/local/does-not-exist/a.png');

        // Not a real image: the helper rejects it, the temp copy must still go away.
        $storage = $this->createStub(MediaStorage::class);
        $avatar  = $this->createAvatarHelper($storage);

        (new SetContactAvatarFormSubscriber($avatar, $uploader, $this->createStub(LeadModel::class)))->onFormSubmit($event);

        $this->assertFileDoesNotExist($tmp);
    }

    public function testOriginalLocalUploadIsNeverDeleted(): void
    {
        [$event] = $this->createEvent();
        $local   = sys_get_temp_dir().'/mautic-form-test-'.uniqid().'-a.png';
        $this->writePng($local);

        $uploader = $this->createStub(FormUploader::class);
        $uploader->method('getLocalCopy')->willReturn($local);
        $uploader->method('getCompleteFilePath')->willReturn($local);

        $storage = $this->createStub(MediaStorage::class);
        $storage->method('isEnabled')->willReturn(true);

        (new SetContactAvatarFormSubscriber($this->createAvatarHelper($storage), $uploader, $this->createStub(LeadModel::class)))->onFormSubmit($event);

        $this->assertFileExists($local);
        @unlink($local);
    }
}
