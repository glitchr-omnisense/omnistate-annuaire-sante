<?php

namespace Omnistate\AnnuaireSante\Tests;

use Omnistate\AnnuaireSante\AnnuaireSante;
use Omnistate\Testing\RegistryContractTests;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Omnistate's conformance suite, on the register's recorded answers (Tests/Fixtures). */
final class AnnuaireSanteContractTest extends TestCase
{
    use RegistryContractTests;

    protected function registry(HttpClientInterface $http): object
    {
        return new AnnuaireSante($http, 'test-key');
    }

    protected function recordedAnswers(): array
    {
        return ['*' => static fn (string $method, string $url) => new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.match (true) {
            str_contains($url, '/Organization') => 'organization.json',
            str_contains($url, 'identifier=') => 'practitioner-physician.json',
            default => 'search.json',
        }), ['response_headers' => ['content-type' => 'application/json']])];
    }

    protected function searchQuery(): string
    {
        return 'a';
    }

    protected function facilityIdentifier(): string
    {
        return '754567860';
    }
}
