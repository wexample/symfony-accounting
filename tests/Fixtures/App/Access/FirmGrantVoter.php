<?php

namespace Wexample\SymfonyAccounting\Tests\Fixtures\App\Access;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * What symfony-user's memberships do in an application: a grant held on a
 * group.
 *
 * @extends Voter<string, FirmGroup>
 */
final class FirmGrantVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof FirmGroup;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        return in_array($attribute, $subject->grants[$token->getUserIdentifier()] ?? [], true);
    }
}
