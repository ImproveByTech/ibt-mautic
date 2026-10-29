<?php

declare(strict_types=1);

namespace Mautic\CoreBundle\Storage\DTO;

final class MediaObject
{
    public function __construct(
        public readonly string $key,
        public readonly string $url,
        public readonly int $size,
        public readonly \DateTimeImmutable $lastModified,
    ) {
    }
}
