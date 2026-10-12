<?php

namespace Wexample\SymfonyAccounting\Service\Document;

use Symfony\Contracts\Translation\TranslatorInterface;
use Wexample\SymfonyAccounting\Class\LegalMention;
use Wexample\SymfonyAccounting\Enum\InvoiceType;
use Wexample\SymfonyRemotePdfFactory\Helper\DocumentNodeHelper as Node;

/**
 * Turns document data (InvoiceDocumentDataBuilder) into a pdf-factory semantic
 * document: what each part is, never what it looks like. Every part is a section
 * named by its role, which is all a charter selects:
 *
 * - parties (issuer, recipient), registration, dates;
 * - lines, totals, settlement (a paid document), notes;
 * - payment (where and how to pay: EPC code, holder, national account form, IBAN), terms.
 *
 * `meta` carries the reference, an imprint (the issuer in one line) and the
 * ledger's legal mentions, for a charter to run in the page margins.
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
        $title = trim($data['title'].' '.($data['number'] ?? ''));
        $mentions = fn (array $placements) => array_column(
            array_filter($data['mentions'], fn (array $mention) => in_array($mention['placement'], $placements, true)),
            'text'
        );

        $content = [
            Node::heading($title, 1),
            Node::section('parties', [
                Node::section('issuer', $this->identityLines($data['issuer'], true)),
                Node::section('recipient', $this->identityLines($data['client'], false)),
            ]),
        ];

        if ($registration = $mentions([LegalMention::PLACEMENT_HEADER])) {
            $content[] = Node::section('registration', Node::paragraphs($registration));
        }

        $content[] = Node::section('dates', Node::paragraphs($this->dateLines($data, $label)));
        $content[] = Node::section('lines', $this->lines($data, $label));
        $content[] = Node::section('totals', [Node::table($this->totalRows($data['totals'], $label))]);

        if ($this->isSettled($data)) {
            $content[] = Node::section('settlement', [
                Node::paragraph($label('settled'), [Node::bold()]),
                ...Node::paragraphs([
                    $data['dates']['paid'] ? $label('settled_on').' '.$data['dates']['paid']['formatted'] : null,
                    $data['totals']['paid']['formatted'],
                ]),
            ]);
        }

        $notes = [
            ...($data['note'] ? preg_split('/\R/', $data['note']) : []),
            ...$mentions([LegalMention::PLACEMENT_VAT, LegalMention::PLACEMENT_FOOTER]),
        ];
        if ($notes = Node::paragraphs($notes)) {
            $content[] = Node::section('notes', $notes);
        }

        if ($this->isPaidByTheRecipient($data)) {
            $content[] = Node::section('payment', $this->paymentBlocks($data['payment'], $label));
        }

        if ($terms = $mentions([LegalMention::PLACEMENT_PAYMENT])) {
            $content[] = Node::section('terms', Node::paragraphs($terms));
        }

        $meta = [
            'reference' => [Node::paragraph((string) ($data['number'] ?? ''))],
            'imprint' => [Node::paragraph($this->imprint($data['issuer']))],
        ];
        if ($data['legalMentions']) {
            $meta['legal'] = Node::paragraphs(preg_split('/\R/', $data['legalMentions']));
        }

        return Node::doc($title, [Node::section('body', $content)], $meta);
    }

    /**
     * @return list<array>
     */
    private function identityLines(
        array $identity,
        bool $withContact
    ): array {
        return Node::paragraphs([
            $identity['name'] ? trim($identity['name'].' '.($identity['legalForm'] ?? '')) : null,
            ...$identity['addressLines'],
            ...($withContact ? [$identity['phone'] ?? null, $identity['email'] ?? null, $identity['website'] ?? null] : []),
            $identity['legalIdentifier'] ? $identity['legalIdentifierLabel'].' : '.$identity['legalIdentifier'] : null,
            $identity['vatNumber'] ? $identity['vatNumberLabel'].' : '.$identity['vatNumber'] : null,
        ]);
    }

    /**
     * The issuer in one line, as a letterhead foot shows it.
     */
    private function imprint(array $issuer): string
    {
        return implode(' - ', array_filter([
            $issuer['name'] ? trim($issuer['name'].' '.($issuer['legalForm'] ?? '')) : null,
            implode(' ', $issuer['addressLines']),
        ]));
    }

    /**
     * @return list<string>
     */
    private function dateLines(
        array $data,
        callable $label
    ): array {
        $dates = $data['dates'];
        $lines = [$label('date').' : '.$dates['invoice']['formatted']];

        if ($dates['due'] && InvoiceType::from($data['type'])->isPayable()) {
            $lines[] = $label('due_date').' : '.$dates['due']['formatted'];
        }
        if ($dates['validUntil']) {
            $lines[] = $label('valid_until').' : '.$dates['validUntil']['formatted'];
        }
        foreach ($data['credited'] as $bill) {
            $lines[] = $label('credited_bill').' : '.$bill['number'].' ('.$bill['date']['formatted'].')';
        }
        if ($dates['periodStart'] && $dates['periodEnd']) {
            $lines[] = $label('period').' : '.$dates['periodStart']['formatted'].' → '.$dates['periodEnd']['formatted'];
        }

        return $lines;
    }

    /**
     * @return list<array>
     */
    private function lines(
        array $data,
        callable $label
    ): array {
        $rows = [[
            Node::header($label('item')),
            Node::header($label('quantity'), 'right'),
            Node::header($label('unit_price'), 'right'),
            Node::header($label('vat'), 'right'),
            Node::header($label('total'), 'right'),
        ]];

        foreach ($data['items'] as $item) {
            $rows[] = [
                Node::cell(Node::paragraphs([$item['title'], $item['description']])),
                Node::textCell(trim($item['quantity'].' '.($item['unit'] ?? '')), 'right'),
                Node::textCell($item['unitPrice']['formatted'], 'right'),
                Node::textCell($item['vatRateFormatted'], 'right'),
                Node::textCell($item['total']['formatted'], 'right'),
            ];
        }

        foreach ($data['credits'] as $credit) {
            $rows[] = [
                Node::textCell($label('credit_note').' '.$credit['number']),
                Node::textCell(''),
                Node::textCell(''),
                Node::textCell(''),
                Node::textCell($credit['total']['formatted'], 'right'),
            ];
        }

        return array_values(array_filter([
            $data['subject'] ? Node::heading($data['subject'], 2) : null,
            Node::table($rows),
        ]));
    }

    private function totalRows(
        array $totals,
        callable $label
    ): array {
        $row = fn (string $key, string $amount, array $marks = []) => [
            Node::textCell($label($key)),
            Node::cell([Node::paragraph($amount, $marks)], 'right'),
        ];
        $rows = [];

        if ($totals['discount']) {
            $rows[] = $row('subtotal', $totals['subTotal']['formatted']);
            $rows[] = $row('discount', '-'.$totals['discount']['formatted']);
        }

        $rows[] = $row('net', $totals['net']['formatted']);

        foreach ($totals['vatLines'] as $line) {
            if ($line['vat']['amount'] > 0) {
                $rows[] = [
                    Node::textCell($label('vat').' '.$line['rateFormatted'].' ('.$line['base']['formatted'].')'),
                    Node::textCell($line['vat']['formatted'], 'right'),
                ];
            }
        }

        if ($totals['overridden']) {
            $rows[] = $row('total', $totals['total']['formatted'], [Node::strike()]);
            $rows[] = $row('total', $totals['overridden']['formatted'], [Node::bold()]);
        } else {
            $rows[] = $row('total', $totals['total']['formatted'], [Node::bold()]);
        }

        if ($totals['paid']) {
            $rows[] = $row('paid', '-'.$totals['paid']['formatted']);
            $rows[] = $row('amount_due', $totals['due']['formatted'], [Node::bold()]);
        }

        return $rows;
    }

    /**
     * @return list<array>
     */
    private function paymentBlocks(
        array $payment,
        callable $label
    ): array {
        $blocks = [Node::heading($label('payment'), 2)];

        if ($payment['epcQr']) {
            $blocks[] = Node::qrCode($payment['epcQr'], $label('scan_to_pay'));
        }

        $blocks[] = Node::paragraph($label('payment_reference').' : '.$payment['reference']);

        if ($bank = $payment['bank']) {
            if ($bank['holder'] ?? null) {
                $blocks[] = Node::paragraph($label('holder').' : '.$bank['holder']);
            }

            $blocks[] = Node::table($this->bankRows($bank));
        }

        return $blocks;
    }

    /**
     * The national form of the account as one row of labels and one of values,
     * then the IBAN and the BIC across the same width.
     */
    private function bankRows(array $bank): array
    {
        $details = $bank['localDetails'];
        $span = max(1, count($details) - 1);
        $rows = [];

        if ($details) {
            $rows[] = array_map(fn (array $detail) => Node::header($detail['label']), $details);
            $rows[] = array_map(fn (array $detail) => Node::textCell($detail['value']), $details);
        }

        $rows[] = [Node::header('IBAN'), Node::textCell(trim(chunk_split((string) $bank['iban'], 4, ' ')), colspan: $span)];

        if (! empty($bank['bic'])) {
            $rows[] = [Node::header('BIC'), Node::textCell($bank['bic'], colspan: $span)];
        }

        return $rows;
    }

    /**
     * Who pays this document is its recipient: a sale the books expect money for.
     * A credit note is paid back by the issuer, a quotation not paid at all.
     */
    private function isPaidByTheRecipient(array $data): bool
    {
        return 'sale' === $data['direction'] && InvoiceType::from($data['type'])->isPayable();
    }

    private function isSettled(array $data): bool
    {
        return $this->isPaidByTheRecipient($data)
            && $data['totals']['paid']
            && $data['totals']['due']['amount'] <= 0;
    }

    private function trans(
        string $key,
        ?string $locale
    ): string {
        return $this->translator?->trans($key, [], 'accounting', $locale) ?? $key;
    }
}
