<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    /** @psalm-suppress UnusedVariable, UnusedMethodCall */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('webgriffe_sylius_back_in_stock_notification_plugin');
        $rootNode = $treeBuilder->getRootNode();
        $rootNode
            ->children()
                ->enumNode('target')
                    ->values(['product', 'variant'])
                    ->defaultValue('variant')
                    ->info('What a subscription waits for: any variant of a product, or one specific variant.')
                ->end()
                ->scalarNode('display_timezone')
                    ->defaultNull()
                    ->info('Time zone (e.g. Europe/Budapest) used to print hold deadlines in the shop; null uses the PHP default time zone.')
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
