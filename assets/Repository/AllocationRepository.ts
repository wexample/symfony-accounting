import AbstractApiRepository from '@wexample/js-api-entity/Common/AbstractApiRepository';
import Allocation from '../Entity/Allocation.js';

export default class AllocationRepository extends AbstractApiRepository<Allocation> {
  static getEntityType() {
    return Allocation;
  }
}
