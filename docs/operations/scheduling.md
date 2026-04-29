# Test Scheduling

Automate test execution with cron-based scheduling.

---

## Overview

MATRE supports automated test runs via cron expressions. Scheduled tests:
- Run automatically at specified intervals
- Use environment locking to prevent conflicts
- Track execution history and status
- Support all test types (MFTF, Playwright, Both)

---

## Configure Scheduled Tests

### Via Admin UI

1. Navigate to **Test Suites**
2. Click **Edit** on a suite
3. Enable **Scheduled** checkbox
4. Enter **Cron Expression**
5. Select **Environment** for scheduled runs
6. Click **Save**

### Required Fields

| Field | Description |
|-------|-------------|
| Scheduled | Enable/disable scheduling |
| Cron Expression | When to run (see syntax below) |
| Environment | Target environment for scheduled runs |
| Test Type | MFTF, Playwright, or Both |

---

## Cron Expression Syntax

Standard 5-field cron format:

```
┌───────────── minute (0-59)
│ ┌───────────── hour (0-23)
│ │ ┌───────────── day of month (1-31)
│ │ │ ┌───────────── month (1-12)
│ │ │ │ ┌───────────── day of week (0-6, Sunday=0)
│ │ │ │ │
* * * * *
```

### Common Examples

| Expression | Schedule |
|------------|----------|
| `0 2 * * *` | Daily at 2:00 AM |
| `0 */6 * * *` | Every 6 hours |
| `30 1 * * 1-5` | Weekdays at 1:30 AM |
| `0 0 * * 0` | Sunday at midnight |
| `0 0 1 * *` | First of month at midnight |
| `*/30 * * * *` | Every 30 minutes |
| `0 9,17 * * *` | At 9 AM and 5 PM |

### Special Characters

| Character | Meaning |
|-----------|---------|
| `*` | Any value |
| `,` | List separator (e.g., `1,15`) |
| `-` | Range (e.g., `1-5`) |
| `/` | Step values (e.g., `*/15`) |

---

## Managing Jobs {#list-jobs}

### List All Jobs

```bash
docker compose exec php php bin/console app:cron:list
```

Output:
```
+----+------------------+-------------+--------+-------------+---------------------+
| ID | Name             | Expression  | Active | Last Status | Last Run            |
+----+------------------+-------------+--------+-------------+---------------------+
| 1  | Nightly Smoke    | 0 2 * * *   | Yes    | success     | 2025-01-15 02:00:05 |
| 2  | Weekly Full      | 0 0 * * 0   | Yes    | failed      | 2025-01-14 00:00:03 |
| 3  | Hourly Quick     | 0 * * * *   | No     | never       | -                   |
+----+------------------+-------------+--------+-------------+---------------------+
```

### Filter Active Only

```bash
docker compose exec php php bin/console app:cron:list --active-only
```

---

## Manual Execution {#manual-run}

Trigger a scheduled job immediately:

### By Job ID

```bash
docker compose exec php php bin/console app:cron:run 1
```

### Wait for Completion

```bash
docker compose exec php php bin/console app:cron:run 1 --sync
```

Output:
```
Executing job #1: Nightly Smoke
Command: app:test:run mftf staging --filter=SmokeTestGroup
Status: success
Duration: 5m 23s
Output: Test run #42 completed. 15 passed, 0 failed.
```

---

## System Integration {#system-install}

### Docker (Automatic)

In Docker environment, the `scheduler` service (`matre_scheduler` container) handles all scheduling automatically:

```yaml
# docker-compose.yml
scheduler:
  command: php bin/console messenger:consume async scheduler_test_runner scheduler_cron scheduled_test_messages --time-limit=60 -vv
  restart: unless-stopped
```

**No additional setup required** - scheduler runs continuously.

### Manual Installation (Non-Docker)

For non-Docker deployments, install to system crontab:

#### Preview Entry

```bash
php bin/console app:cron:install --show-only
```

Output:
```
* * * * * cd /path/to/matre && php bin/console messenger:consume scheduler_test_runner scheduler_cron scheduled_test_messages --time-limit=55 >> var/log/scheduler.log 2>&1
```

#### Install to Crontab

```bash
php bin/console app:cron:install
```

#### Remove from Crontab

```bash
php bin/console app:cron:remove
```

---

## Architecture

### How Scheduling Works

1. **CronJob entity** stores job configuration in database
2. **CronJobScheduleProvider** reads active jobs on worker start
3. **Symfony Scheduler** dispatches `CronJobMessage` at scheduled times
4. **CronJobMessageHandler** executes the command
5. Status and output stored back to CronJob entity

### Locking Mechanism

- Only one instance of a job can run at a time
- Lock TTL: 1 hour (auto-releases on crash)
- Status shows `locked` if already running
- Prevents duplicate executions

### Schedule Updates

- Changes take effect within ~60 seconds
- Worker restarts automatically pick up new schedule
- No manual restart required

---

## Job Statuses

| Status | Description |
|--------|-------------|
| `success` | Last execution completed successfully |
| `failed` | Last execution encountered an error |
| `running` | Currently executing |
| `locked` | Another instance is running |
| `never` | Job has never been executed |

---

## Viewing Job Output

Job output is stored in the database. View via Admin UI:

1. Navigate to **Cron Jobs**
2. Click on job to view details
3. **Last Output** shows execution log

Or via database:
```bash
docker compose exec db mysql -umatre -pmatre -e \
  "SELECT name, last_status, last_output FROM matre.cron_job WHERE id = 1;"
```

---

## Troubleshooting

See [Troubleshooting Guide](troubleshooting.md#scheduling) for common issues:
- Jobs not running
- Stuck in locked status
- Scheduler container issues
