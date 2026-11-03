<?php

declare(strict_types=1);

namespace Mautic\CoreBundle\Tests\Unit\Form\Extension;

use Mautic\CoreBundle\Form\Extension\DefaultTranslationLanguageExtension;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\PageBundle\Entity\Page;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Forms;

final class DefaultTranslationLanguageExtensionTest extends TestCase
{
    private function buildForm(Page $page, string $locale): void
    {
        $parameters = $this->createMock(CoreParametersHelper::class);
        $parameters->method('get')->with('locale')->willReturn($locale);

        Forms::createFormFactoryBuilder()
            ->addTypeExtension(new DefaultTranslationLanguageExtension($parameters))
            ->getFormFactory()
            ->create(FormType::class, $page, ['data_class' => Page::class]);
    }

    public function testNewEntityGetsConfiguredDefaultLanguage(): void
    {
        $page = new Page();
        $this->buildForm($page, 'pt_BR');

        $this->assertSame('pt_BR', $page->getLanguage());
    }

    public function testExistingEntityIsLeftAlone(): void
    {
        $page = new Page();
        (new \ReflectionProperty(Page::class, 'id'))->setValue($page, 5);
        $this->buildForm($page, 'pt_BR');

        $this->assertSame('en', $page->getLanguage());
    }

    public function testExplicitLanguageIsLeftAlone(): void
    {
        $page = new Page();
        $page->setLanguage('es');
        $this->buildForm($page, 'pt_BR');

        $this->assertSame('es', $page->getLanguage());
    }

    public function testEnglishDefaultChangesNothing(): void
    {
        $page = new Page();
        $this->buildForm($page, 'en_US');

        $this->assertSame('en', $page->getLanguage());
    }
}
