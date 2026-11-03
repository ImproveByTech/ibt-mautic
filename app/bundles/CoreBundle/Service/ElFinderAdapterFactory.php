<?php

declare(strict_types=1);

namespace Mautic\CoreBundle\Service;

use League\Flysystem\FilesystemAdapter;
use Mautic\CoreBundle\Storage\MediaStorage;
use Mautic\CoreBundle\Storage\MediaStorageFlysystemAdapter;

class ElFinderAdapterFactory
{
    public static function create(MediaStorage $storage, string $elFinderPath): FilesystemAdapter
    {
        if ($storage->isEnabled()) {
            return new MediaStorageFlysystemAdapter($storage);
        }

        return new LocalFileAdapterService($elFinderPath);
    }
}
