# Monitoring & Health Checks

System diagnostics and health monitoring.

---

## Pre-Flight Validation {#pre-flight}

Run before test execution to verify system readiness:

```bash
docker compose exec php php bin/console app:test:check-magento
```

### Checks Performed

| Check | Description |
|-------|-------------|
| Docker containers | Verify required containers are running |
| Magento version | Detect Magento version in test environment |
| MFTF binary | Verify `vendor/bin/mftf` is available |
| Selenium Grid | Test connection to selenium-hub |
| Allure service | Verify Allure API responds |

### Example Output

```
MATRE Pre-Flight Check
======================

[OK] Docker: matre_magento container running
[OK] Magento: Version 2.4.6-p3 detected
[OK] MFTF: vendor/bin/mftf available
[OK] Selenium: Hub at selenium-hub:4444 responding (4 nodes)
[OK] Allure: Service at allure:5050 healthy (v2.24.0)

All checks passed. Ready for test execution.
```

### Failed Check Example

```
[OK] Docker: matre_magento container running
[OK] Magento: Version 2.4.6-p3 detected
[OK] MFTF: vendor/bin/mftf available
[FAIL] Selenium: Connection refused to selenium-hub:4444
[OK] Allure: Service at allure:5050 healthy

1 check failed. See troubleshooting guide.
```

---

## Dashboard Statistics

### API Endpoint

```bash
curl http://localhost:8089/api/dashboard/stats
```

### Response

```json
{
  "users": {
    "total": 5,
    "active": 4,
    "inactive": 1
  },
  "testRuns": {
    "total": 150,
    "completed": 120,
    "failed": 25,
    "running": 3,
    "pending": 2
  },
  "environments": {
    "total": 5,
    "active": 4
  },
  "suites": {
    "total": 10,
    "active": 8,
    "scheduled": 3
  },
  "activity": {
    "running": 2
  }
}
```

### Metrics Explained

| Metric | Description |
|--------|-------------|
| `testRuns.running` | Currently executing tests |
| `testRuns.pending` | Queued tests waiting to start |
| `testRuns.failed` | Failed runs in last 30 days |
| `suites.scheduled` | Suites with active cron scheduling |
| `activity.running` | Current active test count |

---

## Service Health Checks

Quick health verification for each service:

| Service | Command | Expected |
|---------|---------|----------|
| **App** | `curl http://localhost:8089` | HTTP 200 |
| **Selenium** | `curl http://localhost:4444/status` | JSON with `"ready": true` |
| **Allure** | `curl http://localhost:5050/allure-docker-service/version` | Version string |
| **Mailpit** | `curl http://localhost:8031` | HTTP 200 |
| **Database** | `docker compose exec db mysqladmin ping -umatre -pmatre` | `mysqld is alive` |

### Selenium Grid Details

```bash
curl http://localhost:4444/status | jq '.value.ready, .value.nodes'
```

Shows grid ready state and available browser nodes.

---

## Log Monitoring

### Test Worker

Test execution logs:
```bash
docker compose logs -f test-worker
```

### Scheduler

Cron job execution logs:
```bash
docker compose logs -f scheduler
```

### All Services

```bash
docker compose logs -f
```

### Specific Container

```bash
# Last 100 lines
docker compose logs --tail=100 php

# Follow with timestamps
docker compose logs -f -t php
```

---

## Queue Health

### Check Pending Messages

```bash
docker compose exec db mysql -umatre -pmatre -e \
  "SELECT queue_name, COUNT(*) as pending FROM matre.messenger_messages GROUP BY queue_name;"
```

Expected queues:
- `test_runner_per_env` / `test_runner_env_{environmentId}` - Per-environment test execution messages
- `scheduled_test_messages` - Scheduled test messages
- `async` - Email/notification messages

### Check Failed Messages

```bash
docker compose exec db mysql -umatre -pmatre -e \
  "SELECT id, queue_name, created_at FROM matre.messenger_messages WHERE queue_name = 'failed' ORDER BY created_at DESC LIMIT 10;"
```

### Retry Failed Message

Failed messages can be retried via Symfony console:
```bash
docker compose exec php php bin/console messenger:failed:show
docker compose exec php php bin/console messenger:failed:retry {id}
```

---

## Host Disk Monitoring {#host-disk}

For production deployments, use the host ops scripts under `scripts/ops`:

```bash
# Install host cron entries (idempotent)
bash scripts/ops/install-host-ops-cron.sh

# Remove host cron entries
bash scripts/ops/remove-host-ops-cron.sh
```

Installed jobs (host cron timezone — typically UTC on cloud-hosted Linux):
- Every 10 minutes: disk monitor and Slack alert transitions
- Daily 02:25: artifact retention cleanup (`app:test:cleanup --days=14`)
- Sunday 03:40: safe Docker prune (no volume prune)

### Alert Thresholds

- Warning: `85%`
- Critical: `92%`

Thresholds can be overridden via environment variables:
- `WARN_THRESHOLD`
- `CRIT_THRESHOLD`
- `ENV_FILE` (for alternate `.env` path)

### Manual Verification

```bash
# Check root disk usage
df -h /

# Run monitor script once
bash scripts/ops/disk-monitor-alert.sh

# View monitor logs
tail -n 100 /home/ubuntu/matre/var/log/disk-monitor.log

# Show current Docker footprint
docker system df
```

### Expected Alert Behavior

- Sends alert only on state transition (`ok -> warning`, `warning -> critical`, etc.)
- Sends recovery alert on transition back to `ok`
- Does not send repeated alerts while state is unchanged

---

## Container Status

### Quick Status

```bash
docker compose ps
```

### Detailed Status

```bash
docker compose ps --format "table {{.Name}}\t{{.Status}}\t{{.Ports}}"
```

### Resource Usage

```bash
docker stats --no-stream
```

---

## Common Issues

See [Troubleshooting Guide](troubleshooting.md) for solutions to:
- Selenium Grid disconnected
- Allure service unresponsive
- Worker not processing
- Queue backlog
