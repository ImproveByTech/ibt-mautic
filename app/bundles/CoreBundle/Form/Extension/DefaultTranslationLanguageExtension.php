<?php

declare(strict_types=1);

namespace Mautic\CoreBundle\Form\Extension;

use Mautic\CoreBundle\Entity\FormEntity;
use Mautic\CoreBundle\Entity\TranslationEntityInterface;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;

/**
 * New translatable entities (landing pages, emails, dynamic content, SMS,
 * notifications) are hardcoded to 'en' in TranslationEntityTrait. Pre-fill
 * the form with the instance's default language (Configuration > System
 * Settings > Default language) instead. Only touches new entities still
 * on that hardcoded 'en', so existing items and explicit choices are kept.
 */
class DefaultTranslationLanguageExtension extends AbstractTypeExtension
{
    private const HARDCODED_DEFAULT = 'en';

    public function __construct(
        private CoreParametersHelper $coreParametersHelper,
    ) {
    }

    /**
     * @param array<mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            $entity = $event->getData();

            if (!$entity instanceof TranslationEntityInterface || !$entity instanceof FormEntity) {
                return;
            }

            if (!$entity->isNew() || self::HARDCODED_DEFAULT !== $entity->getLanguage()) {
                return;
            }

            $locale = (string) $this->coreParametersHelper->get('locale');

            // English instances keep upstream's 'en' as-is.
            if ('' === $locale || str_starts_with($locale, self::HARDCODED_DEFAULT) || !method_exists($entity, 'setLanguage')) {
                return;
            }

            $entity->setLanguage($locale);
        });
    }

    /**
     * @return iterable<class-string>
     */
    public static function getExtendedTypes(): iterable
    {
        return [FormType::class];
    }
}
