import AbstractApiEntity from '@wexample/js-api-entity/Common/AbstractApiEntity';
import schema from '../data/entity/entry_line.json';

export default class EntryLine extends AbstractApiEntity {
  static readonly entityName = 'entryLine';

  static retrieveEntitySchema() {
    return schema;
  }
}
