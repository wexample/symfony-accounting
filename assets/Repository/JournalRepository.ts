import AbstractApiRepository from '@wexample/js-api-entity/Common/AbstractApiRepository';
import Journal from '../Entity/Journal.js';

export default class JournalRepository extends AbstractApiRepository<Journal> {
  static getEntityType() {
    return Journal;
  }
}
