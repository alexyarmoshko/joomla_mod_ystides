# AGENT Instructions for PHP/Joomla Work

## Scope

This repo contains Joomla 5.4 code. Use the Joomla API and conventions; avoid ad-hoc PHP unless there is no core helper. Ignore "./.*" folders with subfolders as they are not relevant to understand the project.

## Environment

Match the PHP version to the Joomla 5.4.1 requirements in `README.txt`/docs (modern 8.3). Do not add system-level dependencies; keep changes self-contained.

Development environment uses DDEV Windows WSL2 instance for launching local web development for PHP/Joomla.
DDEV configuration stored in .ddev folder. In turn DDEV uses remote Docker host accessible via environment variable DOCKER_HOST.
Consult with [DDEV documentation and examples](https://docs.ddev.com/en/stable/) if required.

## Coding style

Follow Joomla coding standards (PSR-12 based). Keep namespaces, type hints, and docblocks consistent with nearby code. Avoid adding `declare(strict_types=1);` if the file does not already use it.

## Security

Never trust request data. Use `$app->getInput()` and the filtering helpers (`getString`, `getInt`, etc.). Add CSRF protection for forms/actions (`Session::checkToken()` or form token fields). Escape output with Joomla helpers (`HTMLHelper`, `Text`, `$this->escape`).

## Localization

No hard-coded UI strings. Add/adjust language keys under `language/en-GB/` (and module/plugin language folders as appropriate) and load them via `Text::_()` / `Text::sprintf()`. For date formats Joomla configuration to be use, default for date is`DATE_FORMAT_LC4` and for time 24h format to be used.

## Database & services

Use dependency injection/Factory (`Factory::getContainer()->get('DatabaseDriver')`) and query builders or Table classes. Parameterize queries; avoid manual concatenation.

## Query database structure

The database can be accessed with mysql command:

```bash
mysql -u root -proot --port=32820 --host=192.168.0.93 db -e "<SQL command>"
```

Query list of all tables:

```bash
mysql -u root -proot --port=32820 --host=192.168.0.93 db -e "SHOW TABLES;"
```

Query table structure:

```bash
mysql -u root -proot --port=32820 --host=192.168.0.93 db -e "DESCRIBE <table_name>;"
```

## MVC/Extensions

Keep to Joomla MVC patterns for components (Controller → Model → View/Layout). For plugins, fire/handle the correct events; avoid direct core hacks. For templates/layouts, prefer Layout overrides and helpers over inline PHP/HTML mixing.

## Assets

Place JS/CSS via Joomla asset manager (`$document->getWebAssetManager()`); avoid inline scripts/styles when possible.

## Testing & checks

`ddev logs` gets you web server logs.
`ddev logs -s db` gets database server logs.

If you touch PHP logic, add/adjust tests where they exist (PHPUnit/system). Run available linters/tests locally when practical; keep changes minimal if tests aren’t present.

## Config & secrets

Never commit real credentials or local configuration. Do not modify `configuration.php` or other user-specific files.
