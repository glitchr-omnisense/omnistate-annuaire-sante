# omnistate/annuaire-sante

French health professionals and facilities from the [Annuaire Santé](https://annuaire.sante.fr) of the
Agence du numérique en santé, through its [FHIR API](https://ansforge.github.io/annuaire-sante-fhir-documentation/)
(version 2): a professional by RPPS number - profession, specialties, diplomas, where they practise and
how, their MSSanté addresses - and a facility by FINESS number. For [omnisense/omnistate](https://github.com/glitchr-omnisense/omnistate).

```php
$registry = new AnnuaireSante($httpClient, $_ENV['ESANTE_API_KEY']);
$professional = $registry->professional('10003461033');      // or its IDNPS, 810003461033
$professional->name(), $professional->profession /* Médecin */, $professional->specialties,
$professional->workplaces /* name, address, FINESS, liberal|salaried */, $professional->secureEmails;
$registry->search('Martin', postcode: '67');
$registry->facility('580008803');
```

No framework needed: the package requires `omnisense/omnistate` and `symfony/http-client`.
`$httpClient` is the HTTP client to call with - the application's, `HttpClient::create()` in
plain PHP, a `MockHttpClient` in a test.

The key is free: an account on the ANS's [Gravitee portal](https://portal.api.esante.gouv.fr), a
subscription to "API Annuaire Santé en libre accès"; it goes in the `ESANTE-API-KEY` header. 17 calls a
second. There is no sandbox: the API answers the real register. Without a key, every call throws
`UnavailableException`.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by the ANS (Agence du Numérique en Santé). It reaches their service on behalf of whoever
runs it, and it does not redistribute their data beyond the short excerpts recorded as test
fixtures. Its MIT licence covers this package's code only and grants no right to the service or to
its data.

Whoever installs and runs this package is responsible for complying with the Annuaire Santé's terms
([access to the data and its
CGU](https://esante.gouv.fr/offres-services/annuaire-sante/acceder-aux-donnees)), for obtaining
whatever they require (an account, a key, a licence or subscription, an attribution or a citation, a
rate limit to respect), and for any use made of what it returns. The authors publish it free of
charge, make no commercial use of it, and accept no liability for how it is used (see
[LICENSE](LICENSE)).

Documentation: [docs/](docs/index.md). License: MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.
