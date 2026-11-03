<?php

declare(strict_types=1);

namespace Mautic\CoreBundle\Tests\Unit\Service;

use Mautic\CoreBundle\Service\ElFinderAdapterFactory;
use Mautic\CoreBundle\Service\LocalFileAdapterService;
use Mautic\CoreBundle\Storage\MediaStorage;
use Mautic\CoreBundle\Storage\MediaStorageFlysystemAdapter;
use PHPUnit\Framework\TestCase;

class ElFinderAdapterFactoryTest extends TestCase
{
    public function testReturnsS3AdapterWhenEnabled(): void
    {
        $storage = $this->createMock(MediaStorage::class);
        $storage->method('isEnabled')->willReturn(true);

        $this->assertInstanceOf(MediaStorageFlysystemAdapter::class, ElFinderAdapterFactory::create($storage, sys_get_temp_dir()));
    }

    public function testReturnsLocalAdapterWhenDisabled(): void
    {
        $storage = $this->createMock(MediaStorage::class);
        $storage->method('isEnabled')->willReturn(false);

        $this->assertInstanceOf(LocalFileAdapterService::class, ElFinderAdapterFactory::create($storage, sys_get_temp_dir()));
    }
}
