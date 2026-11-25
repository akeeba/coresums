# AGENTS.md

## Setup

Copy `env.dist` to `.env` and set a valid `GITHUB_TOKEN` (GitHub personal access
token). Without it the `sources` command cannot query the GitHub API.

## Testing

See the "Testing" section of `README.md` for the offline-only requirement, why
`failOnDeprecation` is disabled, and the `insertObject` by-reference gotcha.

## Project memory

Read the matching file before starting that kind of work.

| File | Read it before… |
|---|---|
| `.claude/memory/database-api.md` | writing or changing any database query |
| `.claude/memory/production.md` | diagnosing a missing or wrong published checksum, changing a query on `checksums`, or changing the schema |
