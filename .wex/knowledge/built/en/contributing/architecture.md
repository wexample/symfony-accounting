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
