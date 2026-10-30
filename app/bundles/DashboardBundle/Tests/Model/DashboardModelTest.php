<?php

declare(strict_types=1);

namespace Mautic\DashboardBundle\Tests\Model;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CacheBundle\Cache\CacheProviderTagAwareInterface;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\Filesystem;
use Mautic\CoreBundle\Helper\PathsHelper;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\CoreBundle\Translation\Translator;
use Mautic\DashboardBundle\Entity\Widget;
use Mautic\DashboardBundle\Event\WidgetDetailEvent;
use Mautic\DashboardBundle\Factory\WidgetDetailEventFactory;
use Mautic\DashboardBundle\Model\DashboardModel;
use Mautic\CoreBundle\Exception\MediaStorageException;
use Mautic\CoreBundle\Storage\MediaStorage;
use Mautic\DashboardBundle\Helper\DashboardFileSync;
use Mautic\UserBundle\Entity\User;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class DashboardModelTest extends TestCase
{
    private MockObject&CoreParametersHelper $coreParametersHelper;

    private MockObject&Session $session;

    private DashboardModel $model;

    protected function setUp(): void
    {
        $this->coreParametersHelper = $this->createMock(CoreParametersHelper::class);
        $this->session              = $this->createMock(Session::class);
        $requestStack               = $this->createMock(RequestStack::class);
        $requestStack->method('getSession')
            ->willReturn($this->session);

        $this->model = new DashboardModel(
            $this->coreParametersHelper,
            $this->createStub(PathsHelper::class),
            $this->createStub(WidgetDetailEventFactory::class),
            $this->createStub(Filesystem::class),
            $requestStack,
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(CorePermissions::class),
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(UrlGeneratorInterface::class),
            $this->createStub(Translator::class),
            $this->createStub(UserHelper::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(CacheProviderTagAwareInterface::class),
        );
    }

    public function testGetDefaultFilterFromSession(): void
    {
        $dateFromStr = '-1 month';
        $dateFrom    = new \DateTime($dateFromStr);
        $dateTo      = new \DateTime('23:59:59'); // till end of the 'to' date selected

        $this->coreParametersHelper->expects($this->once())
            ->method('get')
            ->with('default_daterange_filter', $dateFromStr)
            ->willReturn($dateFromStr);

        $this->session->expects($this->exactly(2))
            ->method('get')
            ->willReturnOnConsecutiveCalls(
                $dateFrom->format(\DateTimeInterface::ATOM),
                $dateTo->format(\DateTimeInterface::ATOM)
            );

        $filter = $this->model->getDefaultFilter();

        Assert::assertSame(
            $dateFrom->format(\DateTimeInterface::ATOM),
            $filter['dateFrom']->format(\DateTimeInterface::ATOM)
        );

        Assert::assertSame(
            $dateTo->format(\DateTimeInterface::ATOM),
            $filter['dateTo']->format(\DateTimeInterface::ATOM)
        );
    }

    public function testPopulateWidgetContentCatchesExceptionAndSetsGenericErrorMessage(): void
    {
        $widget    = new Widget();
        $exception = new \RuntimeException('DB connection failed — secret host: db.internal');
        $event     = $this->createStub(WidgetDetailEvent::class);

        $widgetEventFactory = $this->createMock(WidgetDetailEventFactory::class);
        $widgetEventFactory->method('create')->willReturn($event);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->willThrowException($exception);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                self::stringContains('failed to load'),
                self::callback(fn (array $ctx): bool => $exception === $ctx['exception'])
            );

        $this->coreParametersHelper->method('get')->willReturn(null);

        $requestStack = $this->createMock(RequestStack::class);
        $requestStack->method('getSession')->willReturn($this->session);

        $model = new DashboardModel(
            $this->coreParametersHelper,
            $this->createStub(PathsHelper::class),
            $widgetEventFactory,
            $this->createStub(Filesystem::class),
            $requestStack,
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(CorePermissions::class),
            $dispatcher,
            $this->createStub(UrlGeneratorInterface::class),
            $this->createStub(Translator::class),
            $this->createStub(UserHelper::class),
            $logger,
            $this->createStub(CacheProviderTagAwareInterface::class),
        );

        // Pass timezone to skip userHelper->getUser()->getTimezone()
        $model->populateWidgetContent($widget, ['timezone' => 'UTC']);

        Assert::assertSame('mautic.dashboard.widget.load.failed', $widget->getErrorMessage());
    }

    public function testSaveSnapshotRemovesLocalCopyWhenS3PushFails(): void
    {
        $dir = (string) realpath(sys_get_temp_dir()).'/dash-model-'.bin2hex(random_bytes(4));
        mkdir($dir);

        try {
            $storage = $this->createMock(MediaStorage::class);
            $storage->method('isEnabled')->willReturn(true);
            $storage->method('putFile')->willThrowException(new MediaStorageException('boom'));
            $paths = $this->createStub(PathsHelper::class);
            $paths->method('getSystemPath')->willReturn($dir);
            $user = $this->createStub(User::class);
            $user->method('getId')->willReturn(7);
            $userHelper = $this->createStub(UserHelper::class);
            $userHelper->method('getUser')->willReturn($user);
            $sync = new DashboardFileSync($storage, $paths, $userHelper, $this->createStub(LoggerInterface::class));

            $model = $this->getMockBuilder(DashboardModel::class)
                ->setConstructorArgs([
                    $this->coreParametersHelper,
                    $paths,
                    $this->createStub(WidgetDetailEventFactory::class),
                    new Filesystem(),
                    $this->createStub(RequestStack::class),
                    $this->createStub(EntityManagerInterface::class),
                    $this->createStub(CorePermissions::class),
                    $this->createStub(EventDispatcherInterface::class),
                    $this->createStub(UrlGeneratorInterface::class),
                    $this->createStub(Translator::class),
                    $userHelper,
                    $this->createStub(LoggerInterface::class),
                    $this->createStub(CacheProviderTagAwareInterface::class),
                    $sync,
                ])
                ->onlyMethods(['toArray'])
                ->getMock();
            $model->method('toArray')->willReturn(['name' => 'x']);

            try {
                $model->saveSnapshot('x');
                $this->fail('Expected IOException');
            } catch (\Symfony\Component\Filesystem\Exception\IOException) {
            }

            $this->assertFileDoesNotExist($dir.'/x.json');
        } finally {
            array_map('unlink', glob($dir.'/*') ?: []);
            rmdir($dir);
        }
    }
}
