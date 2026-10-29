# Changelog

## Unreleased (1.x)

### Fixed
- A 429's `Retry-After` written as an HTTP date gives the seconds until that date (at least 1),
  where it was cast to 0.
