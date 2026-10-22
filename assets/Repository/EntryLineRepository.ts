import AbstractApiRepository from '@wexample/js-api-entity/Common/AbstractApiRepository';
import EntryLine from '../Entity/EntryLine.js';

export default class EntryLineRepository extends AbstractApiRepository<EntryLine> {
  static getEntityType() {
    return EntryLine;
  }
}
