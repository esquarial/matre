# Notifications

## Overview

MATRE sends notifications after test runs complete via:
- **Slack**: Single webhook message per run (shared channel)
- **Email**: Individual emails per subscribed user

Notifications fire asynchronously in the 5-phase pipeline (PHASE_NOTIFY), after reports are generated.

---

## Configuration

### Environment Variables

| Variable | Required | Example |
|----------|----------|---------|
| `SLACK_WEBHOOK_URL` | No | `https://hooks.slack.com/services/XXX/YYY/ZZZ` |
| `MAILER_DSN` | Yes | `smtp://mailpit:1025` |

**Slack**: Create an [Incoming Webhook](https://api.slack.com/messaging/webhooks) in your Slack workspace. Leave empty to disable Slack notifications.

**Email**: Configure SMTP transport. In development, Mailpit captures all emails at http://localhost:8031.

### User Preferences

Each user configures their notification preferences in Admin > Users > Edit:

| Field | Default | Description |
|-------|---------|-------------|
| `notificationsEnabled` | false | Master toggle - must be enabled |
| `notificationTrigger` | 'failures' | When to notify: `all` or `failures` only |
| `notifyByEmail` | true | Receive email notifications |
| `notifyBySlack` | true | Include in Slack notifications |
| `notificationEnvironments` | [] | Which environments to monitor |

**Trigger Logic:**
- `failures`: Notified only when test run has failures (failed > 0 OR status = FAILED)
- `all`: Notified on every completed test run

---

## Pipeline Integration

```
PREPARE → EXECUTE → REPORT → NOTIFY → CLEANUP
                              ↑
                         Notifications sent here
```

Notifications are dispatched by `TestRunMessageHandler::handleNotify()`:

1. Query users subscribed to the test run's environment
2. Filter by notification preferences (enabled, trigger type, channel)
3. Send single Slack message if any user wants Slack
4. Send individual emails to each subscribed user

---

## Message Content

### Slack

Rich attachment with:
- **Color**: Green (passed), orange (has failures), red (run failed)
- **Emoji**: White check mark / warning / X based on status
- **Fields**: Run ID, environment, test type, duration, result counts
- **Link**: Allure report URL (if available)

Example:
```
✅ Test Run #42 Completed
Environment: Staging
Type: MFTF + Playwright
Duration: 5m 23s
Results: 95 passed, 2 failed, 3 skipped
Report: http://allure:5050/...
```

### Email

HTML-formatted email with:
- Same details as Slack
- Full error message in `<pre>` block (if run failed)
- Styled table layout

---

## Technical Details

### Message Queue

| Setting | Value |
|---------|-------|
| Queue | `test_runner_per_env` |
| Transport | Doctrine |
| Retry | 2 attempts |
| Config | `config/packages/messenger.yaml` |

### Slack Retry Logic

Built-in exponential backoff:
- Retries: 3
- Initial delay: 500ms
- Multiplier: 2x
- Max delay: ~2 seconds

### Key Files

| File | Purpose |
|------|---------|
| `src/Service/NotificationService.php` | Send Slack/Email |
| `src/MessageHandler/TestRunMessageHandler.php` | Trigger point (lines 121-137) |
| `src/Repository/UserRepository.php` | User subscription queries (lines 244-288) |
| `src/Entity/User.php` | Preference fields (lines 112-146) |

---

## Troubleshooting

### Slack Not Sending

1. **Check env var**: Verify `SLACK_WEBHOOK_URL` is set in `.env`
2. **Test webhook**:
   ```bash
   curl -X POST -H 'Content-type: application/json' \
     --data '{"text":"Test"}' \
     $SLACK_WEBHOOK_URL
   ```
3. **Check logs**: `docker compose logs test-worker | grep -i slack`

### Email Not Sending

1. **Check env var**: Verify `MAILER_DSN` in `.env`
2. **Check worker**: `docker compose ps` - ensure `test-worker` is running
3. **Check Mailpit**: http://localhost:8031 shows captured emails in dev

### User Not Receiving Notifications

1. **Master toggle**: User must have `notificationsEnabled = true`
2. **Environment subscription**: User must be subscribed to the test run's environment
3. **Trigger match**: If trigger is `failures`, run must have failures
4. **Channel enabled**: `notifyByEmail` or `notifyBySlack` must be true

### Queue Issues

Check message queue status:
```bash
docker compose exec php php bin/console messenger:stats
docker compose exec php php bin/console messenger:failed:show
```
