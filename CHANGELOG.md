# Changelog

## v1.0.2

- A route cache built before the package was installed no longer breaks the CP. The nav item
  pointed at a route missing from the cache ("Route [statamic.cp.audit-log.index] not
  defined"), which made every CP page return 500. The item now stays hidden until the routes
  are cached again.
- A config cache built before the package was installed no longer switches masking off. The
  package defaults (sensitive_patterns and the rest) are now merged at runtime, so they apply
  whether or not the config is cached.

## v1.0.1

- Fix: on Statamic 4, deleting an entry of a structured collection (one with a tree, like
  pages) lost its "deleted" record: the entry's URL can no longer be built once it is off the
  tree, and the error dropped the whole record. The URL is now recorded as empty instead.

## v1.0.0

- First release, extracted from elnokhba's in-app audit log. Statamic 4/5/6, Laravel 10-13.
