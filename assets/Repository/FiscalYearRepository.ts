import AbstractApiRepository from '@wexample/js-api-entity/Common/AbstractApiRepository';
import FiscalYear from '../Entity/FiscalYear.js';

export default class FiscalYearRepository extends AbstractApiRepository<FiscalYear> {
  static getEntityType() {
    return FiscalYear;
  }
}
