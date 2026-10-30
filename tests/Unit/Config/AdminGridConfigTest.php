<?php

declare(strict_types=1);

namespace Tests\Webgriffe\SyliusBackInStockNotificationPlugin\Unit\Config;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Under the `product` target a subscription has no variant, so no grid column, filter or sort may go through
 * `productVariant.*` (a property path over null, or an inner join that drops the rows).
 */
final class AdminGridConfigTest extends TestCase
{
    public function testNoGridFieldFilterOrSortReadsThroughTheVariant(): void
    {
        /** @var array{sylius_grid: array{grids: array<string, array<string, mixed>>}} $config */
        $config = Yaml::parseFile(__DIR__ . '/../../../config/packages/sylius_grid.yaml');

        foreach ($config['sylius_grid']['grids'] as $name => $grid) {
            $encoded = (string) json_encode($grid);
            self::assertStringNotContainsString('productVariant.', $encoded, sprintf('Grid "%s" reads through the nullable variant.', $name));
        }
    }

    public function testVariantFieldTemplateRendersNothingWithoutAVariant(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../templates/admin/subscription/grid/field/variant.html.twig');
        $twig = new Environment(new ArrayLoader(['variant' => $source]));

        self::assertSame('', trim($twig->render('variant', ['data' => null])));
        self::assertSame('A0001', trim($twig->render('variant', ['data' => new class() {
            public string $code = 'A0001';
        }])));
    }
}
