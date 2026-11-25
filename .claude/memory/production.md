# Production deployment

## The nightly run and where its output goes

The production server (a VM on a commercial host, clock on GMT) runs
`php coresums.php sources --latest --process --dump=<published folder>` daily at 00:00 GMT. Its output goes to
`/dev/null`; nothing is logged or emailed. The dump folder is served directly as `https://getpanopticon.com/checksums/`,
behind Cloudflare: 200 responses carry `Cache-Control: public, max-age=31536000`, 404s are `private`.

**Why:** these facts are not in the repository, and they decide how to diagnose a missed release (GitHub issue #2).

**How to apply:** to reconstruct what a run did without logs, read the `Last-Modified` header of each published file
(`<cms>/<version>/<type>.json.gz`, `sources.json.gz`): it is the time the dump wrote that file. A published file is
cached for a year, so a bad file must never be written: Dump skips existing files and rewrites only empty `[]`
ones (left by older versions). A 22-byte `.json.gz` file is such an empty dump.

## The server is IO-starved

Before the `checksums(cms, version, filename)` index existed, each per-version query scanned the whole table and took
about 4.4 minutes on the server (0.4 s on a development Mac). One nightly run with two new Joomla versions took over an
hour and was cut short.

**Why:** timings on a development machine say nothing about the server.

**How to apply:** every query on `checksums` must use the index (check with `EXPLAIN QUERY PLAN`). Schema changes must
reach the existing production database at runtime (see `Akeeba\CoreSums\Database\Schema`), because `init` only runs
on fresh installs.
