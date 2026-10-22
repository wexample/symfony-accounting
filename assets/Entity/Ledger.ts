import AbstractApiEntity from '@wexample/js-api-entity/Common/AbstractApiEntity';
import schema from '../data/entity/ledger.json';

export default class Ledger extends AbstractApiEntity {
  static readonly entityName = 'ledger';

  static retrieveEntitySchema() {
    return schema;
  }
}
