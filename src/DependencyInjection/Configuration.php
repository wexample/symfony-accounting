<?php

namespace Wexample\SymfonyAccounting\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('wexample_symfony_accounting');

        $treeBuilder->getRootNode()
            ->children()
                ->arrayNode('document')
                    ->info('How invoices are dressed when rendered by pdf-factory (wexample_symfony_remote_pdf_factory.url).')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('default_stylesheet')->defaultTrue()->info('Start from the sober charter shipped with the package.')->end()
                        ->arrayNode('stylesheets')
                            ->info('CSS files posted after it, in order: the application\'s charter.')
                            ->scalarPrototype()->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('access')
                    ->info('Who may view, edit or administer a ledger: a role, else a grant on the group the application ties the ledger to (LedgerGroupResolverInterface).')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('roles')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('view')->defaultValue('ROLE_ADMIN')->end()
                                ->scalarNode('edit')->defaultValue('ROLE_ADMIN')->end()
                                ->scalarNode('administer')->defaultValue('ROLE_ADMIN')->end()
                            ->end()
                        ->end()
                        ->arrayNode('grants')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('view')->defaultNull()->end()
                                ->scalarNode('edit')->defaultNull()->end()
                                ->scalarNode('administer')->defaultNull()->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
