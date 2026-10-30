<?php

declare(strict_types=1);

namespace Mautic\FormBundle\Tests\Controller;

use Doctrine\Persistence\ManagerRegistry;
use Mautic\CoreBundle\Factory\ModelFactory;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\CoreBundle\Service\FlashBag;
use Mautic\CoreBundle\Translation\Translator;
use Mautic\FormBundle\Controller\ResultController;
use Mautic\FormBundle\Entity\Field;
use Mautic\FormBundle\Entity\Form;
use Mautic\FormBundle\Entity\Submission;
use Mautic\FormBundle\Helper\FormFieldHelper;
use Mautic\FormBundle\Helper\FormUploader;
use Mautic\FormBundle\Model\SubmissionResultLoader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;

final class ResultControllerS3DownloadTest extends TestCase
{
    private function controller(Submission $submission): ResultController
    {
        $loader = $this->createMock(SubmissionResultLoader::class);
        $loader->method('getSubmissionWithResult')->willReturn($submission);
        $modelFactory = $this->createMock(ModelFactory::class);
        $modelFactory->method('getModel')->with('form.submission_result_loader')->willReturn($loader);

        $security = $this->createMock(CorePermissions::class);
        $security->method('isAdmin')->willReturn(true);
        $security->method('hasEntityAccess')->willReturn(true);

        return new ResultController(
            $this->createMock(FormFactoryInterface::class),
            $this->createMock(FormFieldHelper::class),
            $this->createMock(ManagerRegistry::class),
            $modelFactory,
            $this->createMock(UserHelper::class),
            $this->createMock(CoreParametersHelper::class),
            $this->createMock(EventDispatcherInterface::class),
            $this->createMock(Translator::class),
            $this->createMock(FlashBag::class),
            new RequestStack(),
            $security
        );
    }

    public function testDownloadRedirectsToS3WhenFileIsNotLocal(): void
    {
        $field = new Field();
        $field->setProperties(['public' => true]);
        $submission = $this->createMock(Submission::class);
        $submission->method('getResults')->willReturn(['doc' => 'cv.pdf']);
        $submission->method('getFieldByAlias')->with('doc')->willReturn($field);
        $submission->method('getForm')->willReturn(new Form());

        $uploader = $this->createMock(FormUploader::class);
        $uploader->method('getCompleteFilePath')->willReturn('/nonexistent/dir/cv.pdf');
        $uploader->method('getFileUrl')->willReturn('https://bucket.s3.eu-west-1.amazonaws.com/root/media/files/form/1/2/cv.pdf');

        $response = $this->controller($submission)->downloadFileAction(1, 'doc', $uploader);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('https://bucket.s3.eu-west-1.amazonaws.com/root/media/files/form/1/2/cv.pdf', $response->getTargetUrl());
    }
}
