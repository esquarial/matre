# CLI Reference

All MATRE commands. Click links for detailed usage.

---

## Management Scripts

Wrapper scripts for common operations.

### `./local.sh` (development)

| Command | Description |
|---|---|
| `./local.sh start` | `docker compose up -d` + run migrations |
| `./local.sh stop` | Stop all containers |
| `./local.sh restart` | Stop + start, with Selenium Grid readiness wait |
| `./local.sh status` | Show container status |
| `./local.sh logs [svc]` | Follow logs (all services or one) |
| `./local.sh shell [svc]` | Open shell (default service: `php`) |
| `./local.sh console <cmd>` | Run Symfony console command in php container |
| `./local.sh test` | Run PHPUnit |
| `./local.sh phpstan` | Run PHPStan static analysis |
| `./local.sh fix` | Fix code style with PHP-CS-Fixer |

### `./prod.sh` (production)

| Command | Description |
|---|---|
| `./prod.sh start` | Start with `production` profile + migrate + cache warmup |
| `./prod.sh stop` | Stop all containers |
| `./prod.sh restart` | Stop + start, with Selenium Grid readiness wait |
| `./prod.sh status` | Container status (highlights workers/scheduler) |
| `./prod.sh update` | Pull images + recreate + migrate + cache clear (use after composer or compose changes) |
| `./prod.sh recreate <svc>` | Recreate one service |
| `./prod.sh logs [svc]` | Follow logs |
| `./prod.sh shell [svc]` | Open shell (default: `php`) |
| `./prod.sh console <cmd>` | Run Symfony console command |
| `./prod.sh frontend` | Build + atomically deploy frontend assets |
| `./prod.sh frontend --no-cache` | Force fresh frontend build (after package.json/vite.config changes) |
| `./prod.sh frontend-rollback` | Restore previous frontend build from `public/build.old/` |

---

## Test Operations

| Command | Purpose | Guide |
|---------|---------|-------|
| `app:test:run` | Execute MFTF/Playwright tests | [Test Execution](test-execution.md#cli) |
| `app:test:check-magento` | Pre-flight health check | [Monitoring](monitoring.md#pre-flight) |
| `app:test:cleanup` | Remove old artifacts/reports | [Allure Reports](allure-reports.md#cleanup) |
| `app:test:import-env` | Bulk import test environments from .env files | — |
| `app:test-run:watchdog` | Detect and fail stuck test runs | — |
| `app:clear-env-locks` | Clear stale per-environment locks | — |
| `app:containers:cleanup` | Remove orphaned dynamic Magento env containers | — |

---

## Scheduling

| Command | Purpose | Guide |
|---------|---------|-------|
| `app:cron:list` | Show scheduled jobs | [Scheduling](scheduling.md#list-jobs) |
| `app:cron:run {id}` | Run job manually | [Scheduling](scheduling.md#manual-run) |
| `app:cron:install` | Add to system crontab | [Scheduling](scheduling.md#system-install) |
| `app:cron:remove` | Remove from crontab | [Scheduling](scheduling.md#system-install) |

## Maintenance

| Command | Purpose |
|---------|---------|
| `app:audit:cleanup` | Prune old audit log entries |
| `app:notification:resend` | Resend a failed notification |

---

## Setup

See [Installation Guide](../getting-started/installation.md) for full setup instructions.

| Command | Purpose |
|---------|---------|
| `app:database:setup` | Initialize database (drop, create, migrate, fixtures) |
| `app:create-admin` | Create admin user (interactive) |
| `app:create-user` | Create regular user (interactive) |
| `app:load-fixtures` | Load sample data |

---

## Data Import

| Command | Purpose |
|---------|---------|
| `app:env:import` | Import .env variables with MFTF usage analysis |
| `app:test:import-env` | Bulk import test environments from .env files |

### Environment Variable Import (`app:env:import`)

Import variables from `.env.{environment}` files with automatic MFTF test usage analysis.

```bash
# Clone module and select environment interactively
php bin/console app:env:import --clone

# Import for specific environment (creates env-specific variables)
php bin/console app:env:import stage-us --clone

# Import as global variables (apply to ALL environments)
php bin/console app:env:import stage-us --clone --global

# Preview without saving
php bin/console app:env:import stage-us --dry-run

# Overwrite existing variables
php bin/console app:env:import stage-us --overwrite
```

**Options:**
| Option | Short | Description |
|--------|-------|-------------|
| `--clone` | `-c` | Clone fresh test module from TEST_MODULE_REPO |
| `--global` | `-g` | Import as global variables (apply to all environments) |
| `--dry-run` | | Preview without saving changes |
| `--overwrite` | | Update existing variables |

**Merge Logic:**
- Same name + same value across environments → merges into single record with multiple environments
- Same name + different values → creates separate records
- `--global` flag → sets `environments = null` (applies to all)

---

## Validation

| Command | Purpose |
|---------|---------|
| `app:validate-ssl-config` | Check SSL/Traefik configuration for production |
