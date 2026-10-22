`symfony-accounting` keeps the books of one or many companies in a Symfony app: double-entry ledger, invoicing, bank reconciliation, VAT, closing and financial statements. An accounting firm holds one ledger per client; an app keeping its own books holds one.

Nothing in it is specific to a country. Charts of accounts, VAT rules, legal mentions and statement layouts come from jurisdiction packages — `symfony-accounting-fr` and `symfony-accounting-be` — and a default jurisdiction serves the countries no package covers.

It never renders anything: printed documents are data handed to `pdf-factory`, and screens live in `symfony-accounting-ds` (to come).
