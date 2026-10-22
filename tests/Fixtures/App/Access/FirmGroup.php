<?php

namespace Wexample\SymfonyAccounting\Tests\Fixtures\App\Access;

/**
 * A group of the application — a firm, a client's team — and the grants
 * each of its members holds there.
 */
final class FirmGroup
{
    /**
     * @param array<string, list<string>> $grants user identifier → grants
     */
    public function __construct(
        public readonly string $key,
        public readonly array $grants,
    ) {
    }
}
