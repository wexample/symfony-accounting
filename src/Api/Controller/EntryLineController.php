<?php

namespace Wexample\SymfonyAccounting\Api\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Wexample\SymfonyAccounting\Api\Dto\LetterLinesDto;
use Wexample\SymfonyAccounting\Entity\EntryLine;
use Wexample\SymfonyAccounting\Enum\LedgerPermission;
use Wexample\SymfonyAccounting\Service\Ledger\LetteringService;
use Wexample\SymfonyApi\Api\Attribute\ValidateRequestContent;
use Wexample\SymfonyApi\Api\Class\ApiResponse;
use Wexample\SymfonyHelpers\Controller\AbstractController;

/**
 * Lettering: lines of one account marked as settling each other, or no longer.
 */
#[Route(path: 'api/entry-line/', name: 'api_entry_line_')]
class EntryLineController extends AbstractAccountingApiController
{
    final public const string ROUTE_LETTER = 'letter';

    final public const string ROUTE_UNLETTER = 'unletter';

    #[Route(path: 'letter', name: self::ROUTE_LETTER, methods: [Request::METHOD_POST], options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[ValidateRequestContent(dto: LetterLinesDto::class, attributeName: 'letterLinesDto')]
    public function letter(
        LetterLinesDto $letterLinesDto,
        EntityManagerInterface $entityManager,
        LetteringService $letteringService,
    ): ApiResponse {
        $lines = array_map(fn (string $id) => $this->reachableLine($id, $entityManager), $letterLinesDto->lines);
        $letter = $this->withinTheRules(fn () => $letteringService->letterLines($lines));
        $entityManager->flush();

        return self::apiResponseSuccess(data: ['letter' => $letter]);
    }

    /**
     * Every line sharing the letter of this one, unlettered.
     */
    #[Route(path: 'unletter/{id}', name: self::ROUTE_UNLETTER, methods: [Request::METHOD_POST], options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    public function unletter(
        string $id,
        EntityManagerInterface $entityManager,
        LetteringService $letteringService,
    ): ApiResponse {
        $line = $this->reachableLine($id, $entityManager);

        if ($line->getLetter()) {
            $letteringService->unletter($line->getAccount(), $line->getLetter());
            $entityManager->flush();
        }

        return self::apiResponseSuccess();
    }

    private function reachableLine(
        string $id,
        EntityManagerInterface $entityManager
    ): EntryLine {
        $line = $entityManager->find(EntryLine::class, $id) ?? throw new NotFoundHttpException();
        $this->reachable($line->getEntry(), LedgerPermission::EDIT);

        return $line;
    }
}
