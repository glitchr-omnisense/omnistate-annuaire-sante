# omnistate/annuaire-sante

French health professionals and facilities for
[omniscient/omnistate](https://github.com/glitchr-studio/omnistate), from the
[Annuaire Santé](https://annuaire.sante.fr) of the Agence du numérique en santé (ANS), through its
[FHIR API](https://ansforge.github.io/annuaire-sante-fhir-documentation/) (version 2): a
professional by RPPS number - profession, specialties, diplomas, where they practise and how,
their MSSanté addresses - and a facility by FINESS number.

| Operation | Request |
|---|---|
| `professional($rpps)` | `GET /Practitioner?identifier={rpps}&_revinclude=PractitionerRole:practitioner&_include:iterate=PractitionerRole:organization` |
| `search($name, $postcode, $limit)` | `GET /Practitioner?name={name}&active=true&_count=…` with the same includes; the postcode filters on the workplaces' addresses |
| `facility($finess)` | `GET /Organization?identifier={finess}` |

on `https://gateway.api.esante.gouv.fr/fhir/v2` (the constant `AnnuaireSante::URL`, or the `url`
argument), with the headers `ESANTE-API-KEY` and `Accept: application/fhir+json`. There is no
sandbox: the API answers the real register.

## Installation

```sh
composer require omniscient/omnistate omnistate/annuaire-sante
```

Requires PHP 8.2, `omniscient/omnistate` (1.x) and `symfony/http-client` (6.4, 7 or 8). In a
Symfony application, `Omnistate\Bridge\Symfony\OmnistateBundle` registers it when it is installed
(tag `omnistate.professional_registry`):

```yaml
# config/packages/omnistate.yaml
omnistate:
    annuaire_sante:
        api_key: '%env(ESANTE_API_KEY)%'
        # url: https://gateway.api.esante.gouv.fr/fhir/v2
```

```dotenv
# .env.local - never committed
ESANTE_API_KEY=...
```

## Credentials

A key, free: an account on the ANS's [Gravitee portal](https://portal.api.esante.gouv.fr), and a
subscription to "API Annuaire Santé en libre accès". It goes in the `ESANTE-API-KEY` header; the
README gives 17 calls a second. There is no sandbox and no shared test key. Without a key, every
call throws `UnavailableException` before any request.

## Options

| Argument | Type | Default | |
|---|---|---|---|
| `http` | `HttpClientInterface` | required | the client to call with |
| `apiKey` | `?string` | `null` | the Gravitee key (marked `#[\SensitiveParameter]`); `null` or blank: every call is an `UnavailableException` |
| `timeout` | float | `10` | seconds the API has to answer |
| `url` | string | `https://gateway.api.esante.gouv.fr/fhir/v2` | the FHIR API's base address |

## Capabilities

| Contract / question | Supported |
|---|---|
| `ProfessionalRegistryInterface::professional()` | yes: eleven digits (an RPPS), or twelve starting with 8 (its IDNPS) |
| `ProfessionalRegistryInterface::search()` | yes: by name, optionally narrowed to a postcode |
| `FacilityRegistryInterface::facility()` | yes: a FINESS number (nine characters, 2A / 2B for Corsica) |
| companies, VAT, civil records, Internet | no |

`supports()` checks the shape (`Rpps::normalize()`), not the Luhn key; `supportsFacility()` the
same (`Finess::normalize()`). `name()` is `annuaire-sante`, the `source` of every model.

## Behaviour

**A professional.** The RPPS is normalized (an IDNPS gives its RPPS) and asked as `identifier`;
the answer's practitioner whose RPPS is that one is the professional, else `null`. Called
directly with something that is not an RPPS, `professional()` throws `\InvalidArgumentException`
(the facade checks `supports()` first).

**A search.** An empty name is `[]` without a request. `_count` is the limit, or 50 when a
postcode is given: the practitioners whose workplaces have a postal code starting with it are
kept (`67` keeps the Bas-Rhin), at most the limit. Only the first page of the answer is read.

**A facility.** The FINESS is normalized and asked as `identifier`; the organization whose FINESS
is that one is the facility, else `null`.

`professionals(array $bundle): array` reads any FHIR search `Bundle` fetched otherwise: each
practitioner with the roles that reference it and the organizations they reference.

**What is read**:

- **Practitioner**: the RPPS (identifier of type `RPPS`, or of system `https://rpps.esante.gouv.fr`;
  else the `IDNPS` minus its leading 8), the usual name (else the first) with its given names and
  prefix, the qualifications - the profession (nomenclature `TRE_G15-ProfessionSante`: 10
  Médecin, 21 Pharmacien, 40 Chirurgien-dentiste, 50 Sage-femme, 60 Infirmier, 70
  Masseur-kinésithérapeute... named from `AnnuaireSante::PROFESSIONS` when the answer gives no
  label), the diplomas (`TRE_R48`, `TRE_R49`, `TRE_R47`; the diploma's code, not its type's
  `TRE_R14`), the professional category (`TRE_R09`) left out, every other coded qualification as
  a specialty or know-how -, the telecoms (an e-mail marked `MSSANTE`, or under `.mssante.fr`, is
  a secure address), the languages, `active`, `meta.lastUpdated`.
- **PractitionerRole**: each is a `Workplace`: its organization's name, address, FINESS (else its
  id) and phones, the mode of practice (`TRE_R23`: L `liberal`, S `salaried`, B `volunteer`,
  another code as it is), the function (`TRE_R21`, its label else its code), the secure addresses
  of the role and the organization, active unless its period ended. A role's profession is used
  when the practitioner gives none, and its specialties added. Workplaces come active first.
- **Organization**: name, FINESS (`FINEG` a site, `FINEJ` the legal entity; `kind` `site` or
  `legal`), every identifier, types (but the `fr-core-cs-v2-3307` system), address (the street
  from the address's extensions when given, the postcode stripped from the city, the INSEE
  country code 99100 - or no country - read as FR), telecoms, `active`.

**Caching.** None of its own: the facade's cache keeps its answers.

## Example

Without a key at hand, this script runs the register on the ANS's answers recorded in
`Tests/Fixtures` (fictional people of the ANS's implementation guide), served by a
`MockHttpClient`; with a key, replace the client by `HttpClient::create()`.

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnistate\AnnuaireSante\AnnuaireSante;
use Omnistate\Omnistate;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

// The ANS's answers recorded in omnistate/annuaire-sante's Tests/Fixtures, in place of the API
$fixtures = __DIR__.'/vendor/omnistate/annuaire-sante/Tests/Fixtures/';
$http = new MockHttpClient(static fn (string $method, string $url) => new MockResponse((string) file_get_contents($fixtures.match (true) {
    str_contains($url, '/Organization') => 'organization.json',
    str_contains($url, 'identifier=') => 'practitioner-physician.json',
    default => 'search.json',
})));

$omnistate = new Omnistate(professionals: [new AnnuaireSante($http, 'a-key')]);

$professional = $omnistate->professional('810003461033');   // the IDNPS form: the same RPPS
printf("%s %s (%s), %s [%s %s]\n", $professional->prefix, $professional->name(), $professional->identifier, $professional->profession, $professional->profession?->system, $professional->profession?->code);
foreach ($professional->diplomas as $diploma) {
    printf("  diploma %s (%s)\n", $diploma, $diploma->code);
}
foreach ($professional->workplaces as $workplace) {
    printf("  works at %s, FINESS %s, %s, %s, %s\n", $workplace->name, $workplace->facility, $workplace->mode, $workplace->role, $workplace->active ? 'active' : 'ended');
}

$facility = $omnistate->facility('754 567 860');
printf("\n%s (%s, %s), %s\n", $facility->name, $facility->identifier, $facility->kind, $facility->address);

echo "\nSearch \"a\":\n";
foreach ($omnistate->professionals('a', limit: 5) as $found) {
    printf("  %s %-22s %s\n", $found->identifier, $found->name(), $found->profession);
}
```

```
M Arthur Saucier (10003461033), Médecin [TRE_G15-ProfessionSante 10]
  diploma DE05 (DE05)
  works at CABINET SAINT ANTOINE, FINESS 548812, liberal, FON-AU, active

CH EURE-SEINE (754567860, site), 96 R DIDOT 75014 PARIS

Search "a":
  10003461033 Arthur Saucier         Médecin
  10102727017 David CHATELIER        Pharmacien
```

(run on 2026-10-25 in omnistate's Docker harness, on the recorded answers, not against the ANS)

With the key, in plain PHP: `new AnnuaireSante(HttpClient::create(), getenv('ESANTE_API_KEY'))`.

## Errors

`UnavailableException` - none of them means "no such professional":

| What happens | Message |
|---|---|
| no key | `Annuaire Santé: no API key (ESANTE-API-KEY, from the ANS Gravitee portal).`, before any request |
| HTTP 401, 403 | `Annuaire Santé: the key was refused (403).` |
| HTTP 429 | `Annuaire Santé: too many calls (17 a second).`, `retryAfter` from `Retry-After`, else 1 |
| HTTP 5xx | `Annuaire Santé: the server answered 503.` |
| another 4xx but 404, no answer, a body that is not JSON | `Annuaire Santé did not answer: ...` |

> [!WARNING]
> Known defect: a `Retry-After` written as an HTTP date gives `retryAfter` 0 (`AnnuaireSante.php:515`), where it should be the seconds until that date.

HTTP 404 is an empty answer: `null` from a lookup, `[]` from a search.

## Verified, and not

Tested offline: `Tests/AnnuaireSanteTest.php` (a physician by RPPS, the IDNPS form, a
pharmacist's MSSanté address, another practitioner's answer not taken for theirs, a search
narrowed to a postcode, a facility by FINESS, nothing asked without a key, a 429 with its
`Retry-After`, through the facade) and `Tests/AnnuaireSanteContractTest.php`, omnistate's
conformance suite (professionals and facilities). The tests run on the examples of the ANS's
implementation guide (fictional people) put in search bundles.

Not verified: the request shape (the `_revinclude` / `_include:iterate` pair) follows the guide;
it has not been tried against the real API, no key being at hand.

Defect found in the code: `AnnuaireSante.php:515` - a `Retry-After` written as an HTTP date is
cast to an integer, `0`: `retryAfter` is then 0 instead of the seconds until that date.

## Responsibility

An independent, unofficial client, not affiliated with the ANS (Agence du Numérique en Santé).
Whoever runs it complies with the Annuaire Santé's terms ([access to the data and its
CGU](https://esante.gouv.fr/offres-services/annuaire-sante/acceder-aux-donnees)): see the README's
Responsibility section.
