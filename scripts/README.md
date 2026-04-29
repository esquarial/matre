# Scripts

## allure_cleanup.py

Remove test results from Allure reports **permanently** with advanced filtering. Supports multi-environment batch operations and suite-based filtering.

### What It Removes

**From Generated Report** (`allure-report-{env}/`):
1. **Test-case file** (`data/test-cases/{uid}.json`)
2. **Behaviors entry** (`data/behaviors.json`)
3. **Suites entry** (`data/suites.json`)
4. **History entry** (`history/history.json`)

**From Source Results** (`allure-results-{env}/`):
5. **Testsuite XML files** (`{uuid}-testsuite.xml`) - **Prevents re-appearance after report regeneration**

### Environment Shortcuts

| Shortcut | Environments |
|----------|--------------|
| `all` | stage-us, stage-es, preprod-us, preprod-es |
| `us` | stage-us, preprod-us |
| `es` | stage-es, preprod-es |
| `stage` | stage-us, stage-es |
| `preprod` | preprod-us, preprod-es |

### Usage Examples

```bash
# Remove test from all environments
python3 scripts/allure_cleanup.py -t MOEC8899ES --sudo

# Remove from specific suite only (e.g., group1)
python3 scripts/allure_cleanup.py -t MOEC2417 --in-suite group1 --sudo

# Remove from ES environments only
python3 scripts/allure_cleanup.py -t MOEC3758 --env es --sudo

# Remove from group1 on US environments only
python3 scripts/allure_cleanup.py -t MOEC-5173 --in-suite group1 --env us --sudo

# Keep entries in functional\us suite (remove from everywhere else)
python3 scripts/allure_cleanup.py -t MOEC5317 --not-in-suite us --sudo

# Multiple test IDs
python3 scripts/allure_cleanup.py -t MOEC3758 -t MOEC11676 --env es --sudo

# Dry run (preview changes)
python3 scripts/allure_cleanup.py -t MOEC2417 --in-suite group1 --dry-run
```

### Options

| Option | Short | Default | Description |
|--------|-------|---------|-------------|
| `--test-id` | `-t` | (required) | Test ID pattern (can use multiple times) |
| `--env` | `-e` | `all` | Environment shortcut or comma-separated list |
| `--in-suite` | | | Only remove from these suites (can use multiple times) |
| `--not-in-suite` | | | Do not remove from these suites (can use multiple times) |
| `--ssh` | `-s` | (deployment-specific) | SSH host alias for the production server (use `local` for local execution) |
| `--base-path` | `-p` | (legacy default — see warning) | Base path template |
| `--dry-run` | `-n` | | Preview changes without modifying files |
| `--sudo` | | | Use sudo for file operations |

### Example Output

```
Allure History Cleanup
==================================================
Test IDs: TEST-1234
Environments: stage-us, stage-es, preprod-us, preprod-es
Base path: /home/ubuntu/matre/var/allure-projects/{env}/reports/latest
In suites: group1
SSH host: <production-server>
Dry run: False
Use sudo: True

stage-us: Removed 236 report entries, 236 source files
  - MOEC2417: Test for LV Drives configurators - Edit
    Suite: Magento\FunctionalTestingFramework.functional\group1
  ... and 231 more report entries
  📁 abc123-testsuite.xml (MOEC2417)
  ... and 231 more source files

stage-es: Removed 234 report entries, 234 source files
  - MOEC2417: Test for LV Drives configurators - Edit
    Suite: Magento\FunctionalTestingFramework.functional\group1
  ... and 229 more report entries
  📁 def456-testsuite.xml (MOEC2417)
  ... and 229 more source files

==================================================
Total: 470 report entries + 470 source files removed across 4 environment(s)
```

> ⚠️ **The script's built-in default `--base-path` points to a legacy location that is no longer used.** Always pass `--base-path` explicitly, e.g.:
> ```
> --base-path '/home/ubuntu/matre/var/allure-projects/{env}/reports/latest'
> ```
> Adjust to match your deployment's actual report directory.

### Recovery

Backups created with `.bak` extension. To restore:

```bash
ssh <production-server> "sudo cp /path/to/report/data/behaviors.json.bak /path/to/report/data/behaviors.json"
ssh <production-server> "sudo cp /path/to/report/data/suites.json.bak /path/to/report/data/suites.json"
ssh <production-server> "sudo cp /path/to/report/history/history.json.bak /path/to/report/history/history.json"
```

---

## Host Ops Scripts (`scripts/ops`)

Operational scripts for production host maintenance and disk safety.

### Install / Remove Host Cron Entries

Install idempotent cron entries (marker: `matre-host-ops`):

```bash
bash scripts/ops/install-host-ops-cron.sh
```

Remove only MATRE host-ops entries:

```bash
bash scripts/ops/remove-host-ops-cron.sh
```

### `safe-docker-prune.sh`

Safe Docker cleanup (excludes volumes) with lock protection.

```bash
# Preview only
bash scripts/ops/safe-docker-prune.sh --dry-run

# Execute cleanup
bash scripts/ops/safe-docker-prune.sh
```

Actions:
- `docker builder prune -af --filter until=168h`
- `docker image prune -af --filter until=168h`
- `docker container prune -f --filter until=168h`
- `docker network prune -f --filter until=168h`

### `artifact-retention.sh`

Runs Symfony artifact/report cleanup inside the `php` container.

```bash
# Preview only
bash scripts/ops/artifact-retention.sh --dry-run

# Execute cleanup (default retention 14 days)
bash scripts/ops/artifact-retention.sh
```

Optional env overrides:
- `PROJECT_DIR=/home/ubuntu/matre`
- `RETENTION_DAYS=14`

### `disk-monitor-alert.sh`

Monitors `/` disk usage and sends Slack alerts on threshold transitions.

```bash
bash scripts/ops/disk-monitor-alert.sh
```

Defaults:
- Warning threshold: `85%`
- Critical threshold: `92%`
- State file: `/tmp/matre-disk-alert.state`
- Slack webhook source: `/home/ubuntu/matre/.env` (`SLACK_WEBHOOK_URL`)

Optional env overrides:
- `WARN_THRESHOLD=85`
- `CRIT_THRESHOLD=92`
- `ENV_FILE=/home/ubuntu/matre/.env`
