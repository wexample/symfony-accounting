import AbstractApiRepository from '@wexample/js-api-entity/Common/AbstractApiRepository';
import Party from '../Entity/Party.js';

export default class PartyRepository extends AbstractApiRepository<Party> {
  static getEntityType() {
    return Party;
  }
}
