<?php

namespace Wexample\SymfonyAccounting\Class;

/**
 * A sentence the law requires on a document. `key` is a translation key of the
 * `accounting` domain; the jurisdiction packages ship the texts.
 */
final readonly class LegalMention
{
    public const string PLACEMENT_HEADER = 'header';
    public const string PLACEMENT_VAT = 'vat';
    public const string PLACEMENT_PAYMENT = 'payment';
    public const string PLACEMENT_FOOTER = 'footer';

    /**
     * @param array<string, scalar|null> $parameters
     */
    public function __construct(
        public string $key,
        public string $placement = self::PLACEMENT_FOOTER,
        public array $parameters = [],
    ) {
    }
}
