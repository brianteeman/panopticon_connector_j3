# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Akeeba Panopticon Connector for Joomla! 3 — a Joomla system plugin that exposes a JSON:API-compliant REST API allowing [Akeeba Panopticon](https://github.com/akeeba/panopticon) to remotely monitor and manage Joomla 3.9/3.10 sites.

**Language/Framework**: PHP 7.2.5+ on Joomla! 3.x
**License**: AGPL-3.0+

## Build Commands

The build system uses Apache Phing 3.x. The shared build infrastructure lives in `../buildfiles/phing/common.xml` (outside this repo).

```bash
# Full build (default target): version setup, packaging, update XML generation
phing

# Install composer dependencies
phing composer-install

# Create development symlinks
phing link

# Package plugins only
phing package-plugins
```

The `version.php` file under `plugins/system/panopticon/` is **generated at build time** via token replacement (`##VERSION##`, `##DATE##`). It is gitignored — do not create or edit it manually.

## Architecture

### Request Flow

The plugin hooks into Joomla's `onAfterInitialise` event. Requests to `panopticon_api/v1/panopticon/*` are intercepted and processed through:

1. **Path parsing** — strips base path, `index.php`, and the `panopticon_api/` prefix
2. **PSR-4 autoloader** — registers `Akeeba\PanopticonConnector` namespace from `plugins/system/panopticon/src/`
3. **Routing** — `Router` matches method + path pattern to a controller callable
4. **Authentication** — bearer token or `X-Joomla-Token` header, validated via HMAC-SHA256 derived from Joomla's site secret
5. **Accept header** — must match `application/vnd.api+json` (with wildcard support)
6. **Controller** — invoked with `__invoke(\JInput $input): object`, returns JSON:API response

Entry point: `plugins/system/panopticon/panopticon.php` (`plgSystemPanopticon` class)

### Source Layout (`plugins/system/panopticon/src/`)

| Directory/File | Purpose |
|---|---|
| `Controller/` | API endpoint controllers, all extend `AbstractController` |
| `Controller/Mixit/` | Reusable traits (extension ID lookup, Joomla update helpers, Admin Tools integration, component param saving) |
| `Model/` | `CoreUpdateModel`, `ExtensionsModel` — Joomla MVC models |
| `Route/` | `Router` (route matching) and `Route` (single route definition with regex pattern support) |
| `Library/ServerInfo.php` | System info collection (disk, memory, load average) |
| `Version/Version.php` | Semantic version parser |
| `Authentication.php` | Token authentication (dual-method: path-based + connection-info-based HMAC) |
| `AcceptHeaderMatch.php` | HTTP Accept header validation |

### Key Patterns

- **Controllers** are callables: implement `__invoke(\JInput $input): object`. Routes are registered in `panopticon.php::getRouter()`.
- **JSON:API responses** use `AbstractController::asSingleItem()` and `asItemsList()` helpers.
- **All PHP files** start with `defined('_JEXEC') || die;` as a Joomla security guard.
- **Polyfills** in `polyfills.php` provide PHP 8 string functions (`str_contains`, `str_starts_with`, `str_ends_with`) for PHP 7.x compatibility.
- **Traits in `Mixit/`** are used to share code across controllers (e.g., `JoomlaUpdateTrait`, `AdminToolsTrait`).

### API Endpoints

Routes are defined in `panopticon.php::getRouter()`. Major groups:
- **Core updates**: `GET/POST v1/panopticon/core/update`, `POST core/update/download`, `POST core/update/activate`, `POST core/update/postupdate`
- **Core checksums**: `GET core/checksum/prepare`, `GET core/checksum/step/:step`
- **Extensions**: `GET v1/panopticon/extensions` (also `GET v1/extensions` for connection test), `POST updates`, `POST update`, `POST/PUT extension/install`
- **Admin Tools**: `POST admintools/{unblock,plugin/disable,plugin/enable,htaccess/disable,htaccess/enable,tempsuperuser,scanner/start,scanner/step}`, `GET admintools/scans`
- **Akeeba Backup**: `GET akeebabackup/info`

### Plugin Parameters

Configured in `panopticon.xml`:
- `sysinfo` — enable/disable server info collection
- `allow_remote_install` — enable/disable remote extension installation

## Code Conventions

- Allman brace style (braces on own line)
- Copyright header: `@package panopticon`, `@copyright Copyright (c)2023-2026 Nikolaos Dionysopoulos / Akeeba Ltd`, `@license` AGPL-3.0
- Use Joomla's `JInput` for request input, `JDatabaseQuery` for SQL (parameterized queries)
- Errors are `RuntimeException` with HTTP status codes; the plugin serialises the exception chain into a JSON:API `errors` array
- No test suite exists in this repository
