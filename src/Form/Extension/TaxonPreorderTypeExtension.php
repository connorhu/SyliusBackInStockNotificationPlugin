<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Form\Extension;

use Sylius\Bundle\AdminBundle\Form\Type\TaxonType;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\PreorderabilityAwareInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Preorderability\Preorderability;

/**
 * Adds the pre-orderability tri-state when the host's Taxon entity implements PreorderabilityAwareInterface.
 */
final class TaxonPreorderTypeExtension extends AbstractTypeExtension
{
    public static function getExtendedTypes(): iterable
    {
        return [TaxonType::class];
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $dataClass = $options['data_class'] ?? null;
        if (!is_string($dataClass) || !is_a($dataClass, PreorderabilityAwareInterface::class, true)) {
            return;
        }

        $builder->add('preorderability', EnumType::class, [
            'class' => Preorderability::class,
            'label' => 'webgriffe_bisn.admin.preorderability.label',
            'help' => 'webgriffe_bisn.admin.preorderability.help',
            'choice_label' => static fn (Preorderability $choice): string => 'webgriffe_bisn.admin.preorderability.' . $choice->value,
            'required' => true,
        ]);
    }
}
