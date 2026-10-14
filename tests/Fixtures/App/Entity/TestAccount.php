<?php

namespace Wexample\SymfonyAccounting\Tests\Fixtures\App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Wexample\SymfonyHelpers\Entity\AbstractEntity;
use Wexample\SymfonyHelpers\Entity\Interfaces\UserEntityInterface;
use Wexample\SymfonyOrganization\Entity\Traits\HasOrganizationMembershipsTrait;

/**
 * The account of the test application: symfony-organization's memberships
 * point at one, which the application resolves UserEntityInterface to.
 */
#[ORM\Entity]
#[ORM\Table(name: 'test_account')]
class TestAccount extends AbstractEntity implements UserEntityInterface
{
    use HasOrganizationMembershipsTrait;
}
