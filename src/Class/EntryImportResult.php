<?php

namespace Wexample\SymfonyAccounting\Class;

final class EntryImportResult
{
    public int $entries = 0;

    public int $lines = 0;

    public int $accountsCreated = 0;

    public int $partiesCreated = 0;

    public int $journalsCreated = 0;

    public int $fiscalYearsCreated = 0;

    /** @var array<string, string> Entry key ("VE/12") → why it was not imported. */
    public array $errors = [];

    public function isComplete(): bool
    {
        return [] === $this->errors;
    }
}
