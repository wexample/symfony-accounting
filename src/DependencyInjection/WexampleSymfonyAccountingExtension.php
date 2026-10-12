<?php

namespace Wexample\SymfonyAccounting\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Wexample\SymfonyAccounting\Interface\BankStatementParserInterface;
use Wexample\SymfonyAccounting\Interface\EntryImporterInterface;
use Wexample\SymfonyAccounting\Interface\JurisdictionInterface;
use Wexample\SymfonyAccounting\Interface\LedgerExporterInterface;
use Wexample\SymfonyAccounting\Interface\LedgerGroupResolverInterface;
use Wexample\SymfonyAccounting\Interface\TransactionMatcherInterface;
use Wexample\SymfonyAccounting\Interface\VatReturnFormInterface;
use Wexample\SymfonyHelpers\DependencyInjection\AbstractWexampleSymfonyExtension;

class WexampleSymfonyAccountingExtension extends AbstractWexampleSymfonyExtension
{
    public const string TAG_JURISDICTION = 'wexample_symfony_accounting.jurisdiction';

    public const string TAG_BANK_PARSER = 'wexample_symfony_accounting.bank_parser';

    public const string TAG_MATCHER = 'wexample_symfony_accounting.matcher';

    public const string TAG_VAT_RETURN_FORM = 'wexample_symfony_accounting.vat_return_form';

    public const string TAG_LEDGER_EXPORTER = 'wexample_symfony_accounting.ledger_exporter';

    public const string TAG_ENTRY_IMPORTER = 'wexample_symfony_accounting.entry_importer';

    public const string TAG_LEDGER_GROUP_RESOLVER = 'wexample_symfony_accounting.ledger_group_resolver';

    public function load(
        array $configs,
        ContainerBuilder $container
    ): void {
        // Implementing the interface is enough to be registered.
        $container
            ->registerForAutoconfiguration(JurisdictionInterface::class)
            ->addTag(self::TAG_JURISDICTION);

        $container
            ->registerForAutoconfiguration(BankStatementParserInterface::class)
            ->addTag(self::TAG_BANK_PARSER);

        $container
            ->registerForAutoconfiguration(TransactionMatcherInterface::class)
            ->addTag(self::TAG_MATCHER);

        $container
            ->registerForAutoconfiguration(VatReturnFormInterface::class)
            ->addTag(self::TAG_VAT_RETURN_FORM);

        $container
            ->registerForAutoconfiguration(LedgerExporterInterface::class)
            ->addTag(self::TAG_LEDGER_EXPORTER);

        $container
            ->registerForAutoconfiguration(EntryImporterInterface::class)
            ->addTag(self::TAG_ENTRY_IMPORTER);

        $container
            ->registerForAutoconfiguration(LedgerGroupResolverInterface::class)
            ->addTag(self::TAG_LEDGER_GROUP_RESOLVER);

        $this->loadConfig(
            __DIR__,
            $container
        );

        $config = $this->processConfiguration(new Configuration(), $configs);
        $container->setParameter('wexample_symfony_accounting.access.roles', $config['access']['roles']);
        $container->setParameter('wexample_symfony_accounting.access.grants', $config['access']['grants']);
        $container->setParameter('wexample_symfony_accounting.document.stylesheets', [
            ...($config['document']['default_stylesheet'] ? [dirname(__DIR__).'/Resources/pdf/invoice.css'] : []),
            ...$config['document']['stylesheets'],
        ]);
    }
}
