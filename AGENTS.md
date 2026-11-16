# AGENTS.md

## Setup

Copy `env.dist` to `.env` and set a valid `GITHUB_TOKEN` (GitHub personal access
token). Without it the `sources` command cannot query the GitHub API.

## Testing

See the "Testing" section of `README.md` for the offline-only requirement, why
`failOnDeprecation` is disabled, and the `insertObject` by-reference gotcha.
