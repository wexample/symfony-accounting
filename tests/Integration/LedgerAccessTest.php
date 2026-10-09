<?php

namespace Wexample\SymfonyAccounting\Tests\Integration;

use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Enum\LedgerPermission;
use Wexample\SymfonyAccounting\Service\Ledger\LedgerAccessService;
use Wexample\SymfonyAccounting\Tests\Fixtures\App\Access\FirmGroup;
use Wexample\SymfonyAccounting\Tests\Fixtures\App\Access\FirmGroupResolver;

class LedgerAccessTest extends AbstractAccountingTestCase
{
    private Ledger $acme;

    private Ledger $globex;

    protected function setUp(): void
    {
        parent::setUp();

        FirmGroupResolver::$groups = [
            'firm:acme' => new FirmGroup('acme', [
                'paula' => ['partner', 'accountant', 'client'],
                'anna' => ['accountant', 'client'],
                'chris' => ['client'],
            ]),
        ];

        $this->acme = $this->createLedger()->setExternalReference('firm:acme');
        $this->globex = $this->createLedger()->setExternalReference('firm:globex');
        $this->em()->flush();
    }

    public function testAnAdministratorReachesEveryLedgerByRole(): void
    {
        $this->signIn('root', ['ROLE_ADMIN']);

        foreach (LedgerPermission::cases() as $permission) {
            $this->assertTrue($this->isGranted($permission, $this->acme));
            $this->assertTrue($this->isGranted($permission, $this->globex));
        }
    }

    public function testTheGrantsHeldOnTheLedgersGroupDecide(): void
    {
        $this->signIn('anna');
        $this->assertTrue($this->isGranted(LedgerPermission::VIEW, $this->acme));
        $this->assertTrue($this->isGranted(LedgerPermission::EDIT, $this->acme));
        $this->assertFalse($this->isGranted(LedgerPermission::ADMINISTER, $this->acme));
        $this->assertFalse($this->isGranted(LedgerPermission::VIEW, $this->globex), 'A ledger of another group.');

        $this->signIn('chris');
        $this->assertTrue($this->isGranted(LedgerPermission::VIEW, $this->acme));
        $this->assertFalse($this->isGranted(LedgerPermission::EDIT, $this->acme));

        $this->signIn('paula');
        $this->assertTrue($this->isGranted(LedgerPermission::ADMINISTER, $this->acme));

        $this->signIn('stranger');
        $this->assertFalse($this->isGranted(LedgerPermission::VIEW, $this->acme));
    }

    public function testWhatALedgerHoldsFollowsItsLedger(): void
    {
        $party = $this->createParty($this->acme);

        $this->signIn('chris');
        $this->assertTrue($this->isGranted(LedgerPermission::VIEW, $party));
        $this->assertFalse($this->isGranted(LedgerPermission::EDIT, $party));
        $this->assertFalse($this->isGranted(LedgerPermission::VIEW, $this->createParty($this->globex)));
    }

    public function testListsOnlyTheLedgersOneMayReach(): void
    {
        $this->signIn('anna');
        $this->assertSame([$this->acme], $this->service(LedgerAccessService::class)->getAccessible());
        $this->assertSame([], $this->service(LedgerAccessService::class)->getAccessible(LedgerPermission::ADMINISTER));

        $this->signIn('root', ['ROLE_ADMIN']);
        $this->assertCount(2, $this->service(LedgerAccessService::class)->getAccessible());
    }

    /**
     * @param list<string> $roles
     */
    private function signIn(string $identifier, array $roles = ['ROLE_USER']): void
    {
        $this->service('security.token_storage')->setToken(
            new UsernamePasswordToken(new InMemoryUser($identifier, null, $roles), 'main', $roles)
        );
    }

    private function isGranted(LedgerPermission $permission, object $subject): bool
    {
        return $this->service('security.authorization_checker')->isGranted($permission->value, $subject);
    }
}
