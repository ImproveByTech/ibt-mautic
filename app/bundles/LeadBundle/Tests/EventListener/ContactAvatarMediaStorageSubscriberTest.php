<?php

declare(strict_types=1);

namespace Mautic\LeadBundle\Tests\EventListener;

use Mautic\CoreBundle\Exception\MediaStorageException;
use Mautic\CoreBundle\Storage\MediaStorage;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Event\LeadEvent;
use Mautic\LeadBundle\EventListener\ContactAvatarMediaStorageSubscriber;
use Mautic\LeadBundle\LeadEvents;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ContactAvatarMediaStorageSubscriberTest extends TestCase
{
    /**
     * @var MockObject&MediaStorage
     */
    private MockObject $mediaStorage;

    /**
     * @var MockObject&LoggerInterface
     */
    private MockObject $logger;

    protected function setUp(): void
    {
        $this->mediaStorage = $this->createMock(MediaStorage::class);
        $this->logger       = $this->createMock(LoggerInterface::class);
    }

    private function event(?int $deletedId = 42): LeadEvent
    {
        $lead            = new Lead();
        $lead->deletedId = $deletedId;

        return new LeadEvent($lead);
    }

    public function testSubscribesToPostDelete(): void
    {
        $this->assertArrayHasKey(LeadEvents::LEAD_POST_DELETE, ContactAvatarMediaStorageSubscriber::getSubscribedEvents());
    }

    public function testDeletingContactDeletesAvatarObject(): void
    {
        $this->mediaStorage->method('isEnabled')->willReturn(true);
        $this->mediaStorage->expects($this->once())->method('delete')->with('media/images/lead_avatars/avatar42');

        (new ContactAvatarMediaStorageSubscriber($this->mediaStorage, $this->logger))->onLeadDelete($this->event());
    }

    public function testDoesNothingWhenDisabled(): void
    {
        $this->mediaStorage->method('isEnabled')->willReturn(false);
        $this->mediaStorage->expects($this->never())->method('delete');

        (new ContactAvatarMediaStorageSubscriber($this->mediaStorage, $this->logger))->onLeadDelete($this->event());
    }

    public function testDoesNothingWithoutDeletedId(): void
    {
        $this->mediaStorage->method('isEnabled')->willReturn(true);
        $this->mediaStorage->expects($this->never())->method('delete');

        (new ContactAvatarMediaStorageSubscriber($this->mediaStorage, $this->logger))->onLeadDelete($this->event(null));
    }

    public function testS3FailureIsLoggedNotThrown(): void
    {
        $this->mediaStorage->method('isEnabled')->willReturn(true);
        $this->mediaStorage->method('delete')->willThrowException(new MediaStorageException('boom'));
        $this->logger->expects($this->once())->method('error');

        (new ContactAvatarMediaStorageSubscriber($this->mediaStorage, $this->logger))->onLeadDelete($this->event());
    }
}
