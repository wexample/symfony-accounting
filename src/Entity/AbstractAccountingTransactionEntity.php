<?php

namespace Wexample\SymfonyAccounting\Entity;

use Wexample\SymfonyHelpers\Entity\AbstractEntity;

abstract class AbstractAccountingTransactionEntity extends AbstractEntity
{
    final public const TYPE_STATEMENT = 'statement';

    final public const TYPE_TRANSACTION = 'transaction';
}
