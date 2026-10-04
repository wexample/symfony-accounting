<?php

namespace Wexample\SymfonyAccounting\Service\Document;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Wexample\PhpRemote\Class\RemoteStatus;
use Wexample\PhpRemote\Interface\RemoteInterface;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyAccounting\Interface\DocumentRendererInterface;

/**
 * Posts documents to a pdf-factory service (POST /render) and gets the PDF back.
 * Its health (GET /health) is checked as a remote.
 */
class PdfFactoryRenderer implements DocumentRendererInterface, RemoteInterface
{
    public function __construct(
        private readonly ?HttpClientInterface $httpClient = null,
        private readonly ?string $baseUrl = null,
        private readonly ?array $defaultTheme = null,
    ) {
    }

    public function getKey(): string
    {
        return 'pdf_factory';
    }

    public function getLabel(): string
    {
        return 'PDF factory';
    }

    public function checkStatus(): RemoteStatus
    {
        if (! $this->httpClient || ! $this->baseUrl) {
            return RemoteStatus::unconfigured('No pdf-factory URL.');
        }

        $response = $this->httpClient->request('GET', rtrim($this->baseUrl, '/').'/health');

        return 200 === $response->getStatusCode() ? RemoteStatus::up() : RemoteStatus::down('HTTP '.$response->getStatusCode());
    }

    public function render(
        array $document,
        ?array $theme = null
    ): string {
        if (! $this->httpClient || ! $this->baseUrl) {
            throw new AccountingException('pdf-factory is not configured (wexample_symfony_accounting.pdf_factory.url).');
        }

        $payload = ['document' => $document];
        if ($theme ??= $this->defaultTheme) {
            $payload['theme'] = $theme;
        }

        $response = $this->httpClient->request('POST', rtrim($this->baseUrl, '/').'/render', ['json' => $payload]);

        if (200 !== $response->getStatusCode()) {
            throw new AccountingException('pdf-factory refused the document: '.$response->getContent(false));
        }

        return $response->getContent();
    }
}
