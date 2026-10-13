import AbstractApiEntity from '@wexample/js-api-entity/Common/AbstractApiEntity';
import schema from '../data/entity/journal.json';

export default class Journal extends AbstractApiEntity {
  static readonly entityName = 'journal';

  static retrieveEntitySchema() {
    return schema;
  }
}
