import AbstractApiRepository from '@wexample/js-api-entity/Common/AbstractApiRepository';
import Account from '../Entity/Account.js';

export default class AccountRepository extends AbstractApiRepository<Account> {
  static getEntityType() {
    return Account;
  }
}
