import AbstractApiRepository from '@wexample/js-api-entity/Common/AbstractApiRepository';
import BankAccount from '../Entity/BankAccount.js';

export default class BankAccountRepository extends AbstractApiRepository<BankAccount> {
  static getEntityType() {
    return BankAccount;
  }
}
