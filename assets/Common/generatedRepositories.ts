import AccountRepository from '../Repository/AccountRepository.js';
import AllocationRepository from '../Repository/AllocationRepository.js';
import BankAccountRepository from '../Repository/BankAccountRepository.js';
import BankTransactionRepository from '../Repository/BankTransactionRepository.js';
import EntryLineRepository from '../Repository/EntryLineRepository.js';
import FiscalYearRepository from '../Repository/FiscalYearRepository.js';
import InvoiceItemRepository from '../Repository/InvoiceItemRepository.js';
import InvoiceRepository from '../Repository/InvoiceRepository.js';
import InvoiceTransmissionRepository from '../Repository/InvoiceTransmissionRepository.js';
import JournalEntryRepository from '../Repository/JournalEntryRepository.js';
import JournalRepository from '../Repository/JournalRepository.js';
import LedgerRepository from '../Repository/LedgerRepository.js';
import PartyRepository from '../Repository/PartyRepository.js';

const generatedRepositories = [AccountRepository, AllocationRepository, BankAccountRepository, BankTransactionRepository, EntryLineRepository, FiscalYearRepository, InvoiceItemRepository, InvoiceRepository, InvoiceTransmissionRepository, JournalEntryRepository, JournalRepository, LedgerRepository, PartyRepository];

export default generatedRepositories;
