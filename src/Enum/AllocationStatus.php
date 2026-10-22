<?php

namespace Wexample\SymfonyAccounting\Enum;

enum AllocationStatus: string
{
    /** Proposed by a matcher, to be confirmed. */
    case Pending = 'pending';

    /** Confirmed: the payment is booked. */
    case Validated = 'validated';

    /** Rejected: matchers never propose this pair again. */
    case Excluded = 'excluded';

    public function isActive(): bool
    {
        return self::Excluded !== $this;
    }
}
