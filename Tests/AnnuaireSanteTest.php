<?php

namespace Omnistate\AnnuaireSante\Tests;

use Omnistate\AnnuaireSante\AnnuaireSante;
use Omnistate\Exception\UnavailableException;
use Omnistate\Omnistate;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * On the examples of the ANS's implementation guide (interop.esante.gouv.fr/ig/fhir/annuaire),
 * put in search Bundles as the API v2 answers them; the practitioners' identities (name,
 * RPPS, telecoms, address, birth date) replaced by invented ones, Strasbourg and Colmar.
 */
final class AnnuaireSanteTest extends TestCase
{
    public function testAPhysicianByRpps(): void
    {
        $requests = [];
        $professional = $this->registry('practitioner-physician.json', $requests)->professional('100 0000 0017');
        self::assertNotNull($professional);

        self::assertSame('10000000017', $professional->identifier);
        self::assertSame('Jean Exemple', $professional->name());
        self::assertSame('M', $professional->prefix);
        self::assertSame('10', $professional->profession?->code);
        self::assertSame('Médecin', (string) $professional->profession);
        self::assertSame('DE05', $professional->diplomas[0]->code);
        self::assertSame(['fr'], $professional->languages);
        self::assertSame(['0300000000'], $professional->phones);
        self::assertSame('CABINET EXEMPLE', $professional->workplaces[0]->name);
        self::assertSame('liberal', $professional->workplaces[0]->mode);
        self::assertTrue($professional->workplaces[0]->active);
        self::assertSame('annuaire-sante', $professional->source);

        [$method, $url, $options] = $requests[0];
        self::assertSame('GET', $method);
        self::assertStringStartsWith('https://gateway.api.esante.gouv.fr/fhir/v2/Practitioner?', $url);
        self::assertStringContainsString('identifier=10000000017', $url);
        self::assertStringContainsString('_revinclude=PractitionerRole:practitioner', $url);
        self::assertIsArray($options['headers']);
        self::assertContains('ESANTE-API-KEY: test-key', $options['headers']);
    }

    public function testTheIdnpsFormIsTheSameProfessional(): void
    {
        self::assertSame('10000000017', $this->registry('practitioner-physician.json')->professional('810000000017')?->identifier);
    }

    public function testAPharmacistsMssanteAddressIsTold(): void
    {
        $professional = $this->registry('practitioner-pharmacist.json')->professional('10000000025');
        self::assertNotNull($professional);

        self::assertSame('Paul EXEMPLE', $professional->name());
        self::assertSame(['paul.exemple@exemple.mssante.fr'], $professional->secureEmails, 'his own MSSanté box');
        self::assertSame(['pharmacie@exemple.mssante.fr'], $professional->workplaces[0]->secureEmails, 'his workplace\'s');
        self::assertSame(['paul.exemple@example.org'], $professional->emails);
        self::assertSame('PHARMACIE NOLOT', $professional->workplaces[0]->name);
        self::assertSame('580008803', $professional->workplaces[0]->facility, 'the FINESS of the legal entity');
    }

    public function testSomeoneElsesAnswerIsNotTheirs(): void
    {
        self::assertNull($this->registry('practitioner-physician.json')->professional('10000000025'));
        self::assertNull($this->registry('empty.json')->professional('10000000017'));
    }

    public function testASearchByNameNarrowedToAPostcode(): void
    {
        $registry = $this->registry('search.json');

        self::assertCount(2, $registry->search('a'));
        $inStrasbourg = $registry->search('a', '67');
        self::assertCount(1, $inStrasbourg);
        self::assertSame('Jean Exemple', $inStrasbourg[0]->name());
        self::assertSame('STRASBOURG', $inStrasbourg[0]->workplaces[0]->address?->city);
        self::assertSame('67000', $inStrasbourg[0]->workplaces[0]->address->postalCode);
        self::assertSame([], $registry->search('  '));
    }

    public function testAFacilityByFiness(): void
    {
        $facility = $this->registry('organization.json')->facility('754 567 860');
        self::assertNotNull($facility);

        self::assertSame('754567860', $facility->identifier);
        self::assertSame('CH EURE-SEINE', $facility->name);
        self::assertSame('site', $facility->kind, 'a FINESS géographique');
        self::assertSame('96 R DIDOT', $facility->address?->street);
        self::assertSame('PARIS', $facility->address->city);
        self::assertSame(['0450636363'], $facility->phones);
        self::assertSame(['SA01', '86.10Z'], array_map(static fn ($type) => $type->code, $facility->types));
    }

    public function testWithoutAKeyNothingIsAsked(): void
    {
        $asked = false;
        $registry = new AnnuaireSante(new MockHttpClient(function () use (&$asked) {
            $asked = true;

            return new MockResponse('{}');
        }));

        $this->expectException(UnavailableException::class);
        try {
            $registry->professional('10000000017');
        } finally {
            self::assertFalse($asked);
        }
    }

    public function testTooManyCallsSaysWhenToComeBack(): void
    {
        $registry = new AnnuaireSante(new MockHttpClient(new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After: 2']])), 'test-key');

        try {
            $registry->professional('10000000017');
            self::fail('no exception');
        } catch (UnavailableException $e) {
            self::assertSame(2, $e->retryAfter);
        }
    }

    /** A Retry-After may be an HTTP date (RFC 9110, 10.2.3): the seconds until then, at least 1. */
    public function testTooManyCallsWithARetryAfterDate(): void
    {
        $registry = new AnnuaireSante(new MockHttpClient(new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After: '.gmdate('D, d M Y H:i:s \\G\\M\\T', time() + 90)]])), 'test-key');

        try {
            $registry->professional('10000000017');
            self::fail('no exception');
        } catch (UnavailableException $e) {
            self::assertGreaterThanOrEqual(85, $e->retryAfter);
            self::assertLessThanOrEqual(90, $e->retryAfter);
        }
    }

    public function testThroughOmnistate(): void
    {
        $omnistate = new Omnistate(professionals: [$this->registry('practitioner-physician.json')]);

        self::assertSame('Médecin', (string) $omnistate->professional('10000000017')?->profession);
        self::assertTrue($this->registry('organization.json')->supportsFacility('2A0001234'), 'Corsica');
    }

    /** A resource whose fields are not of FHIR's shapes is the register's fault: unavailable, as the family's rule has it. */
    public function testAResourceOfAnotherShapeIsUnavailable(): void
    {
        $rpps = ['system' => 'https://rpps.esante.gouv.fr', 'value' => '10000000017'];
        $shapes = [
            'a name that is not a list' => ['resourceType' => 'Practitioner', 'identifier' => [$rpps], 'name' => 'Exemple'],
            'a telecom that is not a list' => ['resourceType' => 'Practitioner', 'identifier' => [$rpps], 'telecom' => '0300000000'],
            'an identifier value that is an object' => ['resourceType' => 'Practitioner', 'identifier' => [['value' => ['10000000017']] + $rpps]],
        ];
        foreach ($shapes as $shape => $practitioner) {
            $registry = new AnnuaireSante(new MockHttpClient(new MockResponse(json_encode($practitioner, \JSON_THROW_ON_ERROR))), 'test-key');
            try {
                $registry->professional('10000000017');
                self::fail($shape.': answered');
            } catch (UnavailableException $e) {
                self::assertStringContainsString('another shape', $e->getMessage(), $shape);
            }
        }
    }

    /**
     * @param list<array{string, string, array<mixed>}>|null $requests
     *
     * @param-out list<array{string, string, array<mixed>}> $requests
     */
    private function registry(string $fixture, ?array &$requests = null): AnnuaireSante
    {
        $requests = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($fixture, &$requests) {
            $requests[] = [$method, $url, $options];

            return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$fixture), ['response_headers' => ['Content-Type: application/fhir+json']]);
        });

        return new AnnuaireSante($client, 'test-key');
    }

    /**
     * Not reached: nothing of the credential anywhere in what a log of the whole chain writes - the
     * messages, the previous exception (a copy of the HTTP client's, its class kept, rather than the
     * client's own, whose trace kept the request's headers), the traces' arguments when
     * zend.exception_ignore_args is off.
     */
    public function testNoPartOfAnUnreachableChainHoldsTheCredential(): void
    {
        $ignoreArgs = ini_set('zend.exception_ignore_args', '0');
        $http = new \Symfony\Component\HttpClient\MockHttpClient(static function (string $method, string $url): never {
            throw new \Symfony\Component\HttpClient\Exception\TransportException(\sprintf('Could not resolve host: gateway.api.esante.gouv.fr for "%s".', $url));
        });
        try {
            (new AnnuaireSante($http, 'sante-key-1234567'))->search('Martin');
            self::fail('no exception');
        } catch (\Omnistate\Exception\UnavailableException $e) {
            self::assertInstanceOf(\Symfony\Component\HttpClient\Exception\TransportException::class, $e->getPrevious());
            for ($one = $e; null !== $one; $one = $one->getPrevious()) {
                self::assertFalse(str_contains($one->getMessage(), 'sante-key-1234567'), $one->getMessage());
                self::assertFalse(str_contains(print_r($one->getTrace(), true), 'sante-key-1234567'), 'a trace holds the credential');
            }
        } finally {
            ini_set('zend.exception_ignore_args', false !== $ignoreArgs ? $ignoreArgs : '1');
        }
    }
}
