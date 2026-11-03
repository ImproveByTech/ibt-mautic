<?php

declare(strict_types=1);

namespace MauticPlugin\MauticCrmBundle\Tests\Integration;

use Mautic\PluginBundle\Entity\Integration;
use Mautic\PluginBundle\Tests\Integration\AbstractIntegrationTestCase;
use MauticPlugin\MauticCrmBundle\Integration\SalesforceIntegration;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class SalesforceIntegrationPkceTest extends AbstractIntegrationTestCase
{
    private const SESSION_KEY = 'Salesforce_pkce_code_verifier';

    private Session $realSession;

    private RequestStack $requestStack;

    protected function setUp(): void
    {
        parent::setUp();

        $this->realSession = new Session(new MockArraySessionStorage());
        $request           = new Request();
        $request->setSession($this->realSession);
        $this->requestStack = new RequestStack();
        $this->requestStack->push($request);

        $this->dispatcher->method('dispatch')->willReturnArgument(0);
        $this->encryptionHelper->method('decrypt')->willReturnArgument(0);
        $this->router->method('generate')->willReturn('https://mautic.test/plugins/integrations/authcallback/Salesforce');
    }

    public function testAuthLoginUrlContainsS256ChallengeMatchingStoredVerifier(): void
    {
        $url = $this->createIntegration()->getAuthLoginUrl();

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $verifier = $this->realSession->get(self::SESSION_KEY);

        $this->assertIsString($verifier);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9\-_]{43,128}$/', $verifier);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame(
            rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            $query['code_challenge']
        );
        $this->assertSame('client-id', $query['client_id']);
        $this->assertSame('code', $query['response_type']);
    }

    public function testEachLoginAttemptGetsANewVerifier(): void
    {
        $integration = $this->createIntegration();

        $integration->getAuthLoginUrl();
        $first = $this->realSession->get(self::SESSION_KEY);
        $integration->getAuthLoginUrl();

        $this->assertNotSame($first, $this->realSession->get(self::SESSION_KEY));
    }

    public function testAuthCallbackSendsVerifierOnceAndClearsIt(): void
    {
        $this->realSession->set(self::SESSION_KEY, 'stored-verifier');
        $integration = $this->createIntegration(['makeRequest', 'extractAuthKeys']);

        $integration->expects($this->once())
            ->method('makeRequest')
            ->with(
                $this->anything(),
                $this->callback(fn (array $parameters): bool => 'stored-verifier' === ($parameters['code_verifier'] ?? null)),
                'POST',
                $this->anything()
            )
            ->willReturn(['access_token' => 'token']);
        $integration->method('extractAuthKeys')->willReturn(false);

        $this->assertFalse($integration->authCallback());
        $this->assertFalse($this->realSession->has(self::SESSION_KEY));
    }

    public function testRefreshTokenFlowDoesNotSendNorConsumeVerifier(): void
    {
        $this->realSession->set(self::SESSION_KEY, 'stored-verifier');
        $integration = $this->createIntegration(['makeRequest', 'extractAuthKeys']);

        $integration->expects($this->once())
            ->method('makeRequest')
            ->with(
                $this->anything(),
                $this->callback(fn (array $parameters): bool => !isset($parameters['code_verifier'])),
                'POST',
                $this->anything()
            )
            ->willReturn(['access_token' => 'token']);
        $integration->method('extractAuthKeys')->willReturn(false);

        $integration->authCallback(['use_refresh_token' => true]);

        $this->assertSame('stored-verifier', $this->realSession->get(self::SESSION_KEY));
    }

    /**
     * @param list<string> $mockedMethods
     *
     * @return SalesforceIntegration&MockObject
     */
    private function createIntegration(array $mockedMethods = []): SalesforceIntegration
    {
        $integration = $this->getMockBuilder(SalesforceIntegration::class)
            ->setConstructorArgs([
                $this->dispatcher,
                $this->cache,
                $this->em,
                $this->requestStack,
                $this->router,
                $this->translator,
                $this->logger,
                $this->encryptionHelper,
                $this->leadModel,
                $this->companyModel,
                $this->pathsHelper,
                $this->notificationModel,
                $this->fieldModel,
                $this->integrationEntityModel,
                $this->doNotContact,
                $this->fieldsWithUniqueIdentifier,
            ])
            ->onlyMethods($mockedMethods)
            ->getMock();

        $entity = new Integration();
        $entity->setName('Salesforce')
            ->setApiKeys(['client_id' => 'client-id', 'client_secret' => 'client-secret'])
            ->setFeatureSettings(['sandbox' => ['sandbox']]);
        $integration->setIntegrationSettings($entity);

        return $integration;
    }
}
