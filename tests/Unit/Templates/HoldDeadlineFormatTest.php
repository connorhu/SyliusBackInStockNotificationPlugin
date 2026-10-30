<?php

declare(strict_types=1);

namespace Tests\Webgriffe\SyliusBackInStockNotificationPlugin\Unit\Templates;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;
use Twig\Environment;
use Twig\Extra\Intl\IntlExtension;
use Twig\Loader\ArrayLoader;
use Twig\TwigFunction;

/**
 * A hold deadline is printed in the configured display timezone everywhere (PHP's own timezone is often UTC), and a
 * mail prints it in the pre-order's locale.
 */
final class HoldDeadlineFormatTest extends TestCase
{
    private const TEMPLATES = __DIR__ . '/../../../templates';

    public function testEveryHoldDeadlineUsesTheDisplayTimezone(): void
    {
        $found = 0;
        foreach ((new Finder())->files()->in(self::TEMPLATES)->name('*.twig') as $file) {
            preg_match_all('/(?:holdUntil|deadline)\s*\|\s*(\w+)\(([^)]*\)?[^)]*)\)/', $file->getContents(), $matches, \PREG_SET_ORDER);
            foreach ($matches as [$expression, $filter, $arguments]) {
                ++$found;
                self::assertSame('format_datetime', $filter, sprintf('%s: %s', $file->getRelativePathname(), $expression));
                self::assertStringContainsString('timezone=webgriffe_bisn_timezone()', $arguments, sprintf('%s: %s', $file->getRelativePathname(), $expression));
                if (str_starts_with($file->getRelativePathname(), 'email/')) {
                    self::assertStringContainsString('locale=localeCode', $arguments, sprintf('%s: %s', $file->getRelativePathname(), $expression));
                }
            }
        }

        self::assertGreaterThan(0, $found);
    }

    public function testAdminGridPrintsTheHoldDeadlineInTheDisplayTimezone(): void
    {
        /** @var array{sylius_grid: array{grids: array<string, array{fields: array<string, array{type: string, options?: array{template?: string}}>}>}} $config */
        $config = Yaml::parseFile(__DIR__ . '/../../../config/packages/sylius_grid.yaml');
        $field = $config['sylius_grid']['grids']['webgriffe_admin_notification_subscription']['fields']['hold_until'];
        self::assertSame('twig', $field['type']);
        self::assertSame('@WebgriffeSyliusBackInStockNotificationPlugin/admin/subscription/grid/field/hold_until.html.twig', $field['options']['template'] ?? null);

        $twig = new Environment(new ArrayLoader(['field' => (string) file_get_contents(self::TEMPLATES . '/admin/subscription/grid/field/hold_until.html.twig')]));
        $twig->addExtension(new IntlExtension());
        $twig->addFunction(new TwigFunction('webgriffe_bisn_timezone', static fn (): string => 'Europe/Budapest'));

        self::assertSame('', trim($twig->render('field', ['data' => null])));
        self::assertMatchesRegularExpression('/\b1(3)?:30\b/', $twig->render('field', ['data' => new \DateTimeImmutable('2026-10-30 12:30:00', new \DateTimeZone('UTC'))]));
    }
}
