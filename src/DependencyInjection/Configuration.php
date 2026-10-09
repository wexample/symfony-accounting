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
                ->arrayNode('pdf_factory')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('url')->defaultNull()->info('Base URL of a pdf-factory service, e.g. http://pdf_factory:8000.')->end()
                        ->scalarNode('stylesheet')->defaultNull()->info('The charter CSS posted with every document.')->end()
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
