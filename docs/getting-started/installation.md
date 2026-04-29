# Installation Guide

> **Want to run your first test quickly?** See the [Quick Start Guide](./quick-start.md) for a streamlined walkthrough.

> **Starting from scratch?** See the [MFTF Setup Guide](./mftf-setup.md) for complete end-to-end instructions including test module creation.

## Prerequisites

**Docker (Recommended):**
- Docker and Docker Compose

**Manual Setup:**
- PHP 8.5+ with extensions: mbstring, xml, intl, pdo_mysql, gd, zip
- Composer 2.8+
- Node.js 20+
- MySQL 8.0+ or MariaDB 11+
- Git

---

## Docker Setup (Recommended)

### 1. Clone and Start

```bash
git clone https://github.com/good-yellow-bee/matre.git
cd matre
docker compose up -d --build
```

This starts all services:

| Service | Description | Port |
|---------|-------------|------|
| php (`matre_php`) | PHP 8.5 FPM | - |
| nginx (`matre_nginx`) | Web server | 8089 |
| db (`matre_db`) | MariaDB 11 | 33067 |
| mailpit (`matre_mailpit`) | Email testing | 1031 (SMTP), 8031 (UI) |
| frontend-build (`matre_frontend_build`) | Vite asset builder | - |
| scheduler (`matre_scheduler`) | Cron job worker | - |
| test-worker | Test execution worker | - |
| selenium-hub (`matre_selenium_hub`) | Selenium Grid hub | 4444 |
| chrome-node | Chrome/Chromium browser node | dynamic noVNC port |
| playwright (`matre_playwright`) | Playwright runner | - |
| magento-opensearch (`matre_magento_opensearch`) | OpenSearch 2.14 (Magento search) | - |
| allure (`matre_allure`) | Allure report service | 5050 |
| magento (`matre_magento`) | Magento MFTF environment | - |

### 2. Frontend Build

The `matre_frontend_build` container automatically runs on startup:
- Image: `node:20-alpine`
- Command: `npm install && npm run build`
- Output: `public/build/`

The PHP container depends on frontend-build, so assets are ready before the app starts.

### 3. Database Setup

```bash
# Create database schema
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction

# Load sample data
docker compose exec php php bin/console doctrine:fixtures:load --no-interaction
```

### 4. Access the Application

- **Admin Panel:** http://localhost:8089
- **Allure Reports:** http://localhost:5050
- **Selenium Grid:** http://localhost:4444
- **Mailpit UI:** http://localhost:8031
- **Database:** localhost:33067 (user: matre, password: matre)

**Default login:** `admin` / `admin123`

### Common Docker Commands

```bash
# Start containers
docker compose up -d

# Stop containers
docker compose down

# View logs
docker compose logs -f php
docker compose logs -f test-worker
docker compose logs -f scheduler

# Run Symfony commands
docker compose exec php php bin/console <command>

# Open shell in PHP container
docker compose exec php sh

# Rebuild containers
docker compose up -d --build

# Remove volumes (reset database)
docker compose down -v
```

---

## Manual Setup

### 1. Install Dependencies

```bash
composer install
npm install
```

### 2. Configure Environment

```bash
cp .env.example .env.local
```

Edit `.env.local`:
```dotenv
DB_NAME=matre
DB_USER=matre
DB_PASS=matre
DB_HOST=127.0.0.1
DB_PORT=3306
MAILER_DSN=smtp://localhost:1025
```

### 3. Setup Database

```bash
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate
php bin/console doctrine:fixtures:load
```

### 4. Build Frontend

```bash
# Development with HMR
npm run dev

# Production build
npm run build
```

### 5. Start Server

```bash
symfony server:start
# OR
php -S localhost:8000 -t public/
```

---

## Frontend Development

For hot module replacement during development:

```bash
npm run dev -- --host 127.0.0.1 --port 5173
```

Vite dev server runs on http://localhost:5173 with HMR enabled.

The Twig helpers (`vite_entry_script_tags`) automatically detect dev mode and serve from Vite.

---

## Test Infrastructure

MATRE includes a complete test infrastructure:

### Selenium Grid
- Hub service: `selenium-hub` (`matre_selenium_hub`) on port 4444
- Browser service: `chrome-node`; local session count defaults to 1 via `SE_NODE_MAX_SESSIONS`

### Playwright
- Service: `playwright` (`matre_playwright`)
- Results: `var/playwright-results/`

### Magento (MFTF)
- Service: `magento` (`matre_magento`)
- Results: `var/mftf-results/`

### Allure Reports
- Service: `allure` (`matre_allure`) on port 5050
- Results: `var/allure-results/`

---

## Troubleshooting

### "Database connection failed"
- Check DB_* variables in `.env.local`
- Ensure MySQL/MariaDB is running
- Verify credentials

### "Class not found"
```bash
composer dump-autoload
php bin/console cache:clear
```

### "Migration already executed"
```bash
php bin/console doctrine:migrations:status
php bin/console doctrine:migrations:version --delete <version>
```

### Docker: "Port already in use"
```bash
# Find process using port
lsof -i :8089

# Or change port in docker-compose.yml
```

### Test worker not processing
```bash
# Check worker logs
docker compose logs -f test-worker

# Restart worker
docker compose restart test-worker
```

---

## Git Access for Private Repositories

MATRE clones your test module repository on each test run. For private repos, configure authentication.

### Option 1: SSH Key (Recommended)

SSH keys provide secure, password-less access to Git repositories.

**1. Generate SSH Key (if needed)**

```bash
# Generate new key
ssh-keygen -t ed25519 -C "matre@local" -f ~/.ssh/matre_key

# Or use existing key
ls ~/.ssh/id_*
```

**2. Add Public Key to Git Provider**

Copy public key and add to GitHub/GitLab/Bitbucket:

```bash
cat ~/.ssh/matre_key.pub
# or
cat ~/.ssh/id_ed25519.pub
```

- **GitHub:** Settings → SSH Keys → New SSH Key
- **GitLab:** Preferences → SSH Keys → Add Key
- **Bitbucket:** Personal Settings → SSH Keys → Add Key

**3. Mount SSH Key in Docker**

Add to `docker-compose.yml` under `php` service:

```yaml
services:
  php:
    volumes:
      - ~/.ssh:/root/.ssh:ro  # Mount SSH keys (read-only)
```

Or mount specific key:

```yaml
volumes:
  - ~/.ssh/matre_key:/root/.ssh/id_ed25519:ro
  - ~/.ssh/known_hosts:/root/.ssh/known_hosts:ro
```

**4. Configure Known Hosts**

Pre-populate known hosts to avoid interactive prompts:

```bash
# Add GitHub/GitLab/Bitbucket to known_hosts
ssh-keyscan github.com >> ~/.ssh/known_hosts
ssh-keyscan gitlab.com >> ~/.ssh/known_hosts
ssh-keyscan bitbucket.org >> ~/.ssh/known_hosts
```

**5. Test SSH Access**

```bash
docker compose exec php ssh -T git@github.com
# Should see: "Hi username! You've successfully authenticated..."
```

### Option 2: HTTPS with Token

For HTTPS repositories, use personal access tokens.

**1. Create Access Token**

- **GitHub:** Settings → Developer settings → Personal access tokens → Generate
  - Scope: `repo` (full repository access)
- **GitLab:** Preferences → Access Tokens → Add
  - Scope: `read_repository`
- **Bitbucket:** Personal Settings → App passwords → Create
  - Permission: Repository read

**2. Configure in `.env`**

```dotenv
TEST_MODULE_REPO=https://github.com/your-org/your-tests.git
REPO_USERNAME=your-username
REPO_PASSWORD=ghp_xxxxxxxxxxxx  # Your token
```

**3. Test HTTPS Access**

```bash
docker compose exec php git ls-remote $TEST_MODULE_REPO
```

### Troubleshooting Git Access

**"Permission denied (publickey)"**

```bash
# Check SSH key is mounted
docker compose exec php ls -la /root/.ssh/

# Verify key permissions
docker compose exec php chmod 600 /root/.ssh/id_*
docker compose exec php chmod 700 /root/.ssh

# Test SSH connection with verbose output
docker compose exec php ssh -vT git@github.com
```

**"Host key verification failed"**

```bash
# Add host to known_hosts
ssh-keyscan github.com >> ~/.ssh/known_hosts

# Or disable strict host checking (less secure)
docker compose exec php sh -c 'echo "StrictHostKeyChecking no" >> /root/.ssh/config'
```

**"Authentication failed" (HTTPS)**

- Verify token has `repo` scope
- Check token hasn't expired
- Ensure `REPO_USERNAME` and `REPO_PASSWORD` are set in `.env`

**Clone times out**

Large repositories may timeout. Options:

```dotenv
# Increase timeout (seconds)
GIT_CLONE_TIMEOUT=600

# Use shallow clone
GIT_CLONE_DEPTH=1
```

---

## ARM64 Support (M1/M2 Macs)

Docker uses Chromium instead of Chrome for ARM64 compatibility. Anti-bot flags enabled by default for testing on protected sites.
