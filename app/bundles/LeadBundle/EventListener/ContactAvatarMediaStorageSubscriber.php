<?php

declare(strict_types=1);

namespace Mautic\LeadBundle\EventListener;

use Mautic\CoreBundle\Exception\MediaStorageException;
use Mautic\CoreBundle\Storage\MediaStorage;
use Mautic\LeadBundle\Event\LeadEvent;
use Mautic\LeadBundle\LeadEvents;
use Mautic\LeadBundle\Twig\Helper\AvatarHelper;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Removes the contact's custom avatar from S3 once the contact is deleted.
 */
final class ContactAvatarMediaStorageSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly MediaStorage $mediaStorage,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LeadEvents::LEAD_POST_DELETE => ['onLeadDelete', 0],
        ];
    }

    public function onLeadDelete(LeadEvent $event): void
    {
        if (!$this->mediaStorage->isEnabled()) {
            return;
        }

        $id = $event->getLead()->deletedId;
        if (!is_numeric($id) || (int) $id <= 0) {
            return;
        }

        try {
            $this->mediaStorage->delete(AvatarHelper::avatarKey((int) $id));
        } catch (MediaStorageException $exception) {
            // The contact row is already gone, so do not fail the request.
            $this->logger->error('Could not delete the S3 avatar of contact '.(int) $id.': '.$exception->getMessage());
        }
    }
}
