# MATRE - Magento Automated Test Run Environment

Enterprise-grade test automation orchestration for Magento 2.

![PHP](https://img.shields.io/badge/PHP-8.5-777BB4?logo=php&logoColor=white)
![Symfony](https://img.shields.io/badge/Symfony-8.0-000000?logo=symfony&logoColor=white)
![Magento](https://img.shields.io/badge/Magento-2.4-EE672F?logo=magento&logoColor=white)
![Vue](https://img.shields.io/badge/Vue-3-4FC08D?logo=vue.js&logoColor=white)
![Docker](https://img.shields.io/badge/Docker-Compose-2496ED?logo=docker&logoColor=white)

## Features

### Test Execution
- **Multi-framework**: Run MFTF and Playwright tests in a single execution
- **Multi-environment**: Isolated configs per target (dev/staging/production)
- **Async pipeline**: 5-phase execution (prepare → execute → report → notify → cleanup)
- **Cron scheduling**: Per-suite cron expressions for automated runs
- **Concurrency control**: Prevents overlapping runs per environment

### Artifacts & Screenshots
- **Per-result screenshots**: Each test result has associated failure screenshot
- **Screenshot gallery**: Thumbnail grid with click-to-enlarge lightbox
- **HTML reports**: Direct links to MFTF HTML output files
- **Secure artifact serving**: Route serves only allowed file types (png, jpg, html)
- **Run isolation**: Artifacts copied to `var/test-artifacts/{runId}/` for persistence

### Reporting & Notifications
- **Allure integration**: Interactive reports with 30-day retention
- **Slack notifications**: Rich messages with status, duration, report links
- **Email alerts**: Per-user preferences with environment subscriptions
- **Smart filtering**: Notify on all runs or failures only
- **Result aggregation**: Merged MFTF + Playwright results in single view

See [Notifications Guide](docs/operations/notifications.md) for configuration.

### Environment Configuration
- **Database-stored variables**: Per-environment custom variables in DB
- **Admin credentials**: Secure storage per target environment
- **Base URL management**: Auto-normalized URLs with validation

### Admin UI & API
- **Full CRUD**: Manage environments, test suites, and runs
- **Real-time status**: Live monitoring of running tests
- **Cancel/retry**: Control test runs mid-execution
- **REST API**: Programmatic access for CI/CD integration

## Quick Start

```bash
# Clone and start
git clone https://github.com/good-yellow-bee/matre.git
cd matre
./local.sh start
```

`./local.sh start` runs `docker compose up -d` and applies migrations. To create an admin user:

```bash
./local.sh console app:create-admin
```

**Access Points:**
| Service | URL |
|---------|-----|
| Admin Panel | http://localhost:8089 |
| Allure Reports | http://localhost:5050 |
| Selenium Grid | http://localhost:4444 |
| Mailpit UI | http://localhost:8031 |

**Default credentials:** set during `app:create-admin` (no built-in defaults).

## Architecture

### Entities
| Entity | Purpose |
|--------|---------|
| `TestEnvironment` | Target Magento instances with credentials and custom env vars |
| `TestSuite` | Reusable test collections with optional cron scheduling |
| `TestRun` | Execution instance with full lifecycle tracking |
| `TestResult` | Individual test outcomes with screenshots and error messages |
| `TestReport` | Generated artifacts (Allure, HTML, JSON) |

### Services
| Service | Responsibility |
|---------|---------------|
| `TestRunnerService` | Orchestrates 5-phase pipeline |
| `MftfExecutorService` | Executes MFTF tests via Docker |
| `PlaywrightExecutorService` | Executes Playwright tests |
| `AllureReportService` | Merges and publishes reports |
| `ModuleCloneService` | Git operations for test modules |
| `NotificationService` | Slack and email alerts |

### Execution Flow
```
pending → preparing → cloning → waiting → running → reporting → completed/failed/cancelled
```

### Docker Services
| Compose service | Purpose |
|-----------------|---------|
| `php` | Symfony application (`matre_php`) |
| `db` | MariaDB database (`matre_db`) |
| `nginx` | Web server (`matre_nginx`) |
| `magento` | MFTF execution environment (`matre_magento`) |
| `playwright` | Playwright test runner (`matre_playwright`) |
| `selenium-hub` | Selenium Grid hub (`matre_selenium_hub`) |
| `chrome-node` | Chromium browser node |
| `allure` | Allure report generator (`matre_allure`) |
| `test-worker` | Async test execution worker |
| `scheduler` | Cron-based test scheduling (`matre_scheduler`) |

## Configuration

### Environment Variables

```bash
# Test Module Repository
TEST_MODULE_REPO=git@github.com:org/magento-module.git
TEST_MODULE_BRANCH=main

# Selenium Grid
SELENIUM_HOST=selenium-hub
SELENIUM_PORT=4444

# Allure Reports
ALLURE_URL=http://allure:5050

# Magento Credentials (for MFTF)
MAGENTO_PUBLIC_KEY=your-public-key
MAGENTO_PRIVATE_KEY=your-private-key

# Notifications
SLACK_WEBHOOK_URL=https://hooks.slack.com/...
```

### TestEnvironment Configuration

Each environment can have custom variables stored in the database:

| Field | Description |
|-------|-------------|
| `name` | Display name (e.g., "Staging") |
| `code` | Unique code (e.g., "staging") |
| `baseUrl` | Magento base URL |
| `backendName` | Admin path (default: "admin") |
| `adminUsername` | Magento admin username |
| `adminPassword` | Magento admin password |
| `envVariables` | JSON object of custom env vars |

## API Reference

### Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/test-runs` | List runs (paginated, filtered) |
| `GET` | `/api/test-runs/{id}` | Get run details with results |
| `POST` | `/api/test-runs/{id}/cancel` | Cancel running test |
| `POST` | `/api/test-runs/{id}/retry` | Retry failed test |

### Response Example

```json
{
  "id": 42,
  "status": "completed",
  "type": "both",
  "testFilter": "SmokeTestGroup",
  "triggeredBy": "manual",
  "createdAt": "2026-01-15T10:30:00+00:00",
  "startedAt": "2026-01-15T10:30:05+00:00",
  "completedAt": "2026-01-15T10:35:28+00:00",
  "duration": "5m 23s",
  "environment": {
    "id": 1,
    "name": "Staging",
    "code": "staging",
    "region": "default"
  },
  "suite": {
    "id": 3,
    "name": "Smoke Tests"
  },
  "executedBy": {
    "id": 1,
    "username": "admin"
  },
  "resultCounts": {
    "passed": 95,
    "failed": 2,
    "skipped": 3,
    "broken": 0,
    "total": 100
  },
  "canBeCancelled": false,
  "results": [
    {
      "id": 1001,
      "testName": "StorefrontCheckoutTest",
      "testId": "StorefrontCheckoutTest",
      "status": "passed",
      "duration": 12500,
      "errorMessage": null
    }
  ],
  "reports": [
    {
      "id": 15,
      "type": "allure",
      "publicUrl": "http://localhost:5050/allure-docker-service/projects/run-42/reports/latest",
      "generatedAt": "2026-01-15T10:35:28+00:00"
    }
  ]
}
```

## Admin UI

### Test Environments (`/admin/test-environments`)
- Create and manage target Magento instances
- Configure credentials and custom environment variables
- Toggle active/inactive status

### Test Suites (`/admin/test-suites`)
- Define reusable test collections
- Configure test type (MFTF, Playwright, or both)
- Set test patterns (group names, specific tests, grep patterns)
- Enable cron scheduling with expressions

### Test Runs (`/admin/test-runs`)
- Create new test runs
- Monitor execution progress in real-time
- View results with screenshot gallery
- Access Allure reports
- Cancel or retry runs

## Development

```bash
# Start services
./local.sh start

# Frontend development (HMR)
npm run dev

# Build for production
npm run build

# Run PHPUnit tests
./local.sh test

# Run Playwright E2E tests
npm run test:e2e

# Code quality
./local.sh phpstan
./local.sh fix

# View worker logs
./local.sh logs test-worker
```

See [tests/README.md](tests/README.md) for full testing documentation.

## Tech Stack

| Layer | Technology |
|-------|------------|
| Backend | Symfony 8.0, PHP 8.5, Doctrine ORM 3 |
| Frontend | Vue 3, Vite, Tailwind CSS |
| Database | MariaDB 11 |
| Testing | MFTF (Codeception), Playwright |
| Reporting | Allure |
| Infrastructure | Docker, Nginx, Selenium Grid |
| Queue | Symfony Messenger |

## Documentation

### Getting Started
- [MFTF Setup Guide](docs/getting-started/mftf-setup.md) - Complete end-to-end setup from scratch
- [Quick Start Guide](docs/getting-started/quick-start.md) - Run your first test in minutes
- [Installation Guide](docs/getting-started/installation.md)
- [Configuration](docs/getting-started/configuration.md)

### Operations
- [Running Tests](docs/operations/test-execution.md) — Execute MFTF/Playwright tests
- [Allure Reports](docs/operations/allure-reports.md) — View and manage reports
- [Monitoring](docs/operations/monitoring.md) — Health checks and diagnostics
- [Scheduling](docs/operations/scheduling.md) — Automate test runs
- [CLI Reference](docs/operations/cli-reference.md) — All commands
- [API Reference](docs/operations/api-reference.md) — REST API
- [Disaster Recovery](docs/operations/disaster-recovery.md) — Incident playbook
- [Backup & Restore](docs/operations/backup-restore.md) — Backup procedures
- [Troubleshooting](docs/operations/troubleshooting.md) — Common issues

### Development
- [Architecture Overview](docs/development/architecture.md)
- [Entities](docs/development/entities.md)
- [Admin CRUD](docs/development/admin-crud.md)
- [Forms](docs/development/forms.md) — Form handling patterns
- [Vue Islands](docs/development/vue-islands.md) — Vue 3 component pattern
- [Dev Mode](docs/development/dev-mode.md) — Local module development
- [Unit Tests](docs/testing/unit-tests.md) — PHPUnit testing

### Deployment
- [Production Deployment](docs/deployment/production.md)
- [Docker Engine](docs/deployment/docker-engine.md) — Engine choice, holds, restart policies
- [CI/CD](docs/deployment/ci-cd.md)
- [Security](docs/security.md)

## License

MIT License — see [LICENSE](LICENSE) for details.
