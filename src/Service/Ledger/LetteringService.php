<?php

namespace Wexample\SymfonyAccounting\Service\Ledger;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Wexample\SymfonyAccounting\Entity\Account;
use Wexample\SymfonyAccounting\Entity\EntryLine;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyAccounting\Helper\LetterHelper;
use Wexample\SymfonyAccounting\Repository\AccountRepository;
use Wexample\SymfonyAccounting\Repository\AllocationRepository;
use Wexample\SymfonyAccounting\Repository\EntryLineRepository;
use Wexample\SymfonyAccounting\Service\Bank\AllocationAccountingService;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceAccountingService;

/**
 * Lettering: marking together the lines of a party account that cancel each
 * other out (documents and the payments settling them).
 *
 * Automatic lettering follows the links the books know: lines of a document,
 * of its write-off and of the allocations paying it are connected; payments
 * covering several documents connect those documents. Each connected group
 * whose lines sum to zero gets a letter; a group that no longer balances
 * (an allocation removed) loses its letter.
 */
class LetteringService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AccountRepository $accountRepository,
        private readonly EntryLineRepository $lineRepository,
        private readonly AllocationRepository $allocationRepository,
    ) {
    }

    /**
     * @return int The number of groups lettered.
     */
    public function letterLedger(
        Ledger $ledger,
        ?DateTimeImmutable $date = null
    ): int {
        $count = 0;

        foreach ($this->accountRepository->findByLedger($ledger) as $account) {
            if ($account->isLettrable()) {
                $count += $this->letterAccount($account, $date);
            }
        }

        $this->entityManager->flush();

        return $count;
    }

    public function letterAccount(
        Account $account,
        ?DateTimeImmutable $date = null
    ): int {
        $lines = $this->lineRepository->findForAccount($account->getLedger(), $account);
        $this->clearUnbalancedLetters($lines);
        $this->loadAllocations($lines);
        $count = 0;
        // The lines carrying each letter, and the highest one: the next letter
        // follows it, as LetterHelper::next() over every line would say,
        // without reading every line again for each group lettered.
        $used = [];
        foreach ($lines as $line) {
            $this->countLetter($used, $line->getLetter(), 1);
        }

        $byParty = [];
        foreach ($lines as $line) {
            $byParty[(string) $line->getParty()?->getId()][] = $line;
        }

        foreach ($byParty as $partyLines) {
            foreach ($this->buildGroups($partyLines) as $group) {
                if (count($group) < 2) {
                    continue;
                }

                if (0 !== array_sum(array_map(fn (EntryLine $l) => $l->getBalance(), $group))) {
                    // A payment removed or reversed: the group is open again.
                    foreach ($group as $line) {
                        $this->countLetter($used, $line->getLetter(), -1);
                        $line->setLetter(null);
                    }

                    continue;
                }

                $letters = array_unique(array_map(fn (EntryLine $l) => $l->getLetter(), $group));

                if (1 === count($letters) && null !== $letters[0]) {
                    continue;
                }

                $letter = LetterHelper::fromNumber(max([0, ...array_keys($used)]) + 1);
                $lettered = $date ?? $this->latestDate($group);

                foreach ($group as $line) {
                    $this->countLetter($used, $line->getLetter(), -1);
                    $line->setLetter($letter, $lettered);
                    $this->countLetter($used, $letter, 1);
                }

                ++$count;
            }
        }

        return $count;
    }

    /**
     * Letters lines chosen by hand. They must be on one account and sum to zero.
     *
     * @param list<EntryLine> $lines
     */
    public function letterLines(array $lines): string
    {
        if (count($lines) < 2) {
            throw new AccountingException('Lettering needs at least two lines.');
        }

        $account = $lines[0]->getAccount();

        foreach ($lines as $line) {
            if ($line->getAccount() !== $account) {
                throw new AccountingException('Lettered lines must be on the same account.');
            }
        }

        if (0 !== array_sum(array_map(fn (EntryLine $l) => $l->getBalance(), $lines))) {
            throw new AccountingException('Lettered lines must cancel each other out.');
        }

        $letter = LetterHelper::next(array_map(
            fn (EntryLine $l) => $l->getLetter(),
            $this->lineRepository->findForAccount($account->getLedger(), $account)
        ));

        foreach ($lines as $line) {
            $line->setLetter($letter, $this->latestDate($lines));
        }

        $this->entityManager->flush();

        return $letter;
    }

    public function unletter(
        Account $account,
        string $letter
    ): void {
        foreach ($this->lineRepository->findForAccount($account->getLedger(), $account) as $line) {
            if ($line->getLetter() === $letter) {
                $line->setLetter(null);
            }
        }

        $this->entityManager->flush();
    }

    /**
     * Connected components over the documents and bank lines the lines refer to.
     *
     * @param list<EntryLine> $lines
     * @return list<list<EntryLine>>
     */
    private function buildGroups(array $lines): array
    {
        $parent = [];
        $find = function (string $node) use (&$parent, &$find): string {
            $parent[$node] ??= $node;

            return $parent[$node] === $node ? $node : $parent[$node] = $find($parent[$node]);
        };
        $union = function (string $a, string $b) use (&$parent, $find): void {
            $parent[$find($a)] = $find($b);
        };

        $lineNodes = [];

        foreach ($lines as $index => $line) {
            $nodes = $this->nodesOf($line);
            $lineNodes[$index] = $nodes[0] ?? 'L:'.$line->getId();
            $find($lineNodes[$index]);

            foreach (array_slice($nodes, 1) as $node) {
                $union($nodes[0], $node);
            }
        }

        $groups = [];
        foreach ($lines as $index => $line) {
            $groups[$find($lineNodes[$index])][] = $line;
        }

        return array_values($groups);
    }

    /**
     * @return list<string>
     */
    private function nodesOf(EntryLine $line): array
    {
        $entry = $line->getEntry();
        $sourceId = (string) $entry->getSourceId();

        return match ($entry->getSourceType()) {
            InvoiceAccountingService::SOURCE, InvoiceAccountingService::SOURCE_WRITE_OFF => ['I:'.$sourceId],
            AllocationAccountingService::SOURCE_ALLOCATION => $this->allocationNodes($sourceId),
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    private function allocationNodes(string $allocationId): array
    {
        $allocation = Uuid::isValid($allocationId) ? $this->allocationRepository->find(Uuid::fromString($allocationId)) : null;

        if (! $allocation) {
            return [];
        }

        $nodes = [];
        if ($allocation->getInvoice()) {
            $nodes[] = 'I:'.$allocation->getInvoice()->getId();
        }
        $nodes[] = 'T:'.$allocation->getTransaction()->getId();

        return $nodes;
    }

    /**
     * @param array<int, int> $used letter number → lines carrying it
     */
    private function countLetter(
        array &$used,
        ?string $letter,
        int $lines
    ): void {
        if (null === $letter || '' === $letter) {
            return;
        }

        $number = LetterHelper::toNumber($letter);
        $used[$number] = ($used[$number] ?? 0) + $lines;

        if ($used[$number] <= 0) {
            unset($used[$number]);
        }
    }

    /**
     * The allocations the lines come from, read at once: each line asks for
     * its own afterwards, and finds it already loaded.
     *
     * @param list<EntryLine> $lines
     */
    private function loadAllocations(array $lines): void
    {
        $ids = [];

        foreach ($lines as $line) {
            $entry = $line->getEntry();

            if (AllocationAccountingService::SOURCE_ALLOCATION === $entry->getSourceType() && Uuid::isValid((string) $entry->getSourceId())) {
                $ids[(string) $entry->getSourceId()] = Uuid::fromString((string) $entry->getSourceId());
            }
        }

        if ([] !== $ids) {
            $this->allocationRepository->findByIds(array_values($ids));
        }
    }

    /**
     * @param list<EntryLine> $lines
     */
    private function clearUnbalancedLetters(array $lines): void
    {
        $byLetter = [];

        foreach ($lines as $line) {
            if ($line->getLetter()) {
                $byLetter[$line->getLetter()][] = $line;
            }
        }

        foreach ($byLetter as $group) {
            if (0 !== array_sum(array_map(fn (EntryLine $l) => $l->getBalance(), $group))) {
                foreach ($group as $line) {
                    $line->setLetter(null);
                }
            }
        }
    }

    /**
     * @param list<EntryLine> $lines
     */
    private function latestDate(array $lines): DateTimeImmutable
    {
        return max(array_map(fn (EntryLine $l) => $l->getEntry()->getDate(), $lines));
    }
}
