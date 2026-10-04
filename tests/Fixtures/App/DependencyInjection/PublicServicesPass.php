<?php

namespace Wexample\SymfonyAccounting\Tests\Fixtures\App\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tests fetch the package services from the container directly.
 */
class PublicServicesPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        foreach ($container->getDefinitions() as $id => $definition) {
            if (str_starts_with($id, 'Wexample\\Symfony')) {
                $definition->setPublic(true);
            }
        }
    }
}
