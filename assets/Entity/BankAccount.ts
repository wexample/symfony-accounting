import AbstractApiEntity from '@wexample/js-api-entity/Common/AbstractApiEntity';
import schema from '../data/entity/bank_account.json';

export default class BankAccount extends AbstractApiEntity {
  static readonly entityName = 'bankAccount';

  static retrieveEntitySchema() {
    return schema;
  }
}
