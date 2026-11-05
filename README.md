# Nour-API installer

Creates `nour-api/nour-api` applications using Composer. Requires PHP 8.2+, Composer in `PATH`, and Git unless `--no-git` is selected.

```sh
composer global require nour-api/installer
nour-api my-api --project-version="^2.1"
cd my-api
php -S localhost:8000 -t public
```

These commands use published packages. Local changes require the local integration check below until reviewed and released.

The installer rejects existing paths, checks subprocess failures, retains partial projects for diagnosis, creates `.env` when missing, fills blank application keys with 32 random bytes, and preserves existing keys. Files it writes receive private `0600` permissions. Git initialization never stages files or creates an automatic commit. Review your project before committing.

Options: `--project-version`, `--no-git`, `--prefer-source`, `--no-scripts`, `--no-plugins`, and `--ignore-platform-reqs`. The last option bypasses Composer compatibility checks and should not be used for production. `--version` reports the installer version; use `--project-version` to select a skeleton. The old conflicting `--version` skeleton option and private `run()` prevented the original installer from starting under Symfony 7.

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
