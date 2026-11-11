<?php

namespace Omnistate\AnnuaireSante;

use Omnisense\Contracts\Policy\DeclaresUsagePolicyInterface;
use Omnisense\Contracts\Policy\UsagePolicy;
use Omnistate\Exception\UnavailableException;
use Omnistate\Http\Redaction;
use Omnistate\Identifier\Finess;
use Omnistate\Identifier\Rpps;
use Omnistate\Model\Address;
use Omnistate\Model\Facility;
use Omnistate\Model\Professional;
use Omnistate\Model\Qualification;
use Omnistate\Model\Workplace;
use Omnistate\Registry\FacilityRegistryInterface;
use Omnistate\Registry\ProfessionalRegistryInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The Annuaire Santé of the Agence du numérique en santé, through its FHIR
 * API (version 2): health professionals by their RPPS number - profession,
 * specialties, diplomas, where they practise and how, their MSSanté
 * addresses - and health facilities by their FINESS number. Free, with a
 * key from the ANS's Gravitee portal (header ESANTE-API-KEY), 17 calls a
 * second. Production data only: the ANS has no sandbox with fictional people.
 *
 * Without a key every call throws UnavailableException: not knowing is not
 * "no such professional".
 */
final class AnnuaireSante implements ProfessionalRegistryInterface, FacilityRegistryInterface, DeclaresUsagePolicyInterface
{
    public const URL = 'https://gateway.api.esante.gouv.fr/fhir/v2';

    private const PROFESSION = 'TRE_G15-ProfessionSante';
    private const CATEGORY = 'TRE_R09-CategorieProfessionnelle';
    private const DIPLOMAS = ['TRE_R48-DiplomeEtatFrancais', 'TRE_R14-TypeDiplome', 'TRE_R49-DiplomeEtudesSpecialisees', 'TRE_R47-DiplomeUniversitaire'];
    private const MODE = 'TRE_R23-ModeExercice';
    private const ROLE = 'TRE_R21-Fonction';
    private const MSSANTE = 'MSSANTE';

    /** TRE_G15, the professions the register knows by code (the ones a practice meets). */
    public const PROFESSIONS = [
        '10' => 'Médecin',
        '21' => 'Pharmacien',
        '26' => 'Audioprothésiste',
        '28' => 'Opticien-lunetier',
        '40' => 'Chirurgien-dentiste',
        '50' => 'Sage-femme',
        '60' => 'Infirmier',
        '69' => 'Infirmier psychiatrique',
        '70' => 'Masseur-kinésithérapeute',
        '80' => 'Pédicure-podologue',
        '81' => 'Orthoprothésiste',
        '82' => 'Podo-orthésiste',
        '83' => 'Orthopédiste-orthésiste',
        '84' => 'Oculariste',
        '85' => 'Épithésiste',
        '86' => 'Technicien de laboratoire médical',
        '91' => 'Orthophoniste',
        '92' => 'Orthoptiste',
        '93' => 'Psychologue',
        '94' => 'Ergothérapeute',
        '95' => 'Diététicien',
        '96' => 'Psychomotricien',
        '98' => 'Manipulateur ERM',
    ];

    /** TRE_R23: how one practises. */
    private const MODES = ['L' => 'liberal', 'S' => 'salaried', 'B' => 'volunteer'];

    public function __construct(
        private readonly HttpClientInterface $http,
        #[\SensitiveParameter] private readonly ?string $apiKey = null,
        private readonly float $timeout = 10,
        private readonly string $url = self::URL,
    ) {
    }

    /** Its usage policy: policy.json, beside this class. */
    public function usagePolicy(): UsagePolicy
    {
        return UsagePolicy::fromFile(__DIR__.'/policy.json');
    }

    public function name(): string
    {
        return 'annuaire-sante';
    }

    public function supports(string $identifier): bool
    {
        return null !== Rpps::normalize($identifier);
    }

    public function supportsFacility(string $identifier): bool
    {
        return null !== Finess::normalize($identifier);
    }

    public function professional(string $identifier): ?Professional
    {
        $rpps = Rpps::normalize($identifier) ?? throw new \InvalidArgumentException(sprintf('"%s" is not an RPPS number.', $identifier));
        $bundle = $this->get('Practitioner', [
            'identifier' => $rpps,
            '_revinclude' => 'PractitionerRole:practitioner',
            '_include:iterate' => 'PractitionerRole:organization',
        ]);

        foreach ($this->professionals($bundle) as $professional) {
            if ($professional->identifier === $rpps) {
                return $professional;
            }
        }

        return null;
    }

    public function search(string $name, ?string $postcode = null, int $limit = 10): array
    {
        $name = trim($name);
        if ('' === $name) {
            return [];
        }
        $bundle = $this->get('Practitioner', [
            'name' => $name,
            'active' => 'true',
            '_count' => null === $postcode ? $limit : 50,
            '_revinclude' => 'PractitionerRole:practitioner',
            '_include:iterate' => 'PractitionerRole:organization',
        ]);

        $found = [];
        foreach ($this->professionals($bundle) as $professional) {
            if (null !== $postcode && !$this->practisesIn($professional, $postcode)) {
                continue;
            }
            $found[] = $professional;
            if (\count($found) >= $limit) {
                break;
            }
        }

        return $found;
    }

    public function facility(string $identifier): ?Facility
    {
        $finess = Finess::normalize($identifier) ?? throw new \InvalidArgumentException(sprintf('"%s" is not a FINESS number.', $identifier));
        $bundle = $this->get('Organization', ['identifier' => $finess]);

        foreach ($this->resources($bundle, 'Organization') as $organization) {
            $facility = $this->facilityOf($organization);
            if ($facility->identifier === $finess) {
                return $facility;
            }
        }

        return null;
    }

    /**
     * The professionals of a search answer, each with the roles and the
     * organizations it included.
     *
     * @param array<mixed> $bundle
     *
     * @return list<Professional>
     */
    public function professionals(array $bundle): array
    {
        $organizations = [];
        foreach ($this->resources($bundle, 'Organization') as $organization) {
            $organizations['Organization/'.self::cast($organization['id'] ?? '')] = $organization;
        }
        $roles = [];
        foreach ($this->resources($bundle, 'PractitionerRole') as $role) {
            $roles[self::string(self::at($role, 'practitioner', 'reference')) ?? ''][] = $role;
        }

        $professionals = [];
        foreach ($this->resources($bundle, 'Practitioner') as $practitioner) {
            $professional = $this->professionalOf($practitioner, $roles['Practitioner/'.self::cast($practitioner['id'] ?? '')] ?? [], $organizations);
            if (null !== $professional) {
                $professionals[] = $professional;
            }
        }

        return $professionals;
    }

    /**
     * @param array<mixed>                $practitioner
     * @param list<array<mixed>>          $roles
     * @param array<string, array<mixed>> $organizations
     */
    private function professionalOf(array $practitioner, array $roles, array $organizations): ?Professional
    {
        $rpps = null;
        foreach (self::items($practitioner['identifier'] ?? null) as $identifier) {
            $type = self::at($identifier, 'type', 'coding', 0, 'code');
            if ('RPPS' === $type || 'https://rpps.esante.gouv.fr' === self::at($identifier, 'system')) {
                $rpps = Rpps::normalize(self::string(self::at($identifier, 'value')));
                break;
            }
            if ('IDNPS' === $type) {
                $rpps ??= Rpps::normalize(self::string(self::at($identifier, 'value')));
            }
        }
        if (null === $rpps) {
            return null;
        }

        $name = $this->usualName(self::object($practitioner['name'] ?? null));
        $profession = null;
        $specialties = [];
        $diplomas = [];
        foreach (self::items($practitioner['qualification'] ?? null) as $qualification) {
            foreach (self::items(self::at($qualification, 'code', 'coding')) as $coding) {
                $coding = self::items($coding);
                $system = $this->system(self::string($coding['system'] ?? null) ?? '');
                if (self::PROFESSION === $system) {
                    $profession ??= $this->profession($coding);
                } elseif (self::CATEGORY === $system) {
                    continue;
                } elseif (\in_array($system, self::DIPLOMAS, true)) {
                    // The diploma's own code, not its type's ("DE05", not "DE").
                    if ('TRE_R14-TypeDiplome' !== $system) {
                        $diplomas[] = $this->qualification($coding);
                    }
                } elseif (isset($coding['code'])) {
                    $specialties[] = $this->qualification($coding);
                }
            }
        }

        $workplaces = [];
        foreach ($roles as $role) {
            foreach (self::items($role['code'] ?? null) as $code) {
                foreach (self::items(self::at($code, 'coding')) as $coding) {
                    $coding = self::items($coding);
                    if (null === $profession && self::PROFESSION === $this->system(self::string($coding['system'] ?? null) ?? '')) {
                        $profession = $this->profession($coding);
                    }
                }
            }
            foreach (self::items($role['specialty'] ?? null) as $specialty) {
                foreach (self::items(self::at($specialty, 'coding')) as $coding) {
                    $coding = self::items($coding);
                    $specialty = self::string($coding['code'] ?? null);
                    if (null !== $specialty && !$this->has($specialties, $specialty)) {
                        $specialties[] = $this->qualification($coding);
                    }
                }
            }
            $workplaces[] = $this->workplace($role, $organizations[self::string(self::at($role, 'organization', 'reference')) ?? ''] ?? null);
        }
        usort($workplaces, static fn (Workplace $a, Workplace $b) => $b->active <=> $a->active);

        [$phones, $emails, $secure] = $this->telecom(self::object($practitioner['telecom'] ?? null));
        foreach ($roles as $role) {
            [, , $roleSecure] = $this->telecom(self::object($role['telecom'] ?? null));
            $secure = array_values(array_unique([...$secure, ...$roleSecure]));
        }

        $languages = [];
        foreach (self::items($practitioner['communication'] ?? null) as $communication) {
            $language = self::string(self::at($communication, 'coding', 0, 'code'));
            if (null !== $language) {
                $languages[] = strtolower($language);
            }
        }

        return new Professional(
            identifier: $rpps,
            familyName: $name['family'],
            source: $this->name(),
            givenName: $name['given'],
            prefix: $name['prefix'],
            profession: $profession,
            specialties: $specialties,
            diplomas: $diplomas,
            workplaces: $workplaces,
            phones: $phones,
            emails: $emails,
            secureEmails: $secure,
            languages: $languages,
            active: (bool) ($practitioner['active'] ?? true),
            updatedAt: $this->date(self::string(self::at($practitioner, 'meta', 'lastUpdated'))),
            raw: $practitioner,
        );
    }

    /**
     * @param array<mixed>      $role
     * @param array<mixed>|null $organization
     */
    private function workplace(array $role, ?array $organization): Workplace
    {
        $mode = null;
        $function = null;
        foreach (self::items($role['code'] ?? null) as $code) {
            foreach (self::items(self::at($code, 'coding')) as $coding) {
                $coding = self::items($coding);
                $system = $this->system(self::string($coding['system'] ?? null) ?? '');
                if (self::MODE === $system) {
                    $modeCode = self::string($coding['code'] ?? null);
                    $mode = self::MODES[$modeCode ?? ''] ?? $modeCode;
                } elseif (self::ROLE === $system) {
                    $function = self::string($coding['display'] ?? $coding['code'] ?? null);
                }
            }
        }
        [$phones, , $secure] = $this->telecom([...self::object($organization['telecom'] ?? null), ...self::object($role['telecom'] ?? null)]);
        $address = self::at($organization, 'address', 0);

        return new Workplace(
            name: self::string($organization['name'] ?? null),
            address: null !== $address ? $this->address(self::object($address)) : null,
            facility: null !== $organization ? $this->finess($organization) ?? self::string($organization['id'] ?? null) : null,
            mode: $mode,
            role: $function,
            phones: $phones,
            secureEmails: $secure,
            active: (bool) ($role['active'] ?? true) && null === self::at($role, 'period', 'end'),
        );
    }

    /** @param array<mixed> $organization */
    private function facilityOf(array $organization): Facility
    {
        $kind = null;
        $identifiers = [];
        foreach (self::items($organization['identifier'] ?? null) as $identifier) {
            $type = self::at($identifier, 'type', 'coding', 0, 'code');
            if ('FINEG' === $type) {
                $kind = 'site';
            } elseif ('FINEJ' === $type) {
                $kind = 'legal';
            }
            $value = self::at($identifier, 'value');
            if (null !== $value) {
                $identifiers[] = self::cast($value);
            }
        }
        $types = [];
        foreach (self::items($organization['type'] ?? null) as $type) {
            foreach (self::items(self::at($type, 'coding')) as $coding) {
                $coding = self::items($coding);
                if (isset($coding['code']) && 'https://hl7.fr/ig/fhir/core/CodeSystem/fr-core-cs-v2-3307' !== ($coding['system'] ?? null)) {
                    $types[] = $this->qualification($coding);
                }
            }
        }
        [$phones, $emails, $secure] = $this->telecom(self::object($organization['telecom'] ?? null));
        $address = self::at($organization, 'address', 0);

        return new Facility(
            identifier: $this->finess($organization) ?? self::cast($organization['id'] ?? ''),
            name: self::cast($organization['name'] ?? ''),
            source: $this->name(),
            kind: $kind,
            types: $types,
            address: null !== $address ? $this->address(self::object($address)) : null,
            phones: $phones,
            emails: $emails,
            secureEmails: $secure,
            identifiers: $identifiers,
            active: (bool) ($organization['active'] ?? true),
            updatedAt: $this->date(self::string(self::at($organization, 'meta', 'lastUpdated'))),
            raw: $organization,
        );
    }

    /** @param array<mixed> $organization */
    private function finess(array $organization): ?string
    {
        foreach (self::items($organization['identifier'] ?? null) as $identifier) {
            if ('https://finess.esante.gouv.fr' === self::at($identifier, 'system') || \in_array(self::at($identifier, 'type', 'coding', 0, 'code'), ['FINEG', 'FINEJ'], true)) {
                return Finess::normalize(self::string(self::at($identifier, 'value')));
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $names
     *
     * @return array{family: string, given: ?string, prefix: ?string}
     */
    private function usualName(array $names): array
    {
        $chosen = $names[0] ?? [];
        foreach ($names as $name) {
            if ('usual' === self::at($name, 'use')) {
                $chosen = $name;
                break;
            }
        }
        $given = self::at($chosen, 'given');

        return [
            'family' => self::cast(self::at($chosen, 'family') ?? ''),
            'given' => null !== $given ? implode(' ', array_map(self::cast(...), self::object($given))) : null,
            'prefix' => self::string(self::at($chosen, 'prefix', 0)),
        ];
    }

    /**
     * @param array<mixed> $telecom
     *
     * @return array{list<string>, list<string>, list<string>} phones, e-mails, secure (MSSanté) e-mails
     */
    private function telecom(array $telecom): array
    {
        $phones = $emails = $secure = [];
        foreach ($telecom as $point) {
            $value = trim(self::cast(self::at($point, 'value') ?? ''));
            if ('' === $value) {
                continue;
            }
            $system = self::at($point, 'system');
            if ('phone' === $system) {
                $phones[] = $value;
            } elseif ('email' === $system) {
                $isSecure = str_ends_with(strtolower($value), '.mssante.fr');
                foreach (self::items(self::at($point, 'extension')) as $extension) {
                    if (self::MSSANTE === self::at($extension, 'valueCoding', 'code')) {
                        $isSecure = true;
                    }
                }
                if ($isSecure) {
                    $secure[] = $value;
                } else {
                    $emails[] = $value;
                }
            }
        }

        return [array_values(array_unique($phones)), array_values(array_unique($emails)), array_values(array_unique($secure))];
    }

    /** @param array<mixed> $address */
    private function address(array $address): Address
    {
        $street = null;
        $line = self::at($address, 'line', 0);
        if (!empty($line) && empty(self::at($address, '_line', 0, 'extension'))) {
            $street = self::cast($line);
        } else {
            $parts = [];
            foreach (self::items(self::at($address, '_line', 0, 'extension')) as $extension) {
                $url = self::cast(self::at($extension, 'url') ?? '');
                $key = substr($url, (int) strrpos($url, '-') + 1);
                if (\in_array($key, ['houseNumber', 'buildingNumberSuffix', 'streetNameType', 'streetNameBase'], true)) {
                    $parts[] = self::cast(self::at($extension, 'valueString'));
                }
            }
            $street = trim(implode(' ', array_filter($parts))) ?: (null !== $line ? self::cast($line) : null);
        }
        $postalCode = self::string($address['postalCode'] ?? null);
        $city = isset($address['city']) ? trim((string) preg_replace('/^\d{5}\s+/', '', self::cast($address['city']))) : null;

        return new Address(
            street: $street ?: null,
            postalCode: $postalCode,
            city: $city ?: null,
            // The INSEE country code: 99100 is France; none written means France too.
            country: match ($address['country'] ?? null) { null, '99100', 'FR', 'FRA' => 'FR', default => null },
        );
    }

    /** @param array<mixed> $coding */
    private function profession(array $coding): Qualification
    {
        $code = self::cast($coding['code'] ?? '');

        return new Qualification($code, self::PROFESSION, self::string($coding['display'] ?? null) ?? self::PROFESSIONS[$code] ?? null);
    }

    /** @param array<mixed> $coding */
    private function qualification(array $coding): Qualification
    {
        return new Qualification(self::cast($coding['code'] ?? null), $this->system(self::string($coding['system'] ?? null) ?? ''), self::string($coding['display'] ?? null));
    }

    /** ".../NOS/TRE_G15-ProfessionSante/FHIR/TRE-G15-ProfessionSante" -> "TRE_G15-ProfessionSante" */
    private function system(string $system): string
    {
        return preg_match('~/NOS/([^/]+)/~', $system, $match) ? $match[1] : $system;
    }

    /** @param list<Qualification> $qualifications */
    private function has(array $qualifications, string $code): bool
    {
        foreach ($qualifications as $qualification) {
            if ($qualification->code === $code) {
                return true;
            }
        }

        return false;
    }

    private function practisesIn(Professional $professional, string $postcode): bool
    {
        $postcode = trim($postcode);
        foreach ($professional->workplaces as $workplace) {
            if ($workplace->address?->postalCode && str_starts_with($workplace->address->postalCode, $postcode)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $bundle
     *
     * @return iterable<array<mixed>> the resources of that type in a search Bundle
     */
    private function resources(array $bundle, string $type): iterable
    {
        if (($bundle['resourceType'] ?? null) === $type) {
            yield $bundle;

            return;
        }
        foreach (self::items($bundle['entry'] ?? null) as $entry) {
            $resource = self::at($entry, 'resource');
            if (\is_array($resource) && ($resource['resourceType'] ?? null) === $type) {
                yield $resource;
            }
        }
    }

    private function date(?string $date): ?\DateTimeImmutable
    {
        try {
            return null === $date ? null : new \DateTimeImmutable($date);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @param array<string, scalar> $query
     *
     * @return array<mixed>
     */
    private function get(string $resource, array $query): array
    {
        if (null === $this->apiKey || '' === trim($this->apiKey)) {
            throw new UnavailableException('Annuaire Santé: no API key (ESANTE-API-KEY, from the ANS Gravitee portal).');
        }

        try {
            $response = $this->http->request('GET', rtrim($this->url, '/').'/'.$resource, [
                'query' => $query,
                'headers' => ['ESANTE-API-KEY' => $this->apiKey, 'Accept' => 'application/fhir+json'],
                'timeout' => $this->timeout,
            ]);
            $status = $response->getStatusCode();
            if (429 === $status) {
                $retry = $response->getHeaders(false)['retry-after'][0] ?? null;

                throw new UnavailableException('Annuaire Santé: too many calls (17 a second).', self::retryAfter($retry), null, 429);
            }
            if (401 === $status || 403 === $status) {
                throw new UnavailableException(sprintf('Annuaire Santé: the key was refused (%d).', $status));
            }
            if ($status >= 500) {
                throw new UnavailableException(sprintf('Annuaire Santé: the server answered %d.', $status));
            }
            if (404 === $status) {
                return [];
            }

            return $response->toArray();
        } catch (ExceptionInterface $e) {
            // The key, in the request's headers, kept out: the previous a copy of the client's exception, no trace keeping the options.
            $secrets = array_filter([(string) $this->apiKey]);

            throw Redaction::withoutArguments(new UnavailableException('Annuaire Santé did not answer: '.Redaction::text($e->getMessage(), $secrets), null, Redaction::copy($e, $secrets)));
        }
    }

    /**
     * A FHIR string, as PHP handed it to a string parameter: null, a string, a
     * number or a boolean written as one; an object or a list where a string
     * belongs is an answer of another shape.
     *
     * @throws UnavailableException
     */
    private static function string(mixed $value): ?string
    {
        return match (true) {
            null === $value, \is_string($value) => $value,
            \is_int($value), \is_float($value) => (string) $value,
            \is_bool($value) => $value ? '1' : '',
            default => throw self::malformed(),
        };
    }

    /**
     * A FHIR object or list, as PHP handed it to an array parameter: [] when
     * absent; anything else where one belongs is an answer of another shape.
     *
     * @return array<mixed>
     *
     * @throws UnavailableException
     */
    private static function object(mixed $value): array
    {
        return match (true) {
            null === $value => [],
            \is_array($value) => $value,
            default => throw self::malformed(),
        };
    }

    /**
     * $value[$key][...] ?? null, as PHP reads it: an array's item, a string's
     * character at an integer offset, null for anything else.
     */
    private static function at(mixed $value, string|int ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (\is_array($value)) {
                $value = $value[$key] ?? null;
            } elseif (\is_string($value) && \is_int($key)) {
                $value = $value[$key] ?? null;
            } else {
                return null;
            }
        }

        return $value;
    }

    /**
     * What a foreach over the answer's value goes through: the array, nothing
     * for anything else (PHP warned and went through nothing).
     *
     * @return array<mixed>
     */
    private static function items(mixed $value): array
    {
        return \is_array($value) ? $value : [];
    }

    /** (string) of a scalar, as the answer's values were cast: "" for null, and for an array where PHP gave "Array". */
    private static function cast(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    /** The family's rule on a body that is not what it should be (docs/providers.md). */
    private static function malformed(): UnavailableException
    {
        return new UnavailableException('Annuaire Santé answered a resource of another shape than FHIR\'s.');
    }

    /** Retry-After in seconds or as an HTTP date (RFC 9110, 10.2.3), at least 1; 1 s when there is none to read. */
    private static function retryAfter(?string $header): int
    {
        $header = trim((string) $header);
        if (is_numeric($header)) {
            return max(1, (int) $header);
        }
        $date = '' === $header ? false : strtotime($header);

        return false === $date ? 1 : max(1, $date - time());
    }
}
