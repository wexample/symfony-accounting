import AbstractApiEntity from '@wexample/js-api-entity/Common/AbstractApiEntity';
import schema from '../data/entity/invoice.json';

export default class Invoice extends AbstractApiEntity {
  static readonly entityName = 'invoice';

  static retrieveEntitySchema() {
    return schema;
  }
}
