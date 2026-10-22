<?php

namespace Wexample\SymfonyAccounting\Tests\Fixtures\App\Vies;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * VIES as the tests need it: the numbers listed are valid, a German one
 * starting with 9 finds its state's register away, every other is invalid.
 */
class FakeVies extends MockHttpClient
{
    public const array VALID = ['NL123456789B01', 'BE0987654321'];

    public function __construct()
    {
        parent::__construct(function (string $method, string $url, array $options): MockResponse {
            $body = json_decode($options['body'], true);
            $number = $body['countryCode'].$body['vatNumber'];

            if (str_starts_with($number, 'DE9')) {
                return new MockResponse(json_encode(['actionSucceed' => false, 'errorWrappers' => [['error' => 'MS_UNAVAILABLE']]]));
            }

            return new MockResponse(json_encode([
                'countryCode' => $body['countryCode'],
                'vatNumber' => $body['vatNumber'],
                'requestDate' => '2026-10-13T10:00:00.000Z',
                'valid' => in_array($number, self::VALID, true),
                'name' => '---',
                'address' => '---',
            ]));
        });
    }
}
