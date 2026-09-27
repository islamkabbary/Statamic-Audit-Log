# Changelog

## v1.0.1

- Fix: on Statamic 4, deleting an entry of a structured collection (one with a tree, like
  pages) lost its "deleted" record: the entry's URL can no longer be built once it is off the
  tree, and the error dropped the whole record. The URL is now recorded as empty instead.

## v1.0.0

- First release, extracted from elnokhba's in-app audit log. Statamic 4/5/6, Laravel 10-13.
