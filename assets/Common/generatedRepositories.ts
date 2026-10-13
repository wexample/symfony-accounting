import InvoiceItemRepository from '../Repository/InvoiceItemRepository.js';
import InvoiceRepository from '../Repository/InvoiceRepository.js';
import LedgerRepository from '../Repository/LedgerRepository.js';
import PartyRepository from '../Repository/PartyRepository.js';

const generatedRepositories = [InvoiceItemRepository, InvoiceRepository, LedgerRepository, PartyRepository];

export default generatedRepositories;
