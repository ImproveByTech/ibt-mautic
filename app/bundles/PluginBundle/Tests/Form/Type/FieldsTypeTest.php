<?php

declare(strict_types=1);

namespace Mautic\PluginBundle\Tests\Form\Type;

use Mautic\PluginBundle\Form\Type\CompanyFieldsType;
use Mautic\PluginBundle\Form\Type\FieldsType;
use Mautic\PluginBundle\Integration\AbstractIntegration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class FieldsTypeTest extends TestCase
{
    /**
     * @return iterable<string, array{AbstractType<array<mixed>>}>
     */
    public static function fieldTypesProvider(): iterable
    {
        yield 'contact fields' => [new FieldsType()];
        yield 'company fields' => [new CompanyFieldsType()];
    }

    /**
     * @param AbstractType<array<mixed>> $type
     */
    #[DataProvider('fieldTypesProvider')]
    public function testAllIntegrationFieldsAreShownOnASinglePage(AbstractType $type): void
    {
        $integration = $this->createMock(AbstractIntegration::class);
        $integration->method('getFormNotes')->willReturn(['', 'info']);

        $resolver = new OptionsResolver();
        $type->configureOptions($resolver);

        $options = $resolver->resolve([
            'integration_fields' => array_fill_keys(array_map(fn (int $i): string => 'Field'.$i, range(1, 235)), 'Label'),
            'mautic_fields'      => [],
            'integration'        => 'Salesforce',
            'integration_object' => $integration,
            'page'               => 3,
            'limit'              => 30,
        ]);

        $this->assertSame(235, $options['limit']);
        $this->assertSame(0, $options['start']);
        $this->assertEquals(1, $options['fixedPageNum']);
    }
}
