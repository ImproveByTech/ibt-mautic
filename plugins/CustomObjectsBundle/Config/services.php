<?php

declare(strict_types=1);

use Mautic\CoreBundle\DependencyInjection\MauticCoreExtension;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return function (ContainerConfigurator $configurator): void {
    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure()
        ->public();

    $excludes = [
        'Provider/SessionProvider.php',
        'Report/ReportColumnsBuilder.php',
    ];

    $services->load('MauticPlugin\\CustomObjectsBundle\\', '../')
        ->exclude('../{'.implode(',', array_merge(MauticCoreExtension::DEFAULT_EXCLUDES, $excludes)).'}');

    $services->load('MauticPlugin\\CustomObjectsBundle\\Repository\\', '../Repository/*Repository.php');

    // Legacy service id removed from Mautic 7 core, still referenced in config.php.
    $services->alias('mautic.helper.export', Mautic\CoreBundle\Helper\ExportHelper::class);
};
