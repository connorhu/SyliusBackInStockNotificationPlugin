<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\DependencyInjection;

use Sylius\Bundle\CoreBundle\DependencyInjection\PrependDoctrineMigrationsTrait;
use Sylius\Bundle\ResourceBundle\DependencyInjection\Extension\AbstractResourceExtension;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class WebgriffeSyliusBackInStockNotificationExtension extends AbstractResourceExtension implements PrependExtensionInterface
{
    use PrependDoctrineMigrationsTrait;

    /** @psalm-suppress UnusedVariable */
    public function load(array $configs, ContainerBuilder $container): void
    {
        /** @var array{target: string, display_timezone: string|null} $config */
        $config = $this->processConfiguration($this->getConfiguration([], $container), $configs);
        $container->setParameter('webgriffe_sylius_back_in_stock_notification_plugin.target', $config['target']);
        $container->setParameter('webgriffe_sylius_back_in_stock_notification_plugin.display_timezone', $config['display_timezone']);
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../../config'));

        $loader->load('services.yaml');
    }

    public function getConfiguration(array $config, ContainerBuilder $container): ConfigurationInterface
    {
        return new Configuration();
    }

    public function prepend(ContainerBuilder $container): void
    {
        $this->prependDoctrineMigrations($container);

        $container->prependExtensionConfig('framework', [
            'rate_limiter' => [
                'webgriffe_bisn_manage_link_email' => [
                    'policy' => 'fixed_window',
                    'limit' => 1,
                    'interval' => '60 seconds',
                ],
                'webgriffe_bisn_manage_link_ip' => [
                    'policy' => 'sliding_window',
                    'limit' => 10,
                    'interval' => '1 hour',
                ],
                'webgriffe_bisn_preorder_ip' => [
                    'policy' => 'sliding_window',
                    'limit' => 10,
                    'interval' => '1 hour',
                ],
            ],
        ]);
    }

    protected function getMigrationsNamespace(): string
    {
        return 'Webgriffe\SyliusBackInStockNotificationPlugin\Migrations';
    }

    protected function getMigrationsDirectory(): string
    {
        return '@WebgriffeSyliusBackInStockNotificationPlugin/migrations';
    }

    protected function getNamespacesOfMigrationsExecutedBefore(): array
    {
        return [
            'Sylius\Bundle\CoreBundle\Migrations',
        ];
    }
}
