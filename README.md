# Nour-API installer

Creates `nour-api/nour-api` applications using Composer. Requires PHP 8.2+, Composer in `PATH`, and Git unless `--no-git` is selected.

```sh
composer global require nour-api/installer
nour-api my-api --project-version="^2.2"
cd my-api
php -S localhost:8000 -t public
```

Installer 2.1.0 supports the Nour-API 2.2 application skeleton. The published `--ref` / `-r` option remains an alias for `--project-version`; conflicting values are rejected.

The installer rejects existing paths, checks subprocess failures, retains partial projects for diagnosis, creates `.env` when missing, fills blank application keys with 32 random bytes, and preserves existing keys. Files it writes receive private `0600` permissions. Git initialization never stages files or creates an automatic commit. Review your project before committing.

Options: `--project-version`, `--ref` / `-r`, `--no-git`, `--prefer-source`, `--no-scripts`, `--no-plugins`, and `--ignore-platform-reqs`. The last option bypasses Composer compatibility checks and should not be used for production. `--version` reports the installer version; use `--project-version` to select a skeleton. The old conflicting `--version` skeleton option and private `run()` prevented the original installer from starting under Symfony 7.

Composer scripts/plugins run unless disabled by their respective options. The installer uses Composer's binary proxy autoloader when installed globally. It does not invoke the unavailable `artisan key:generate` command.

```sh
composer install --no-plugins --no-scripts
composer test
composer validate --strict
composer audit
# Requires sibling framework and nour-api local checkouts:
php tests/integration.php
```

The integration check uses real Composer with temporary local path repositories, runs the installed application's tests and Artisan, and checks an HTTP dispatch. It may download dependencies; it performs no publication or remote repository writes.

## Release publication

Stable `v*` tags run the release verification workflow before a GitHub Release is created. Package discovery through Packagist depends on its configured repository integration; publication is verified against the registry before dependent package releases are advanced. No API keys are stored in the repository.
