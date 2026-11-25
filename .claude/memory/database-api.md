# Database API (joomla/database)

## Build queries with `createQuery()`, and give `setQuery()` only query objects

Get a query object with `$db->createQuery()`, never `$db->getQuery(true)`. To run raw SQL, wrap it:
`$db->setQuery($db->createQuery()->setQuery($sql))`, never `$db->setQuery($sql)`.

**Why:** `getQuery(true)` is deprecated since joomla/database 2.2 and goes away in 4.0. `DatabaseDriver::setQuery()`
given a string calls `getQuery(true)` itself, so passing a string reaches the deprecated API indirectly. The library
reports the deprecation with a silenced `trigger_error()`, so neither PHPUnit nor the console shows it.

**How to apply:** two tests guard this. `tests/Unit/Database/QueryApiTest.php` scans `src/` for `getQuery(true)`.
`tests/Integration/Command/QueryApiRuntimeTest.php` runs the commands and fails on any deprecated call made from `src/`,
including a string passed to `setQuery()`; extend it when adding a command. joomla/database still makes the same
deprecated call internally from `insertObject()` (via `SqliteDriver::getTableColumns()`); that is upstream, and the
runtime test ignores it. Tests may keep passing SQL strings to `setQuery()` for seeding.
