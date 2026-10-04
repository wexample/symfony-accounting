<?php

namespace Wexample\SymfonyAccounting\Class;

final readonly class ExportFile
{
    public function __construct(
        public string $filename,
        public string $content,
        public string $mimeType = 'text/csv',
    ) {
    }
}
