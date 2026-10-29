# Changelog

## Unreleased (1.x)

### Fixed
- An unreachable Annuaire Santé no longer carries the key: the `UnavailableException` wrapped the HTTP
  client's own exception, whose trace kept the request's headers in its arguments
  (`zend.exception_ignore_args` off). The previous is now a copy of it (the same class and code, the
  key masked in its message) and no trace of the chain keeps its arguments. Needs
  omnisense/omnistate's `Http\Redaction`.
- A 429's `Retry-After` written as an HTTP date gives the seconds until that date (at least 1),
  where it was cast to 0.
