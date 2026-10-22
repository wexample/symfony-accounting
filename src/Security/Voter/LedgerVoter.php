<?php

namespace Wexample\SymfonyAccounting\Security\Voter;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Enum\LedgerPermission;
use Wexample\SymfonyAccounting\Interface\BelongsToLedgerInterface;
use Wexample\SymfonyAccounting\Interface\LedgerGroupResolverInterface;

/**
 * Who may view, edit or administer a ledger, or anything it holds: the role
 * configured for the permission, else the grant configured for it on the
 * group the application ties the ledger to. Neither configured nor tied:
 * refused.
 *
 *     wexample_symfony_accounting:
 *         access:
 *             roles: { view: ROLE_ACCOUNTANT, edit: ROLE_ACCOUNTANT, administer: ROLE_ADMIN }
 *             grants: { view: client, edit: accountant, administer: administrator }
 *
 * @extends Voter<string, Ledger|BelongsToLedgerInterface>
 */
class LedgerVoter extends Voter
{
    /**
     * @param iterable<LedgerGroupResolverInterface> $groupResolvers the application's, implementing the interface is enough; the first one answering wins
     * @param array<string, ?string>                 $roles          by permission key
     * @param array<string, ?string>                 $grants         by permission key
     */
    public function __construct(
        private readonly AccessDecisionManagerInterface $accessDecisionManager,
        #[AutowireIterator('wexample_symfony_accounting.ledger_group_resolver')]
        private readonly iterable $groupResolvers = [],
        #[Autowire(param: 'wexample_symfony_accounting.access.roles')]
        private readonly array $roles = [],
        #[Autowire(param: 'wexample_symfony_accounting.access.grants')]
        private readonly array $grants = [],
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return LedgerPermission::tryFrom($attribute) !== null
            && ($subject instanceof Ledger || $subject instanceof BelongsToLedgerInterface);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $key = LedgerPermission::from($attribute)->getKey();
        $ledger = $subject instanceof Ledger ? $subject : $subject->getLedger();

        if (($role = $this->roles[$key] ?? null) && $this->accessDecisionManager->decide($token, [$role])) {
            return true;
        }

        $grant = $this->grants[$key] ?? null;
        $group = $grant ? $this->groupOf($ledger) : null;

        if ($group === null) {
            $vote?->addReason(sprintf('%s needs %s.', $attribute, $this->roles[$key] ?? 'a role or a grant to be configured'));

            return false;
        }

        return $this->accessDecisionManager->decide($token, [$grant], $group);
    }

    private function groupOf(Ledger $ledger): ?object
    {
        foreach ($this->groupResolvers as $resolver) {
            if ($group = $resolver->getGroup($ledger)) {
                return $group;
            }
        }

        return null;
    }
}
