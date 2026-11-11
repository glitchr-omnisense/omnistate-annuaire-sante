# Changelog

## Unreleased (1.x)

### Added
- `policy.json`, the register's usage policy in omnisense/contracts' schema (named in
  `composer.json`, `extra.omnisense.policy`), given by `usagePolicy()` (`DeclaresUsagePolicyInterface`):
  limits 17 a second per key; the rights unknown until the terms are read again. Requires
  `omnisense/contracts`.
- A 429 is thrown with `$status` 429: through the facade, the next calls wait for its `retryAfter`.
- PHPStan at level max, its baseline what the package had on 2026-11-10.

### Fixed
- A resource whose fields are not of FHIR's shapes (a list or an object where a string belongs, a
  string where a list or an object belongs, where PHP threw a `TypeError` or an `Error`) is an
  `UnavailableException`, the family's rule on a body that is not what it should be.
- An unreachable Annuaire Santé no longer carries the key: the `UnavailableException` wrapped the HTTP
  client's own exception, whose trace kept the request's headers in its arguments
  (`zend.exception_ignore_args` off). The previous is now a copy of it (the same class and code, the
  key masked in its message) and no trace of the chain keeps its arguments. Needs
  omnisense/omnistate's `Http\Redaction`.
- A 429's `Retry-After` written as an HTTP date gives the seconds until that date (at least 1),
  where it was cast to 0.
