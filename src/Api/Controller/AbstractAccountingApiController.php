<?php

namespace Wexample\SymfonyAccounting\Api\Controller;

use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Enum\LedgerPermission;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyAccounting\Interface\BelongsToLedgerInterface;
use Wexample\SymfonyAccounting\Repository\LedgerRepository;
use Wexample\SymfonyApi\Api\Controller\AbstractApiController;

/**
 * What the endpoints of the books share. Each entity has its own, under its
 * name (`api/invoice/list`, `api/invoice/show/{id}`…), so the repository
 * generated from it calls it without being told where.
 *
 * A ledger, or anything in it, the reader may not reach does not exist for
 * them: 404, never 403 (LedgerVoter decides). A request breaking a rule of
 * the books is answered 400 with the rule's words.
 */
abstract class AbstractAccountingApiController extends AbstractApiController
{
    final public const string ROUTE_LIST = 'list';

    final public const string ROUTE_SHOW = 'show';

    final public const string ROUTE_CREATE = 'create';

    final public const string ROUTE_DELETE = 'delete';

    final public const string QUERY_OPTION_LEDGER = 'ledger';

    public function __construct(
        protected readonly LedgerRepository $ledgerRepository,
    ) {
    }

    /**
     * The ledger the request names (`?ledger=`), reachable with that permission.
     */
    protected function resolveLedger(
        Request $request,
        LedgerPermission $permission = LedgerPermission::VIEW
    ): Ledger {
        return $this->reachable(
            $this->ledgerRepository->find((string) self::getQueryOptionValue($request, self::QUERY_OPTION_LEDGER, '')),
            $permission
        );
    }

    /**
     * The list kept to the ledger the request names, `$field` holding it.
     */
    protected function ledgerScope(
        Request $request,
        QueryBuilder $query,
        string $field
    ): QueryBuilder {
        return $query->andWhere("$field = :ledger")
            ->setParameter('ledger', $this->resolveLedger($request)->getId(), UuidType::NAME);
    }

    /**
     * @template T of Ledger|BelongsToLedgerInterface
     * @param T|null $subject
     * @return T
     */
    protected function reachable(
        Ledger|BelongsToLedgerInterface|null $subject,
        LedgerPermission $permission = LedgerPermission::VIEW
    ): Ledger|BelongsToLedgerInterface {
        if (! $subject || ! $this->isGranted($permission->value, $subject)) {
            throw new NotFoundHttpException();
        }

        return $subject;
    }

    /**
     * Runs what the books may refuse, a refusal becoming the answer.
     *
     * @template T
     * @param callable(): T $action
     * @return T
     */
    protected function withinTheRules(callable $action): mixed
    {
        try {
            return $action();
        } catch (AccountingException $exception) {
            throw new BadRequestHttpException($exception->getMessage(), $exception);
        }
    }
}
