import AbstractApiRepository from '@wexample/js-api-entity/Common/AbstractApiRepository';
import Ledger from '../Entity/Ledger.js';

export default class LedgerRepository extends AbstractApiRepository<Ledger> {
  static getEntityType() {
    return Ledger;
  }
}
