import AbstractApiRepository from '@wexample/js-api-entity/Common/AbstractApiRepository';
import JournalEntry from '../Entity/JournalEntry.js';

export default class JournalEntryRepository extends AbstractApiRepository<JournalEntry> {
  static getEntityType() {
    return JournalEntry;
  }
}
