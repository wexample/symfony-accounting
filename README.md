# symfony_accounting

Version: 7.0.0

## A ledger

```php
$ledger = $ledgerService->create('Acme SRL', $belgium, fiscalYearStart: new DateTimeImmutable('2026-01-01'));
$ledger->setInvoicePrefix('ACM')->getOrganization()->setLegalIdentifier('0123.456.749')->setVatNumber('BE0123456749');
$customer = $partyService->create($ledger, 'Client SA');           // gets its auxiliary code, CCLIENTSA
```

`create()` loads the jurisdiction's chart and journals. Accounts missing from the chart are created on first use, labelled after their closest parent. The ledger's settings (`setSettings()`) tune the rest: `default_vat_rate`, `invoice_number_pattern`, `hours_per_day`, `late_penalty_rate`, `auto_post_invoices`… — each documented where it is read.

## Ledgers and parties are organizations

Who keeps the books and whom they trade with are organizations of the directory (`wexample/symfony-organization`): `Ledger` and `Party` point to an `Organization`, which holds the legal identity — name, legal identifier, VAT number, legal form, contacts, address, and its country, which selects the ledger's jurisdiction. Both read that identity as their own (`getName()`, `getVatNumber()`, `getCountry()`…, `LegalIdentityInterface`) and change it on the organization: `$party->getOrganization()->setAddress($address)`, seen by every ledger the organization is a party to. Its address is a row of symfony-geo's `address` table it points at, so a move is written once, there. What belongs to one ledger stays on the party: its auxiliary codes, default account, payment terms, IBAN, notes.

```php
$tpa = $organizationService->findOrCreate('The Photo Academy SA', $belgium, vatNumber: 'BE0417497106');
$inWexample = $partyService->findOrCreate($wexample, $tpa);       // one party per ledger and organization
$inWeegerBe = $partyService->findOrCreate($weegerBe, $tpa);
$books = $ledgerService->create($tpa);                            // its own books, on the same organization
```

`create()` accepts an organization or a name (then a new organization, in the given country). Parties made from a payment (`PayableInvoicingService`) or a received e-invoice (`ElectronicInvoiceImporter`) find the organization already in the directory by VAT number or legal identifier before adding one. Documents keep the identity they froze when emitted (`issuerSnapshot`, `partySnapshot`); each identity is labelled as its own country names its identifiers (`LegalIdentifierSchemeInterface`). The API keeps reading and writing a party's or a ledger's identity as fields of their own, and gives the id of their `organization`.

## Who may reach a ledger

Three permissions, asked on a ledger or on anything it holds (an invoice, a party, an entry…, `BelongsToLedgerInterface`): `accounting.view`, `accounting.edit`, `accounting.administer` (`LedgerPermission`).

```twig
{% if is_granted('accounting.edit', invoice) %}…{% endif %}
```

`LedgerVoter` grants a permission to whoever holds the role configured for it, else the grant configured for it on the group the application ties the ledger to:

```yaml
wexample_symfony_accounting:
    access:
        roles: { view: ROLE_ADMIN, edit: ROLE_ADMIN, administer: ROLE_ADMIN }  # the defaults
        grants: { view: client, edit: accountant, administer: partner }         # none by default
```

- **By role only** (no group): a firm whose accountants see every ledger — give them the role.
- **By group**: the application implements `LedgerGroupResolverInterface::getGroup($ledger)` — usually from `getExternalReference()` — returning its own group, a firm or a client's team. With symfony-user, that group is a membership group, and its members' grants (`memberships.grants`) answer: an accountant of the firm, a client of their own ledger only. Implementing the interface is enough to be used.

`LedgerAccessService::getAccessible($permission)` lists the ledgers the signed-in user may reach.

## Documents

```php
$invoice = $invoiceFactory->create($ledger, party: $customer);
$invoiceFactory->addItem($invoice, 'Development', 50000, '2j');   // 2 days at 500.00
$emissionService->emit($invoice);                                  // numbered, frozen, booked
```

Emission is the only way a document becomes official: VAT resolved per item (domestic, intra-EU, export, reverse charge from the EU or from outside it, franchise, and the domestic reverse charge of construction works, asked per item with `setDomesticReverseCharge()`), gap-free number for sales, issuer and party identities (name, identifiers, bank, country, and their address as an `AddressSnapshot`) frozen into snapshots, so a later move leaves the document as it was sent, `InvoiceEmittedEvent`, then the entry in the ledger. Quotations convert to bills (`createBillFromQuotation`, deducting deposit bills), bills get credit notes and penalties (`LatePenaltyCalculator`), models renew monthly.

`ElectronicInvoiceDataBuilder` reads an emitted document in the terms of EN 16931; two builders write it. `CiiInvoiceBuilder` writes UN/CEFACT CII, the XML of Factur-X, checked against the Factur-X 1.09 EN 16931 XSD and schematron. `UblInvoiceBuilder` writes the structured electronic invoice (UBL 2.1, Peppol BIS Billing 3.0 / EN 16931): mandatory for B2B invoices in Belgium since 2026, one of the formats of the French reform. Received invoices go the other way. `UblInvoiceReader` and `CiiInvoiceReader` read the XML into the same terms, and `ElectronicInvoiceImporter` makes a purchase draft from them: supplier found by VAT number or created with its IBAN, checked against the supplier's stated total, origin kept. `FacturXInvoiceReader` takes a received Factur-X PDF: the document-reader service (`symfony-remote-document-reader`) takes its XML out in a sandbox, and a Factur-X whose metadata does not announce its XML comes with a warning. Peppol goes through `symfony-remote-peppol` and the access point the application installs. `PeppolInvoiceService` sends an emitted sale as UBL, from the ledger's address to the client's (enterprise number, SIRET, else VAT number), once the directory says the client accepts it, and keeps each transmission (`InvoiceTransmission`: provider, reference, status). `PeppolSubscriber` writes what the access point reports: a sent document delivered or failed, a received invoice made a purchase draft in the ledger it is addressed to, its warnings in `import_warnings`. A received document no ledger answers to is left with the access point. Validate the output on the Peppol testbed before going live.

`InvoiceDocumentDataBuilder` gives everything a printed document shows; `PdfFactoryDocumentBuilder` turns it into a pdf-factory document whose parts are sections named by their role (parties, dates, lines, totals, settlement, notes, payment with the EPC code and the national account form, terms); `InvoicePdfService::render()` dresses it with the charter and posts it. The application has the last word through `InvoiceDocumentEvent`: it may add sections of its own (a letterhead) and change the theme (its stylesheet, a logo block) before the document leaves.

The PDF carries the document data as an embedded `invoice.json` (relationship `Data`), so a printed invoice can be checked against the books. The issuer is the file's author, and the emission date is its creation date, so two renders give the same bytes. The event can embed more files (`attachments`) or change the output (`output`). Where the jurisdiction asks for it (`isFacturXEmbedded()`, France), an emitted invoice's PDF is a Factur-X: PDF/A-3b carrying its CII XML, profile EN 16931, relationship `Alternative`.

```php
$pdf = $invoicePdfService->render($invoice, 'fr');
```

## Invoicing what was paid

```yaml
wexample_symfony_accounting:
  sales_ledger: shop   # the externalReference of the ledger that sells
```

With a sales ledger, every payment of `symfony-payment` that succeeds is invoiced there by `PayableInvoicingService`, whatever was paid: a cart of `symfony-shop`, a subscription, a registration. Accounting never sees the payable's class. It reads its lines as money's `PricedLineInterface` (title, quantity, unit price, rate, discount, goods or services, revenue account) and its buyer as payment's `BuyerIdentifiedInterface`. The buyer becomes a customer `Party`, found again by VAT number or email. The bill is emitted at once, linked to its payment (`Invoice::getPayment()`), its origin the payable type. A refund issues a credit note on it: the whole bill when the whole payment is refunded, else the amount at the bill's main rate. A bill that cannot be emitted (no exchange rate, no open fiscal year) stays a draft, logged. A document paid through payment is its own bill and is never invoiced again.

`ProviderPaymentMatcher` settles such a bill like a paid document: the provider's line carries the payment, the payment the bill.

## VAT at checkout

Accounting takes the place of money's `VatRateResolverInterface`, so a shop charges at checkout the VAT its invoice will carry. The rules are those of the sales ledger's country. A business from another EU country pays none (reverse charge), once its number is confirmed by VIES (`symfony-remote-vies`, through `VatNumberVerifier`). A consumer abroad pays their country's rate once the One-Stop Shop applies (`OssService::appliesToLedger()`). Everyone else pays the seller's rate. A VIES giving no verdict confirms nothing: the buyer is charged VAT and the number is not kept on the customer, so bill and payment agree. Without a sales ledger, or before the buyer's country is known, money's default answers: the product's own rate.

The sale that passes the OSS threshold is only seen at emission, on its document. Its bill may then ask another amount than checkout charged; the gap is logged.

## Payment proofs

A proof attached to a manual payment (`PaymentService::attachProof()`, a transfer receipt, a scan) is read by `PaymentProofReader` through document-reader. Whether it shows the payment's amount and one of its references (payment, payable, provider) is written under the payment's metadata `proof_reading`. It is a help for whoever confirms the money, never a confirmation. Without a document-reader URL nothing is read.

## Bank

```php
$bankImportService->importContent($bankAccount, $fileContent);     // CAMT, OFX, CODA, LBP…: detected
$matchingService->run($ledger);                                    // proposals and sure matches
$allocationService->allocate($line, $invoice);                     // or allocateToAccount(), linkTransfer()
$letteringService->letterLedger($ledger);
```

Imports never duplicate a line. Matchers propose (pending) or settle (validated) by provider payment, payment reference, label rules, transfers between the ledger's accounts and exact amounts; refusing a proposal excludes the pair for good. Validated allocations are booked at once, one entry per payment.

A bank account whose `provider` is set (`stripe`) is fed by `ProviderBalanceImporter` through symfony-remote-payment: gross payments, fees (booked on the bank fees account), refunds, payouts (matched as transfers).

## Documents in another currency

A document may be in another currency than its ledger's (`setCurrencyCode()`). At emission it fixes its exchange rate (`exchangeRate`): the one typed on it, else the provider's rate of its date through symfony-money's `ExchangeRateService` (`symfony-remote-ecb`). Without either, emission refuses.
- The entry is booked in the ledger's currency, each line keeping its amount in the document's currency beside it. Rounding goes to the customer or supplier line.
- A payment allocated to the document settles it in its currency (`Allocation::invoiceAmount`, by default what is left when the whole bank line pays it, else converted at the day's rate). The customer or supplier is cleared at the document's rate, and the difference with what the bank moved is an exchange gain or loss (`ExchangeGain`, `ExchangeLoss`: 766/666 FR, 754/654 BE).
- At closing, `ExchangeRevaluationService` values what is still owed at the day's rate, posts the gap as unrealized (`UnrealizedExchangeGain`, `UnrealizedExchangeLoss`: 477/476 FR, 754/654 BE) and reverses it the next day.
- The PDF states the VAT in the ledger's currency too, with the rate. UBL and CII carry the tax currency and the VAT total in it.

## One-Stop Shop (OSS)

Distance sales to consumers in other EU countries are taxed at the customer's country's standard rate (`EuVatRateHelper`) once the EU-wide threshold of 10 000 € is passed, this year or the last. The sale that passes it is the first one taxed there. A ledger registered from the start (setting `oss_registered`) is taxed there from its first such sale.
- Goods count. Services count only for a ledger selling services delivered electronically (setting `oss_electronic_services`). Other services to consumers stay taxed where the seller is.
- Reduced rates are not chosen: an OSS sale is charged the standard rate.
- `OssService` keeps the VAT on its own account (`AccountRole::VatCollectedOss`) and leaves it out of the national return. It computes the quarter's return by country and rate, exports it as CSV for the portal, and settles it into VAT payable.

## Partial VAT deduction

A ledger that may not deduct all the VAT it pays says so in its settings:
- `vat_deduction_rate`, or `vat_deduction_rates` by year, in basis points: the deduction coefficient (FR assujettissement × taxation, BE prorata);
- `vat_admission_rates`: an admission by expense account prefix (receptions, passenger cars), the longest prefix winning.

At booking, `InvoiceAccountingService` splits the VAT of a purchase, charged or self-assessed, along its bases:
- what the admission excludes is a plain cost;
- what the coefficient leaves undeducted is a cost tagged `VatRole::NonDeductible`;
- the rest is deductible.

Under VAT on payments, a payment moves only what was booked as pending.

`VatDeductionRegularizationService` makes the year final: what was deducted at the provisional coefficient is set right with the final one, on the year's last day, against `VatAdjustmentGain` or `VatAdjustmentLoss`. The final coefficient becomes next year's provisional one. Multi-year regularizations of fixed assets are not handled.

## VAT position

`VatPositionService::build($ledger, $year)` reads where the year's VAT stands, one column per return period — months, quarters, or half-years for an annual regime —: VAT collected, deductible (the part on fixed assets apart), net, deposits paid as booked, and the deposits the jurisdiction expects from last year's net VAT (`getVatDepositSchedule()`: the July and December deposits of the French simplified regime), then the balance left for the year. Deductible VAT on fixed assets (`isFixedAssetAccount()`: class 2 without 28 and 29) is booked on its own account (`AccountRole::VatDeductibleAssets`), which the forms read apart.

## Period end

```php
$vatReturn = $vatReturnService->compute($ledger, $from, $to);
$vatReturnService->fillForms($vatReturn);   // CA3, CA12, BE periodic… from the jurisdiction packages
$vatReturnService->settle($vatReturn);
$closingService->close($fiscalYear);         // checks, result, opening entries of the next year, lock
```

Before closing, `DepreciationService` books the year's depreciation of the fixed assets (straight-line, pro rata temporis by day; `accounting:depreciation`), `AccrualService` posts adjustments that reverse themselves on the next year's first day, and the checks warn about anything missing.

Reports are computed on demand: `TrialBalanceService`, `GeneralLedgerService`, `FinancialStatementService` (balance sheet, income statement from the jurisdiction's layouts), `AgedBalanceService`. `LedgerExportService` writes the books (FEC, CSV), `EntryImportService` takes another bookkeeping over.

## Collections and supplier payments

`DunningService` finds the reminders due by level (`dunning_levels`, days late; `accounting:dunning --record`) and keeps their history on the document; the host sends them on `InvoiceReminderRecordedEvent`. `SepaCreditTransferBuilder` writes the pain.001 file paying a batch of purchases, structured references included.

## API

Each entity is served under its own name, as `js-api-entity` repositories call it, every answer held to the norm of `symfony-api`. A ledger the reader may not reach, or anything in it, does not exist for them (404); reading asks `accounting.view`, writing `accounting.edit` (LedgerVoter). A request breaking a rule of the books is answered 400 with the rule's words.

| Endpoint | What |
|---|---|
| `GET api/ledger/list`, `show/{id}`; `POST update/{id}` | the ledgers the reader may open; their settings (`accounting.administer`) |
| `GET api/invoice/list?ledger=&type=&status=&direction=&year=&search=&sort=` | documents, paginated; `show/{id}`, `pdf/{id}?locale=` |
| `POST api/invoice/create`, `update/{id}`, `emit/{id}`, `credit-note/{id}`, `bill/{id}`, `duplicate/{id}`; `DELETE delete/{id}` | a draft, its fields, its emission, its cancellation by a credit note, the bill of a quotation, a copy, a draft deleted |
| `POST api/invoice/relate/{id}` (`other`, `type` rebill\|financing, `way` to\|from); `DELETE unrelate/{id}/{relation}` | a link set by hand between two documents of a ledger; the others (quotation, credit, deposit, penalty) follow from their action. A document lists its links both ways in its metadata, `related` |
| `GET api/invoice/electronic/{id}?format=ubl\|cii` | an emitted document as a structured electronic invoice, UBL (Peppol) or CII (Factur-X) |
| `GET api/invoice/peppol/{id}`; `POST peppol/{id}` | a document's transmissions, whether it can go over Peppol beside them (`reachable`); sending an emitted sale, answered with its transmission |
| `POST api/invoice/receive?ledger=` (multipart `file`) | a received Factur-X PDF, CII or UBL made a purchase draft, with `partyCreated` and `warnings` |
| `GET api/invoice-item/list?invoice=`; `POST create`, `update/{id}`; `DELETE delete/{id}` | the lines of a draft, as entities (`priceRaw`, `priceVat`, `quantity`…), each answered with the line, what it comes to in its metadata (`priceNet`); the document's totals are read again from the document |
| `POST api/invoice-item/import` (`entities`: lines as `create` takes them) | several lines at once — a table's rows, read into lines by the sender; one bad line adds nothing |
| `POST api/invoice-item/read-table?ledger=` (multipart `file`) | the tables of a file (CSV, XLSX, ODS, DOCX, PDF…) as rows of texts, read by document-reader, for choosing the columns |
| `GET api/party/list?ledger=&role=customer\|supplier&search=`, `show/{id}`; `POST create`, `update/{id}` | customers and suppliers |
| `GET api/bank-account/list?ledger=` | bank accounts |
| `GET api/bank-transaction/list?ledger=&bankAccount=&unallocated=1&year=`, `proposals/{id}`; `POST import?bankAccount=` (multipart `file`), `match?ledger=`, `link-transfer`, `unlink-transfer/{id}` | bank lines, what is left to explain and whether it is a transfer in their metadata (`unallocatedAmount`, `transfer`); statements imported, matching, internal transfers |
| `GET api/allocation/list?transaction=`; `POST create`; `DELETE delete/{id}` | what a line pays; removing excludes and reverses |
| `GET api/account/list?ledger=&search=` | the chart, by number or label |
| `GET api/journal-entry/list?ledger=&journal=&year=`; `POST create`, `reverse/{id}` | entries, their journal, lines, and the lines' accounts and parties as relationships; written by hand, reversed |
| `POST api/entry-line/letter`, `unletter/{id}` | lettering |
| `GET api/fiscal-year/list?ledger=`, `check/{id}`; `POST next?ledger=`, `close/{id}?force=`, `reopen/{id}` | fiscal years, their label in their metadata (closing asks `accounting.administer`) |
| `GET api/vat-return/show?ledger=&from=&to=`; `POST settle`; `GET position?ledger=&year=` | the VAT of a period, the forms' boxes, its settlement; where the year's VAT stands |
| `GET api/vat-return/deduction?ledger=&year=&rate=`; `POST deduction-regularize` | what the year's final deduction coefficient sets right; its regularization |
| `GET api/vat-return/oss?ledger=&from=&to=`; `POST oss-settle` | the OSS return by country and rate, the year's distance sales against the threshold, its CSV; its settlement |
| `GET api/accounting-report/statement?fiscalYear=&key=`, `general-ledger?fiscalYear=&account=`, `export?fiscalYear=&format=` | statements, general ledger, exports (the formats offered without `format`) |
| `GET api/trial-balance/show?ledger=&year=` | the trial balance of a calendar year |

An update changes the fields sent, and only those: a field sent as null is emptied, one left out kept.

Amounts are integers in minor units, quantities scaled by 100, VAT rates in basis points. Entities travel under the names of their fields, as the exported schemas (`assets/data/entity`) declare them; what is computed travels in `metadata`. A document carries its party and items as relationships, and in its metadata its amounts (`amountNet`, `amountVat`, `amountTotal`) and what answered it: `amountCredited`, `amountPaid`, `amountDue`.

## Checks

`InvoiceChecker`, `BankTransactionChecker` and `FiscalYearChecker` report through symfony-check. Errors of the fiscal year block its closing.

## Table of Contents

- [A ledger](#a-ledger)
- [Ledgers and parties are organizations](#ledgers-and-parties-are-organizations)
- [Who may reach a ledger](#who-may-reach-a-ledger)
- [Documents](#documents)
- [Invoicing what was paid](#invoicing-what-was-paid)
- [VAT at checkout](#vat-at-checkout)
- [Payment proofs](#payment-proofs)
- [Bank](#bank)
- [Documents in another currency](#documents-in-another-currency)
- [One-Stop Shop (OSS)](#one-stop-shop-oss)
- [Partial VAT deduction](#partial-vat-deduction)
- [VAT position](#vat-position)
- [Period end](#period-end)
- [Collections and supplier payments](#collections-and-supplier-payments)
- [API](#api)
- [Checks](#checks)
- [Architecture](#architecture)
- [Integration in the Suite](#integration-in-the-suite)
- [Dependencies](#dependencies)
- [Versioning & Compatibility Policy](#versioning--compatibility-policy)
- [License](#license)
- [About us](#about-us)
- [Migration Notes](#migration-notes)

## Architecture

### Ledger

src/Entity/Ledger.php is a set of books; every other entity belongs to one. src/Entity/JournalEntry.php and src/Entity/EntryLine.php are the double entry: amounts are int minor units in debit/credit columns, a line may carry a party (its auxiliary account), a letter, and a VAT code with its role (base or tax).

src/Service/Ledger/PostingService.php is the only way into the books: it checks balance and fiscal year, numbers entries without gaps per fiscal year (fiscal year row locked), and reverses rather than deletes. Callers describe entries with src/Class/EntryDraft.php, naming accounts by number or by src/Enum/AccountRole.php; src/Service/Ledger/ChartService.php resolves roles through the ledger's overrides, then its jurisdiction.

Entries know their source (`sourceType`, `sourceId`): an invoice, an allocation, a transfer, an opening. That is what makes booking idempotent and what lettering follows.

### Jurisdictions

src/Interface/JurisdictionInterface.php holds everything a country decides. src/Service/Jurisdiction/AbstractJurisdiction.php implements the EU VAT rules and generates VAT codes (`S_DOM_2100`, `P_RC_2000`, `S_IEUS_0`…); src/Service/Jurisdiction/DefaultJurisdiction.php serves countries without a package. Jurisdiction packages ship data (charts as CSV, statement layouts as PHP arrays) and the mentions' texts as translations.

### Invoicing

src/Entity/Invoice.php uses the symfony-money priced traits: a parent summing its items, VAT per item, discount spread over the VAT bases. Status changes go through src/Service/Invoice/InvoiceWorkflow.php; emission through src/Service/Invoice/InvoiceEmissionService.php. src/Service/Invoice/InvoiceAccountingService.php turns a document into an entry: bases split per account and VAT code so that they sum exactly to each rate's base, self-assessed VAT booked twice, VAT on payments parked on pending accounts.

### Bank

Parsers (src/Interface/BankStatementParserInterface.php) are pure: content in, src/Class/ParsedStatement.php out. src/Service/Bank/BankImportService.php deduplicates by external id, else by fingerprint counted per occurrence. src/Entity/Allocation.php links a line to a document or an account; src/EventSubscriber/BankAccountingSubscriber.php books it and moves the document's payment status. Matchers (src/Interface/TransactionMatcherInterface.php) only propose; src/Service/Bank/MatchingService.php applies proposals above a threshold and never one that was excluded.

src/Service/Ledger/LetteringService.php letters party accounts by connected components: lines of a document, its write-off and its allocations are connected, and payments covering several documents connect them; a balanced component gets a letter.

### Period end and reports

src/Service/Vat/VatReturnService.php sums tagged lines per VAT code — tax only where it is due or deductible, so VAT on debits and on payments need no separate code — and national forms (src/Interface/VatReturnFormInterface.php) place the totals in their boxes. src/Service/Ledger/FiscalYearClosingService.php computes the result on net balances and posts the next year's opening entries, party by party. src/Service/Report/FinancialStatementService.php evaluates the jurisdiction's layouts: an account goes to the first line whose rules take it.

### What is not here yet

- An HTTP API: its shape depends on the screens of `symfony-accounting-ds`, and access to ledgers (firm staff, clients) is a host decision to make first.
- Documents in another currency than their ledger's: refused at emission.
- Partly deductible VAT (cars…), domestic reverse charge (construction), OSS distance sales.

## Integration in the Suite

This package is part of the Wexample Suite — a collection of high-quality, modular tools designed to work seamlessly together across multiple languages and environments.

### Related Packages

The suite includes packages for configuration management, file handling, prompts, and more. Each package can be used independently or as part of the integrated suite.

Visit the [Wexample Suite documentation](https://docs.wexample.com) for the complete package ecosystem.

## Dependencies

- php: >=8.5
- league/csv: ^9.5
- wexample/php-date: >=2.1.0
- wexample/symfony-helpers: >=16.0.0
- wexample/symfony-money: >=7.0.0
- wexample/symfony-geo: >=7.0.0
- wexample/symfony-check: >=4.0.0
- wexample/symfony-payment: >=4.0.0
- wexample/symfony-remote-payment: >=3.0.0
- symfony/security-core: ^7.4 || ^8.0
- wexample/symfony-remote-pdf-factory: >=2.0.0
- wexample/symfony-remote-document-reader: >=2.0.0
- wexample/symfony-remote-peppol: >=2.0.0
- wexample/symfony-api: >=13.0.0
- wexample/php-pseudocode: >=2.2.0
- wexample/symfony-pseudocode: >=3.0.0
- wexample/symfony-media: >=2.0.0
- wexample/symfony-organization: >=2.0.0
- symfony/validator: ^7.4 || ^8.0

## Versioning & Compatibility Policy

Wexample packages follow **Semantic Versioning** (SemVer):

- **MAJOR**: Breaking changes
- **MINOR**: New features, backward compatible
- **PATCH**: Bug fixes, backward compatible

We maintain backward compatibility within major versions and provide clear migration guides for breaking changes.

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

Free to use in both personal and commercial projects.

## About us

[Wexample](https://wexample.com) stands as a cornerstone of the digital ecosystem — a collective of seasoned engineers, researchers, and creators driven by a relentless pursuit of technological excellence. More than a media platform, it has grown into a vibrant community where innovation meets craftsmanship, and where every line of code reflects a commitment to clarity, durability, and shared intelligence.

This packages suite embodies this spirit. Trusted by professionals and enthusiasts alike, it delivers a consistent, high-quality foundation for modern development — open, elegant, and battle-tested. Its reputation is built on years of collaboration, refinement, and rigorous attention to detail, making it a natural choice for those who demand both robustness and beauty in their tools.

Wexample cultivates a culture of mastery. Each package, each contribution carries the mark of a community that values precision, ethics, and innovation — a community proud to shape the future of digital craftsmanship.

## Migration Notes

When upgrading between major versions, refer to the migration guides in the documentation.

Breaking changes are clearly documented with upgrade paths and examples.
