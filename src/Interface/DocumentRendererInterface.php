<?php

namespace Wexample\SymfonyAccounting\Interface;

/**
 * Renders a semantic document (pdf-factory's model) to PDF bytes.
 */
interface DocumentRendererInterface
{
    /**
     * @param array $document A `doc` node.
     * @param array|null $theme pdf-factory theme: stylesheet, blocks, variables.
     */
    public function render(
        array $document,
        ?array $theme = null
    ): string;
}
