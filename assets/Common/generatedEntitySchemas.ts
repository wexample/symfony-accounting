import account from '../data/entity/account.json';
import allocation from '../data/entity/allocation.json';
import bankAccount from '../data/entity/bank_account.json';
import bankTransaction from '../data/entity/bank_transaction.json';
import entryLine from '../data/entity/entry_line.json';
import fiscalYear from '../data/entity/fiscal_year.json';
import invoice from '../data/entity/invoice.json';
import invoiceItem from '../data/entity/invoice_item.json';
import invoiceTransmission from '../data/entity/invoice_transmission.json';
import journal from '../data/entity/journal.json';
import journalEntry from '../data/entity/journal_entry.json';
import ledger from '../data/entity/ledger.json';
import party from '../data/entity/party.json';

type EntitySchema = { name: string };

export default function getGeneratedEntitySchemas(): Record<string, EntitySchema> {
  return {
    [account.name]: account,
    [allocation.name]: allocation,
    [bankAccount.name]: bankAccount,
    [bankTransaction.name]: bankTransaction,
    [entryLine.name]: entryLine,
    [fiscalYear.name]: fiscalYear,
    [invoice.name]: invoice,
    [invoiceItem.name]: invoiceItem,
    [invoiceTransmission.name]: invoiceTransmission,
    [journal.name]: journal,
    [journalEntry.name]: journalEntry,
    [ledger.name]: ledger,
    [party.name]: party,
  };
}
