<?php

namespace Wexample\SymfonyAccounting\Tests\Fixtures\App;

use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Wexample\SymfonyAccounting\Tests\Fixtures\App\DependencyInjection\PublicServicesPass;
use Wexample\SymfonyAccounting\WexampleSymfonyAccountingBundle;
use Wexample\SymfonyCheck\WexampleSymfonyCheckBundle;
use Wexample\SymfonyPayment\WexampleSymfonyPaymentBundle;
use Wexample\SymfonyRemotePayment\WexampleSymfonyRemotePaymentBundle;
use Wexample\SymfonyTesting\Tests\Fixtures\AbstractFixtureKernel;

class AppKernel extends AbstractFixtureKernel
{
    protected function getFixtureDir(): string
    {
        return __DIR__;
    }

    protected function getExtraBundles(): iterable
    {
        return [
            new WexampleSymfonyCheckBundle(),
            new WexampleSymfonyRemotePaymentBundle(),
            new WexampleSymfonyPaymentBundle(),
            new WexampleSymfonyAccountingBundle(),
        ];
    }

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new PublicServicesPass(), PassConfig::TYPE_BEFORE_REMOVING);
    }

    protected function getConfigFiles(): array
    {
        return [
            __DIR__.'/config/config.yaml',
        ];
    }
}
