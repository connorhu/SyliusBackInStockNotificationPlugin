<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Form\Extension;

use Sylius\Bundle\AdminBundle\Form\Type\ChannelType;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\PreorderChannelSettingsInterface;

/**
 * Adds the pre-order settings when the host's Channel entity implements PreorderChannelSettingsInterface.
 */
final class ChannelPreorderTypeExtension extends AbstractTypeExtension
{
    private const VALIDATION_GROUPS = ['sylius'];

    public static function getExtendedTypes(): iterable
    {
        return [ChannelType::class];
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $dataClass = $options['data_class'] ?? null;
        if (!is_string($dataClass) || !is_a($dataClass, PreorderChannelSettingsInterface::class, true)) {
            return;
        }

        $builder
            ->add('preorderEnabledByDefault', CheckboxType::class, [
                'label' => 'webgriffe_bisn.admin.channel.enabled_by_default',
                'help' => 'webgriffe_bisn.admin.channel.enabled_by_default_help',
                'required' => false,
            ])
            ->add('preorderMode', ChoiceType::class, [
                'label' => 'webgriffe_bisn.admin.channel.mode',
                'help' => 'webgriffe_bisn.admin.channel.mode_help',
                'choices' => [
                    'webgriffe_bisn.admin.channel.mode_queue' => PreorderChannelSettingsInterface::MODE_QUEUE,
                    'webgriffe_bisn.admin.channel.mode_fastest' => PreorderChannelSettingsInterface::MODE_FASTEST,
                ],
            ])
            ->add('preorderHoldHours', IntegerType::class, [
                'label' => 'webgriffe_bisn.admin.channel.hold_hours',
                'help' => 'webgriffe_bisn.admin.channel.hold_hours_help',
                'constraints' => [
                    new NotBlank(groups: self::VALIDATION_GROUPS),
                    new Range(min: 1, max: 720, groups: self::VALIDATION_GROUPS),
                ],
            ])
            ->add('preorderRateLimitSeconds', IntegerType::class, [
                'label' => 'webgriffe_bisn.admin.channel.rate_limit_seconds',
                'help' => 'webgriffe_bisn.admin.channel.rate_limit_seconds_help',
                'constraints' => [
                    new NotBlank(groups: self::VALIDATION_GROUPS),
                    new Range(min: 0, max: 3600, groups: self::VALIDATION_GROUPS),
                ],
            ])
        ;
    }
}
