<?php

namespace Wexample\SymfonyAccounting\Enum;

/**
 * What an account is used for when entries are generated. Each jurisdiction maps
 * every role to an account number of its chart (FR 411, BE 400 for customers…),
 * so that no account number is ever written in the core.
 */
enum AccountRole: string
{
    case Customers = 'customers';
    /** Deposits received from customers before the final bill. */
    case CustomerAdvances = 'customer_advances';
    case Suppliers = 'suppliers';
    case SalesGoods = 'sales_goods';
    case SalesServices = 'sales_services';
    /** Purchases of goods. */
    case Purchases = 'purchases';
    case PurchasesServices = 'purchases_services';
    case Bank = 'bank';
    case Cash = 'cash';
    /** Money moving between two of the ledger's own accounts (FR 58). */
    case InternalTransfer = 'internal_transfer';
    /** Money collected by a payment provider, not yet paid out (Stripe balance). */
    case PaymentProvider = 'payment_provider';
    case BankFees = 'bank_fees';
    case VatCollected = 'vat_collected';
    /** VAT invoiced, due only once paid (VAT on payments). */
    case VatCollectedPending = 'vat_collected_pending';
    case VatDeductible = 'vat_deductible';
    case VatDeductiblePending = 'vat_deductible_pending';
    case VatDeductibleAssets = 'vat_deductible_assets';
    /** VAT due by self-assessment (reverse charge, intra-EU acquisitions). */
    case VatSelfAssessed = 'vat_self_assessed';
    case VatPayable = 'vat_payable';
    case VatCredit = 'vat_credit';
    case VatDeposits = 'vat_deposits';
    case DoubtfulCustomers = 'doubtful_customers';
    case BadDebts = 'bad_debts';
    case LatePenaltiesIncome = 'late_penalties_income';
    case DepreciationExpense = 'depreciation_expense';
    /** Book value of fixed assets sold or scrapped. */
    case AssetDisposalValue = 'asset_disposal_value';
    case PrepaidExpenses = 'prepaid_expenses';
    case DeferredIncome = 'deferred_income';
    case RoundingGain = 'rounding_gain';
    case RoundingLoss = 'rounding_loss';
    case ResultProfit = 'result_profit';
    case ResultLoss = 'result_loss';
    case RetainedEarnings = 'retained_earnings';
    case RetainedLosses = 'retained_losses';
    case OwnerAccount = 'owner_account';
    case Suspense = 'suspense';
}
