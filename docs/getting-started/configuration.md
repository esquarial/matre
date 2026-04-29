# Configuration

## Environment Variables

Create `.env.local` for local overrides (never committed to git).

### Core Settings

```dotenv
# Application
APP_ENV=dev                    # dev, prod, test
APP_SECRET=your-secret-key     # Generate with: openssl rand -base64 32
APP_DEBUG=1                    # 1 for dev, 0 for prod

# Database
DB_NAME=matre
DB_USER=matre
DB_PASS=matre
DB_HOST=127.0.0.1
DB_PORT=3306

# Mailer
MAILER_DSN=smtp://localhost:1025  # Mailpit for dev
```

### Docker Environment

The `docker-compose.yml` sets these automatically:
```dotenv
DB_HOST=db
DB_NAME=matre
DB_USER=matre
DB_PASS=matre
MAILER_DSN=smtp://mailpit:1025
```

### Test Automation Settings

```dotenv
# Test Module Repository
TEST_MODULE_REPO=git@github.com:org/magento-module.git
TEST_MODULE_BRANCH=main
TEST_MODULE_PATH=app/code/Vendor/Module

# Repository credentials (for HTTPS repos)
REPO_USERNAME=
REPO_PASSWORD=

# Selenium Grid
SELENIUM_HOST=selenium-hub
SELENIUM_PORT=4444

# Live Browser Preview (noVNC)
NOVNC_URL=http://localhost:7900
SE_VNC_NO_PASSWORD=true

# Allure Reports
ALLURE_URL=http://allure:5050

# Magento Marketplace credentials
MAGENTO_PUBLIC_KEY=
MAGENTO_PRIVATE_KEY=

# Notifications
SLACK_WEBHOOK_URL=https://hooks.slack.com/services/XXX/YYY/ZZZ
MAILER_DSN=smtp://mailpit:1025

# SLACK_WEBHOOK_URL: Incoming webhook for Slack notifications (optional)
# MAILER_DSN: SMTP transport for email notifications
# See docs/operations/notifications.md for full configuration
```

---

## Docker Services

### php (`matre_php`)
- **Image:** Custom (from Dockerfile)
- **Target:** `app_dev` stage
- **Extensions:** GD, IntL, ZIP, PDO MySQL, GMP
- **Volumes:** Application code, vendor (named volume)

### nginx (`matre_nginx`)
- **Image:** `nginx:1.25-alpine`
- **Port:** 8089 → 80
- **Config:** `docker/nginx/default.conf`

### db (`matre_db`)
- **Image:** `mariadb:11`
- **Port:** 33067 → 3306
- **Credentials:**
  - Database: `matre`
  - User: `matre`
  - Password: `matre`
  - Root password: `matre_root`

### mailpit (`matre_mailpit`)
- **Image:** `axllent/mailpit:latest`
- **Ports:**
  - 1031 → 1025 (SMTP)
  - 8031 → 8025 (Web UI)
- **Usage:** All emails sent by the app appear in the web UI

### frontend-build (`matre_frontend_build`)
- **Image:** `node:20-alpine`
- **Command:** `npm install && npm run build`
- **Purpose:** Builds Vite assets on container startup
- **Output:** `public/build/`

### scheduler (`matre_scheduler`)
- **Image:** Custom (from Dockerfile)
- **Command:** `php bin/console messenger:consume async scheduler_test_runner scheduler_cron scheduled_test_messages --time-limit=60 -vv`
- **Purpose:** Processes scheduled test runs
- **Restart:** `unless-stopped`

### test-worker
- **Image:** Custom (from Dockerfile)
- **Command:** `php -d memory_limit=1G bin/console messenger:consume test_runner_per_env --time-limit=3600 -vv`
- **Purpose:** Executes test runs asynchronously
- **Restart:** `unless-stopped`

### selenium-hub (`matre_selenium_hub`)
- **Image:** `selenium/hub:4.40.0-20260120`
- **Ports:** 4442, 4443, 4444
- **Purpose:** Selenium Grid coordinator

### chrome-node
- **Image:** `selenium/node-chromium:4.40.0-20260120`
- **Port:** 7900 (noVNC live browser preview)
- **Purpose:** Chrome browser for MFTF tests
- **Sessions:** controlled by `SE_NODE_MAX_SESSIONS` (default: 1 locally)

### playwright (`matre_playwright`)
- **Image:** Custom (from `docker/playwright/Dockerfile`)
- **Purpose:** Playwright test execution
- **Volumes:** `var/playwright-results/`

### allure (`matre_allure`)
- **Image:** `frankescobar/allure-docker-service:latest`
- **Ports:** 5050, 5252
- **Purpose:** Allure report generation and serving

### magento (`matre_magento`)
- **Image:** Custom (from `docker/magento/Dockerfile`)
- **Purpose:** Magento 2 environment for MFTF execution
- **Volumes:** `var/mftf-results/`

---

## Security Configuration

### config/packages/security.yaml

Key settings:
```yaml
security:
    password_hashers:
        App\Entity\User:
            algorithm: bcrypt
            cost: 12

    firewalls:
        main:
            login_throttling:
                max_attempts: 5
                interval: '1 minute'

            form_login:
                enable_csrf: true

            remember_me:
                lifetime: 604800  # 1 week

            two_factor:
                auth_form_path: 2fa_login
                check_path: 2fa_login_check
```

---

## Messenger Configuration

### config/packages/messenger.yaml

```yaml
framework:
    messenger:
        transports:
            async:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
            test_runner_per_env:
                dsn: 'per-env-doctrine://default'
                options:
                    table_name: messenger_messages
            scheduled_test_messages:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                options:
                    queue_name: scheduled_test_messages

        routing:
            'App\Message\TestRunMessage': test_runner_per_env
            'App\Message\ScheduledTestRunMessage': scheduled_test_messages
```

---

## Vite Configuration

### vite.config.mjs

```javascript
export default defineConfig({
  build: {
    manifest: true,
    outDir: 'public/build',
    rollupOptions: {
      input: {
        app: './assets/app.js',
        admin: './assets/admin.js',
        // Vue islands
        'test-run-grid-app': './assets/vue/test-run-grid-app.js',
        // ... more entries
      },
    },
  },
  server: {
    port: 5173,
    host: 'localhost',
  },
});
```

Add new Vue islands to `rollupOptions.input`.

---

## Database Connection

### Docker
```bash
# Connect from host
mysql -h 127.0.0.1 -P 33067 -u matre -pmatre matre

# Connect from container
docker compose exec db mysql -u matre -pmatre matre
```

### GUI Clients
- Host: `127.0.0.1`
- Port: `33067`
- User: `matre`
- Password: `matre`
- Database: `matre`

---

## Test Environment Configuration

Test environments are configured in the database via the Admin UI:

![Test Environments](../images/test-environments.png)

| Field | Description |
|-------|-------------|
| `name` | Display name (e.g., "Staging") |
| `code` | Unique code (e.g., "staging") |
| `baseUrl` | Magento base URL |
| `backendName` | Admin path (default: "admin") |
| `adminUsername` | Magento admin username |
| `adminPassword` | Magento admin password |
| `envVariables` | JSON object of custom env vars |

### Custom Variables Example

```json
{
  "MAGENTO_BASE_URL": "https://staging.example.com",
  "MAGENTO_BACKEND_NAME": "admin_secret",
  "CUSTOM_VAR": "value"
}
```

These variables are exported to the test environment during execution.

---

## Live Browser Preview

Watch test execution in real-time via browser-based VNC viewer (noVNC).

![noVNC Preview](../images/novnc-preview.png)

### Configuration

```dotenv
# noVNC viewer URL (Selenium chrome-node exposes port 7900)
NOVNC_URL=http://localhost:7900

# Disable VNC password for dev (set to false for prod)
SE_VNC_NO_PASSWORD=true
```

| Variable | Description | Default |
|----------|-------------|---------|
| `NOVNC_URL` | Full URL to noVNC viewer | `http://localhost:7900` |
| `SE_VNC_NO_PASSWORD` | Disable password prompt | `true` (dev) |

### Production

For production, set password protection:

```dotenv
SE_VNC_NO_PASSWORD=false
```

Default VNC password: `secret`

### Usage

1. Start a test run
2. Go to Test Run detail page
3. Click **Watch Live** button (visible during execution)
4. Browser tab opens with live view of Selenium browser
