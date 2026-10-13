import AbstractApiRepository from '@wexample/js-api-entity/Common/AbstractApiRepository';
import InvoiceItem from '../Entity/InvoiceItem.js';

export default class InvoiceItemRepository extends AbstractApiRepository<InvoiceItem> {
  static getEntityType() {
    return InvoiceItem;
  }
}
