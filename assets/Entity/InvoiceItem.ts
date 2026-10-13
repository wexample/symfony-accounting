import AbstractApiEntity from '@wexample/js-api-entity/Common/AbstractApiEntity';
import schema from '../data/entity/invoice_item.json';

export default class InvoiceItem extends AbstractApiEntity {
  static readonly entityName = 'invoiceItem';

  static retrieveEntitySchema() {
    return schema;
  }
}
