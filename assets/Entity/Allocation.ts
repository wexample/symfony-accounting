import AbstractApiEntity from '@wexample/js-api-entity/Common/AbstractApiEntity';
import schema from '../data/entity/allocation.json';

export default class Allocation extends AbstractApiEntity {
  static readonly entityName = 'allocation';

  static retrieveEntitySchema() {
    return schema;
  }
}
