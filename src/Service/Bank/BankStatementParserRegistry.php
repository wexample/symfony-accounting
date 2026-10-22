<?php

namespace Wexample\SymfonyAccounting\Service\Bank;

use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyAccounting\Interface\BankStatementParserInterface;

class BankStatementParserRegistry
{
    /**
     * @param iterable<BankStatementParserInterface> $parsers
     */
    public function __construct(
        private readonly iterable $parsers = [],
    ) {
    }

    public function get(string $key): BankStatementParserInterface
    {
        foreach ($this->parsers as $parser) {
            if ($parser->getKey() === $key) {
                return $parser;
            }
        }

        throw new AccountingException(sprintf('No bank file parser "%s".', $key));
    }

    public function detect(
        string $content,
        ?string $filename = null
    ): BankStatementParserInterface {
        foreach ($this->parsers as $parser) {
            if ($parser->supports($content, $filename)) {
                return $parser;
            }
        }

        throw new AccountingException('The format of this bank file is not recognized; choose a parser.');
    }

    /**
     * @return array<string, string> Key → label.
     */
    public function getChoices(): array
    {
        $choices = [];

        foreach ($this->parsers as $parser) {
            $choices[$parser->getKey()] = $parser->getLabel();
        }

        return $choices;
    }
}
