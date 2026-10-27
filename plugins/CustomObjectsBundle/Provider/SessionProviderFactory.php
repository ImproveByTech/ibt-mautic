<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Provider;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;

class SessionProviderFactory
{
    public function __construct(private RequestStack $requestStack, private CoreParametersHelper $coreParametersHelper)
    {
    }

    public function createObjectProvider(): SessionProvider
    {
        return $this->createProvider('custom-object');
    }

    public function createItemProvider(int $objectId, string $filterEntityType = null, int $filterEntityId = null, bool $lookup = false): SessionProvider
    {
        $namespace = implode('-', ['custom-item', $objectId, $filterEntityType, $filterEntityId, (int) $lookup]);

        return $this->createProvider($namespace);
    }

    private function createProvider(string $namespace): SessionProvider
    {
        /** @var Session $session */
        $session = $this->requestStack->getSession();

        return new SessionProvider($session, $namespace, (int) $this->coreParametersHelper->get('default_pagelimit'));
    }
}
