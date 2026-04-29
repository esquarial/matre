# Allure Reports

Managing test reports: viewing, retention, and cleanup.

---

## Accessing Reports

### From Admin UI

1. Open completed test run in **Test Runs**
2. Click **View Report** button
3. Allure HTML report opens in new tab

### Direct URL

```
http://localhost:5050/allure-docker-service/projects/run-{id}/reports/latest
```

Replace `{id}` with test run ID. Example:
```
http://localhost:5050/allure-docker-service/projects/run-42/reports/latest
```

### Via API

Get report URL from test run details:
```bash
curl http://localhost:8089/api/test-runs/42
```

Response includes `reports` array with `publicUrl` for each report.

---

## Report Contents

Allure HTML reports include:

| Section | Description |
|---------|-------------|
| **Overview** | Pass/fail pie chart, total duration, environment info |
| **Suites** | Test hierarchy with status per test |
| **Graphs** | Historical trends (if previous runs exist) |
| **Timeline** | Test execution timeline visualization |
| **Categories** | Failure categorization (product bugs, test issues) |
| **Behaviors** | Tests organized by feature/story |
| **Packages** | Tests organized by package/namespace |

### Result Merging

When running **Both** test types, results from MFTF and Playwright are merged into a single report showing combined coverage.

---

## Retention Policy

- **Default retention:** 30 days
- Reports automatically expire after retention period
- Expired reports removed by cleanup job

---

## Cleanup {#cleanup}

### Automatic Cleanup

A scheduled job runs daily to remove expired reports and artifacts.

### Manual Cleanup

Preview what will be deleted (dry run):
```bash
docker compose exec php php bin/console app:test:cleanup --dry-run
```

Output:
```
[DRY RUN] Would delete:
- 15 test runs older than 30 days
- 15 Allure report directories
- 42 artifact directories
```

Execute cleanup:
```bash
docker compose exec php php bin/console app:test:cleanup
```

### Options

| Option | Example | Description |
|--------|---------|-------------|
| `--days` | `--days=7` | Custom retention period |
| `--dry-run` | `--dry-run` | Preview without deleting |
| `--reports-only` | `--reports-only` | Only delete reports, keep test run records |

### Examples

```bash
# Delete everything older than 7 days
docker compose exec php php bin/console app:test:cleanup --days=7

# Preview reports cleanup only
docker compose exec php php bin/console app:test:cleanup --reports-only --dry-run

# Force cleanup of all reports older than 1 day
docker compose exec php php bin/console app:test:cleanup --days=1 --reports-only
```

### Batch Cleanup (Advanced)

For multi-environment cleanup, use `scripts/allure_cleanup.py`:

```bash
# Clean all environments
python scripts/allure_cleanup.py all

# Shortcuts: us, es, stage, preprod
python scripts/allure_cleanup.py us --older-than 14

# Filter by suite
python scripts/allure_cleanup.py all --not-in-suite "Smoke"
```

See `scripts/README.md` for full options including remote execution mode.

---

## Allure Service Health

### Check Service Status

```bash
curl http://localhost:5050/allure-docker-service/version
```

Expected: Version string like `"2.24.0"`

### View Logs

```bash
docker compose logs allure
docker compose logs -f allure  # Follow logs
```

### Restart Service

```bash
docker compose restart allure
```

### List All Projects

```bash
curl http://localhost:5050/allure-docker-service/projects
```

---

## Storage Locations

| Type | Path |
|------|------|
| Allure results (JSON) | `var/allure-results/run-{id}/` |
| MFTF results | `var/mftf-results/` |
| Playwright results | `var/playwright-results/` |
| Test artifacts | `var/test-artifacts/{runId}/` |
| MFTF run logs | `var/test-output/mftf-run-{id}.log` |

---

## Troubleshooting

See [Troubleshooting Guide](troubleshooting.md#reports) for common issues:
- Report not generating
- Report shows no data
- Screenshots not loading
