import AbstractApiEntity from '@wexample/js-api-entity/Common/AbstractApiEntity';
import schema from '../data/entity/account.json';

export default class Account extends AbstractApiEntity {
  static readonly entityName = 'account';

  static retrieveEntitySchema() {
    return schema;
  }
}
