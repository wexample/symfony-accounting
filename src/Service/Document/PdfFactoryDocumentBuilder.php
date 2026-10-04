<?php

namespace Wexample\SymfonyAccounting\Service\Document;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Turns document data (InvoiceDocumentDataBuilder) into a pdf-factory semantic
 * document: what each part is, never what it looks like. The look belongs to the
 * theme the host posts beside it (its charter); the engine's default sheet
 * renders it readable without one.
 *
 * Not drawn yet (todo): the EPC payment QR code (needs a QR image as a data URI),
 * a "paid" variant with payment date and method, and the FR RIB grid.
 */
class PdfFactoryDocumentBuilder
{
    public function __construct(
        private readonly ?TranslatorInterface $translator = null,
    ) {
    }

    public function build(
        array $data,
        ?string $locale = null
    ): array {
        $label = fn (string $key) => $this->trans('document.label.'.$key, $locale);
        $content = [];

        $content[] = $this->heading(trim($data['title'].' '.($data['number'] ?? '')), 1);

        $content[] = $this->table([
            [
                $this->cell($this->identityBlocks($data['issuer'])),
                $this->cell($this->identityBlocks($data['client'])),
            ],
        ]);

        $dates = [$label('date').' : '.$data['dates']['invoice']];
        if ($data['dates']['due'] && 'quotation' !== $data['type']) {
            $dates[] = $label('due_date').' : '.$data['dates']['due'];
        }
        if ($data['dates']['validUntil']) {
            $dates[] = $label('valid_until').' : '.$data['dates']['validUntil'];
        }
        if ($data['dates']['periodStart'] && $data['dates']['periodEnd']) {
            $dates[] = $label('period').' : '.$data['dates']['periodStart'].' → '.$data['dates']['periodEnd'];
        }
        array_push($content, ...$this->paragraphLines($dates));

        if ($data['subject']) {
            $content[] = $this->heading($data['subject'], 2);
        }

        if ($data['note']) {
            array_push($content, ...$this->paragraphLines(preg_split('/\R/', $data['note'])));
        }

        $rows = [[
            $this->header($label('item')),
            $this->header($label('quantity'), 'right'),
            $this->header($label('unit_price'), 'right'),
            $this->header($label('vat'), 'right'),
            $this->header($label('total'), 'right'),
        ]];

        foreach ($data['items'] as $item) {
            $description = [$this->paragraph($item['title'])];
            if ($item['description']) {
                $description[] = $this->paragraph($item['description']);
            }

            $rows[] = [
                $this->cell($description),
                $this->textCell(trim($item['quantity'].' '.($item['unit'] ?? '')), 'right'),
                $this->textCell($item['unitPrice']['formatted'], 'right'),
                $this->textCell($item['vatRateFormatted'], 'right'),
                $this->textCell($item['total']['formatted'], 'right'),
            ];
        }

        foreach ($data['credits'] as $credit) {
            $rows[] = [
                $this->textCell($label('credit_note').' '.$credit['number']),
                $this->textCell(''),
                $this->textCell(''),
                $this->textCell(''),
                $this->textCell($credit['total']['formatted'], 'right'),
            ];
        }

        $content[] = $this->table($rows);
        $content[] = $this->table($this->totalRows($data['totals'], $label));

        $mentions = array_filter($data['mentions'], fn (array $m) => 'payment' !== $m['placement']);
        foreach ($mentions as $mention) {
            $content[] = $this->paragraph($mention['text']);
        }

        if ('quotation' !== $data['type'] && 'purchase' !== $data['direction']) {
            $content[] = $this->heading($label('payment'), 2);
            $payment = [$label('payment_reference').' : '.$data['payment']['reference']];

            if ($bank = $data['payment']['bank']) {
                $payment[] = $label('holder').' : '.($bank['holder'] ?? '');
                $payment[] = 'IBAN : '.$bank['iban'];
                if (! empty($bank['bic'])) {
                    $payment[] = 'BIC : '.$bank['bic'];
                }
            }

            array_push($content, ...$this->paragraphLines($payment));

            foreach (array_filter($data['mentions'], fn (array $m) => 'payment' === $m['placement']) as $mention) {
                $content[] = $this->paragraph($mention['text']);
            }
        }

        $meta = ['reference' => [$this->paragraph((string) ($data['number'] ?? ''))]];
        if ($data['legalMentions']) {
            $meta['legal'] = $this->paragraphLines(preg_split('/\R/', $data['legalMentions']));
        }

        return [
            'type' => 'doc',
            'title' => trim($data['title'].' '.($data['number'] ?? '')),
            'meta' => $meta,
            'content' => [[
                'type' => 'section',
                'attrs' => ['role' => 'body'],
                'content' => $content,
            ]],
        ];
    }

    /**
     * @return list<array>
     */
    private function identityBlocks(array $identity): array
    {
        $lines = array_filter([
            $identity['name'] ? trim($identity['name'].' '.($identity['legalForm'] ?? '')) : null,
            ...$identity['addressLines'],
            $identity['legalIdentifier'] ? $identity['legalIdentifierLabel'].' : '.$identity['legalIdentifier'] : null,
            $identity['vatNumber'] ? $identity['vatNumberLabel'].' : '.$identity['vatNumber'] : null,
            $identity['email'] ?? null,
        ]);

        return $this->paragraphLines($lines);
    }

    private function totalRows(
        array $totals,
        callable $label
    ): array {
        $rows = [[$this->textCell($label('net')), $this->textCell($totals['net']['formatted'], 'right')]];

        if ($totals['discount']) {
            array_unshift($rows, [$this->textCell($label('discount')), $this->textCell('-'.$totals['discount']['formatted'], 'right')]);
            array_unshift($rows, [$this->textCell($label('subtotal')), $this->textCell($totals['subTotal']['formatted'], 'right')]);
        }

        foreach ($totals['vatLines'] as $line) {
            if ($line['vat']['amount'] > 0) {
                $rows[] = [
                    $this->textCell($label('vat').' '.$line['rateFormatted'].' ('.$line['base']['formatted'].')'),
                    $this->textCell($line['vat']['formatted'], 'right'),
                ];
            }
        }

        if ($totals['overridden']) {
            $rows[] = [$this->textCell($label('total')), $this->cell([$this->paragraph($totals['total']['formatted'], [['type' => 'strike']])], 'right')];
            $rows[] = [$this->textCell($label('total')), $this->cell([$this->paragraph($totals['overridden']['formatted'], [['type' => 'bold']])], 'right')];
        } else {
            $rows[] = [$this->textCell($label('total')), $this->cell([$this->paragraph($totals['total']['formatted'], [['type' => 'bold']])], 'right')];
        }

        if ($totals['paid']) {
            $rows[] = [$this->textCell($label('paid')), $this->textCell('-'.$totals['paid']['formatted'], 'right')];
            $rows[] = [$this->textCell($label('amount_due')), $this->cell([$this->paragraph($totals['due']['formatted'], [['type' => 'bold']])], 'right')];
        }

        return $rows;
    }

    private function heading(
        string $text,
        int $level
    ): array {
        return ['type' => 'heading', 'attrs' => ['level' => $level], 'content' => [$this->text($text)]];
    }

    private function paragraph(
        string $text,
        array $marks = []
    ): array {
        return '' === $text
            ? ['type' => 'paragraph']
            : ['type' => 'paragraph', 'content' => [$this->text($text, $marks)]];
    }

    /**
     * One paragraph per line: the model has no line break inside a paragraph.
     *
     * @return list<array>
     */
    private function paragraphLines(array $lines): array
    {
        return array_map(fn ($line) => $this->paragraph((string) $line), array_values($lines));
    }

    private function text(
        string $text,
        array $marks = []
    ): array {
        return [] === $marks ? ['type' => 'text', 'text' => $text] : ['type' => 'text', 'text' => $text, 'marks' => $marks];
    }

    private function table(array $rows): array
    {
        return [
            'type' => 'table',
            'content' => array_map(fn (array $cells) => ['type' => 'tableRow', 'content' => $cells], $rows),
        ];
    }

    private function cell(
        array $blocks,
        ?string $align = null
    ): array {
        $cell = ['type' => 'tableCell', 'content' => $blocks];

        if ($align) {
            $cell['attrs'] = ['align' => $align];
        }

        return $cell;
    }

    private function textCell(
        string $text,
        ?string $align = null
    ): array {
        return $this->cell([$this->paragraph($text)], $align);
    }

    private function header(
        string $text,
        ?string $align = null
    ): array {
        $cell = ['type' => 'tableHeader', 'content' => [$this->paragraph($text)]];

        if ($align) {
            $cell['attrs'] = ['align' => $align];
        }

        return $cell;
    }

    private function trans(
        string $key,
        ?string $locale
    ): string {
        return $this->translator?->trans($key, [], 'accounting', $locale) ?? $key;
    }
}
