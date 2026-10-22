import AbstractApiEntity from '@wexample/js-api-entity/Common/AbstractApiEntity';
import schema from '../data/entity/fiscal_year.json';

export default class FiscalYear extends AbstractApiEntity {
  static readonly entityName = 'fiscalYear';

  static retrieveEntitySchema() {
    return schema;
  }
}
