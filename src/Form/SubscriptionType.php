<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Webgriffe\SyliusBackInStockNotificationPlugin\Validator\SubscriptionUnique;

/**
 * @TODO: Transform this in an entity type?
 */
final class SubscriptionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if (true === $options['ask_identity']) {
            $builder
                ->add('name', TextType::class, [
                    'constraints' => [
                        new NotBlank([], null, null, null, ['webgriffe_sylius_back_in_stock_notification_plugin']),
                        new Length(max: 255, groups: ['webgriffe_sylius_back_in_stock_notification_plugin']),
                    ],
                ])
                ->add('email', EmailType::class, [
                    'constraints' => [
                        new NotBlank([], null, null, null, ['webgriffe_sylius_back_in_stock_notification_plugin']),
                        new Email([], null, null, null, ['webgriffe_sylius_back_in_stock_notification_plugin']),
                    ],
                ])
            ;
        }

        $builder
            ->add('product_variant_code', HiddenType::class)
            // Honeypot: real visitors never see it, bots fill it in.
            ->add('website', TextType::class, [
                'mapped' => false,
                'required' => false,
                'attr' => ['autocomplete' => 'off', 'tabindex' => '-1'],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'ask_identity' => true,
            'validation_groups' => ['webgriffe_sylius_back_in_stock_notification_plugin'],
            'constraints' => [new SubscriptionUnique(
                null,
                ['webgriffe_sylius_back_in_stock_notification_plugin'],
            )],
        ]);
        $resolver->setAllowedTypes('ask_identity', 'bool');
    }
}
