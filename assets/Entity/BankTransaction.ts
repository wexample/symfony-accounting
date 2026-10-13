import AbstractApiEntity from '@wexample/js-api-entity/Common/AbstractApiEntity';
import schema from '../data/entity/bank_transaction.json';

export default class BankTransaction extends AbstractApiEntity {
  static readonly entityName = 'bankTransaction';

  static retrieveEntitySchema() {
    return schema;
  }
}
