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
            ->end();

        return $treeBuilder;
    }
}
