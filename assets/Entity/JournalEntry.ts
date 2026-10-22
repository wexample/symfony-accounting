import AbstractApiEntity from '@wexample/js-api-entity/Common/AbstractApiEntity';
import schema from '../data/entity/journal_entry.json';

export default class JournalEntry extends AbstractApiEntity {
  static readonly entityName = 'journalEntry';

  static retrieveEntitySchema() {
    return schema;
  }
}
