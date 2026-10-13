import invoice from '../data/entity/invoice.json';
import invoiceItem from '../data/entity/invoice_item.json';
import ledger from '../data/entity/ledger.json';
import party from '../data/entity/party.json';

type EntitySchema = { name: string };

export default function getGeneratedEntitySchemas(): Record<string, EntitySchema> {
  return {
    [invoice.name]: invoice,
    [invoiceItem.name]: invoiceItem,
    [ledger.name]: ledger,
    [party.name]: party,
  };
}
