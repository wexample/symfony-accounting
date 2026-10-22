import AbstractApiRepository from '@wexample/js-api-entity/Common/AbstractApiRepository';
import InvoiceTransmission from '../Entity/InvoiceTransmission.js';

export default class InvoiceTransmissionRepository extends AbstractApiRepository<InvoiceTransmission> {
  static getEntityType() {
    return InvoiceTransmission;
  }
}
