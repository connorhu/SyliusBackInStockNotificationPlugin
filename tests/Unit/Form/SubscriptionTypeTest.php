<?php

declare(strict_types=1);

namespace Tests\Webgriffe\SyliusBackInStockNotificationPlugin\Unit\Form;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Validation;
use Webgriffe\SyliusBackInStockNotificationPlugin\Form\SubscriptionType;

final class SubscriptionTypeTest extends TestCase
{
    private const GROUP = 'webgriffe_sylius_back_in_stock_notification_plugin';

    public function test_identity_fields_are_required_in_the_plugin_group(): void
    {
        // Building the form instantiates the constraints: on Symfony 8 an options array throws here.
        $form = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->getFormFactory()
            ->create(SubscriptionType::class);

        $name = $form->get('name')->getConfig()->getOption('constraints');
        $email = $form->get('email')->getConfig()->getOption('constraints');

        self::assertInstanceOf(NotBlank::class, $name[0]);
        self::assertSame([self::GROUP], $name[0]->groups);
        self::assertInstanceOf(NotBlank::class, $email[0]);
        self::assertSame([self::GROUP], $email[0]->groups);
        self::assertInstanceOf(Email::class, $email[1]);
        self::assertSame([self::GROUP], $email[1]->groups);
    }

    public function test_identity_fields_are_left_out_when_not_asked(): void
    {
        $form = Forms::createFormFactoryBuilder()->getFormFactory()->create(SubscriptionType::class, null, ['ask_identity' => false]);

        self::assertFalse($form->has('name'));
        self::assertFalse($form->has('email'));
    }
}
