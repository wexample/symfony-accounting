import AbstractApiRepository from '@wexample/js-api-entity/Common/AbstractApiRepository';
import Invoice from '../Entity/Invoice.js';

export default class InvoiceRepository extends AbstractApiRepository<Invoice> {
  static getEntityType() {
    return Invoice;
  }
}
