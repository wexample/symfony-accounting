<?php

namespace Wexample\SymfonyAccounting\Tests\Fixtures\App;

use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Wexample\SymfonyAccounting\Tests\Fixtures\App\DependencyInjection\PublicServicesPass;
use Wexample\SymfonyAccounting\WexampleSymfonyAccountingBundle;
use Wexample\SymfonyApi\WexampleSymfonyApiBundle;
use Wexample\SymfonyCheck\WexampleSymfonyCheckBundle;
use Wexample\SymfonyGeo\WexampleSymfonyGeoBundle;
use Wexample\SymfonyLoader\WexampleSymfonyLoaderBundle;
use Wexample\SymfonyMedia\WexampleSymfonyMediaBundle;
use Wexample\SymfonyMoney\WexampleSymfonyMoneyBundle;
use Wexample\SymfonyOrganization\WexampleSymfonyOrganizationBundle;
use Wexample\SymfonyPayment\WexampleSymfonyPaymentBundle;
use Wexample\SymfonyRemoteDocumentReader\WexampleSymfonyRemoteDocumentReaderBundle;
use Wexample\SymfonyRemotePayment\WexampleSymfonyRemotePaymentBundle;
use Wexample\SymfonyRemotePdfFactory\WexampleSymfonyRemotePdfFactoryBundle;
use Wexample\SymfonyRemotePeppol\WexampleSymfonyRemotePeppolBundle;
use Wexample\SymfonyRemoteVies\WexampleSymfonyRemoteViesBundle;
use Wexample\SymfonySecurity\WexampleSymfonySecurityBundle;
use Wexample\SymfonyTesting\Tests\Fixtures\AbstractFixtureKernel;
use Wexample\SymfonyTranslations\WexampleSymfonyTranslationsBundle;

class AppKernel extends AbstractFixtureKernel
{
    protected function getFixtureDir(): string
    {
        return __DIR__;
    }

    protected function getExtraBundles(): iterable
    {
        return [
            new WexampleSymfonyMoneyBundle(),
            new WexampleSymfonyGeoBundle(),
            new WexampleSymfonyOrganizationBundle(),
            new WexampleSymfonyCheckBundle(),
            new WexampleSymfonyRemotePaymentBundle(),
            new WexampleSymfonyMediaBundle(),
            new WexampleSymfonyPaymentBundle(),
            new WexampleSymfonyRemotePdfFactoryBundle(),
            new WexampleSymfonyRemoteDocumentReaderBundle(),
            new WexampleSymfonyRemoteViesBundle(),
            new WexampleSymfonyRemotePeppolBundle(),
            new WexampleSymfonyAccountingBundle(),
            new WexampleSymfonySecurityBundle(),
            new WexampleSymfonyTranslationsBundle(),
            new WexampleSymfonyLoaderBundle(),
            new WexampleSymfonyApiBundle(),
        ];
    }

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new PublicServicesPass(), PassConfig::TYPE_BEFORE_REMOVING);
    }

    protected function configuresItsOwnSecurity(): bool
    {
        return true;
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import(__DIR__.'/../../../src/Resources/config/routes.yaml');
    }

    protected function getConfigFiles(): array
    {
        return [
            __DIR__.'/config/config.yaml',
        ];
    }
}
