import AbstractApiEntity from '@wexample/js-api-entity/Common/AbstractApiEntity';
import schema from '../data/entity/party.json';

export default class Party extends AbstractApiEntity {
  static readonly entityName = 'party';

  static retrieveEntitySchema() {
    return schema;
  }
}
