# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Core Sums is a PHP CLI application that generates checksums (MD5, SHA-1, SHA-256, SHA-512) for Joomla! core CMS files. It downloads Joomla! release archives from GitHub, extracts files, and computes checksums on both original files and "squashed" versions (whitespace normalized to single spaces). The purpose is publicly verifiable checksum generation for transparency.

## Setup and Commands

```bash
# Install dependencies
composer install

# Copy .env.dist to .env and set a valid GITHUB_TOKEN
cp .env.dist .env

# Typical workflow
php ./coresums.php init                    # Create/reset sums.sqlite database
php ./coresums.php sources --process       # Fetch Joomla releases from GitHub + generate checksums
php ./coresums.php dump --sources          # Export data as JSON files
```

### Individual commands

- `init [sourceFolder]` — Creates the SQLite database. Optionally imports from a previous dump.
- `sources [--latest] [--process] [--dump=]` — Fetches Joomla! release URLs from GitHub API. `--latest` limits to 30 most recent. `--process` triggers checksum generation. `--dump` exports sources JSON.
- `generate [cms] [cmsVersion] [--all] [--new]` — Downloads archives and computes checksums. `--all` processes every version (slow). `--new` only processes versions without existing checksums.
- `dump [outdir] [--sources] [--no-sums] [--gzip]` — Exports database to JSON files, optionally gzip-compressed.
- `versions [cms]` — Lists known CMS versions.

There are no automated tests in this project.

## Architecture

**Entry point:** `coresums.php` — Sets up the Silly CLI framework (Symfony Console wrapper) with a Pimple DI container.

**Namespace:** `Akeeba\CoreSums\` mapped to `src/` via PSR-4.

### Key directories

- `src/Command/` — CLI command implementations (Init, Sources, Generate, Dump, Versions). Shared behavior via `IoStyleTrait` (Symfony IO styling) and `CmsNamesTrait` (CMS name normalization).
- `src/Container/` — Pimple DI container with service providers: Database (SQLite via joomla/database), Dotenv, CachePool (Symfony cache), GitHub (knplabs/github-api), HttpFactory (Guzzle with cache middleware), Commands.
- `src/HttpFactory/` — Custom Guzzle HTTP client factory with filesystem-based caching.
- `assets/` — SQL schemas (`sources.sql`, `checksums.sql`) and `sources.json` seed data.

### Data flow

1. **Sources** command queries GitHub API for Joomla! releases, stores download URLs in `sources` table.
2. **Generate** command downloads archives (ZIP/TAR.GZ) to `tmp/`, extracts them, computes 8 checksums per file (4 hash types × original + squashed), stores in `checksums` table.
3. **Dump** command exports database contents as JSON files organized by `cms/version/hash_type.json`.

### Database

SQLite (`sums.sqlite`) with two tables:
- `sources` — CMS name, version, download URL
- `checksums` — CMS name, version, filename, 4 hash types × 2 variants (original + squashed)

### File processing

Only files with these extensions are checksummed: `php`, `inc`, `ini`, `xml`, `js`, `es6`, `mjs`, `json`. Squashing replaces all contiguous whitespace (including newlines) with a single space.

## Requirements

- PHP 8.2+
- ext-zlib
- A GitHub personal access token (set `GITHUB_TOKEN` in `.env`)
- License: AGPL-3.0-or-later
