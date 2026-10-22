import AbstractApiRepository from '@wexample/js-api-entity/Common/AbstractApiRepository';
import BankTransaction from '../Entity/BankTransaction.js';

export default class BankTransactionRepository extends AbstractApiRepository<BankTransaction> {
  static getEntityType() {
    return BankTransaction;
  }
}
