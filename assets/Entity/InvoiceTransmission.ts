import AbstractApiEntity from '@wexample/js-api-entity/Common/AbstractApiEntity';
import schema from '../data/entity/invoice_transmission.json';

export default class InvoiceTransmission extends AbstractApiEntity {
  static readonly entityName = 'invoiceTransmission';

  static retrieveEntitySchema() {
    return schema;
  }
}
