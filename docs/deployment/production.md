# Production Deployment

This guide covers deploying MATRE to a production server. MATRE is designed to run as a single Docker compose stack — no separate PHP/nginx host installation required.

> The day-to-day **operations** docs ([Disaster Recovery](../operations/disaster-recovery.md), [Backup & Restore](../operations/backup-restore.md), [Docker Engine](docker-engine.md)) cover what happens after the initial deployment.

---

## Server Requirements

| | |
|---|---|
| **OS** | Ubuntu 22.04 LTS or 24.04 LTS (recommended) |
| **CPU/RAM** | 4 vCPU / 16 GB RAM minimum (more if running >4 parallel workers) |
| **Disk** | 200 GB+ root volume (Docker images alone are ~45 GB; allocate room for Allure history) |
| **Docker Engine** | Docker CE 29.x via apt (NOT snap — see [Docker Engine](docker-engine.md)) |
| **Docker Compose plugin** | v2 (the `docker compose` subcommand) |
| **Domain** | A record pointing to the server (for Let's Encrypt) |
| **Open ports** | 80, 443 (web); 22 (SSH from operator IPs) |

The application stack runs entirely in containers — host-side PHP, Nginx, MariaDB are NOT required.

---

## Initial Server Provisioning

### 1. Install Docker CE (apt, not snap)

Ubuntu may ship snap docker by default. **Do not use snap docker for production** — see [Docker Engine](docker-engine.md#why-apt-over-snap) for the reason.

```bash
# Remove snap docker if present
sudo snap remove docker || true

# Install apt prerequisites
sudo apt-get update
sudo apt-get install -y ca-certificates curl gnupg

# Add Docker's official GPG key
sudo install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | \
  sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg
sudo chmod a+r /etc/apt/keyrings/docker.gpg

# Add Docker repo
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] \
  https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "$VERSION_CODENAME") stable" | \
  sudo tee /etc/apt/sources.list.d/docker.list

# Install
sudo apt-get update
sudo apt-get install -y docker-ce docker-ce-cli containerd.io \
  docker-buildx-plugin docker-compose-plugin

# Hold versions to prevent unattended-upgrades from breaking the engine
sudo apt-mark hold docker-ce docker-ce-cli docker-ce-rootless-extras \
  containerd.io docker-buildx-plugin docker-compose-plugin

# Add deploy user to docker group
sudo usermod -aG docker $USER
```

### 2. Verify

```bash
docker --version             # Docker version 29.x.x
docker compose version       # Docker Compose version v2.x.x
docker info | grep -E 'Server Version|Storage Driver|Cgroup Driver'
# Server Version: 29.x.x
# Storage Driver: overlay2
# Cgroup Driver: systemd
```

### 3. Required composer packages

The Redis lock DSN used in production requires `predis/predis`. It's already in `composer.json` — make sure it installs successfully on first build.

---

## Application Setup

### 1. Clone Repository

```bash
git clone https://github.com/good-yellow-bee/matre.git ~/matre
cd ~/matre
```

### 2. Configure `.env`

Copy and edit production environment:

```bash
cp .env.example .env
```

Production-required settings:

```dotenv
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=<generate via: openssl rand -base64 32>

# DB credentials (used by docker compose to seed the matre_db container)
DB_HOST=db
DB_PORT=3306
DB_NAME=matre
DB_USER=matre
DB_PASS=<strong-random-password>
DB_ROOT_PASSWORD=<strong-random-password>

# Public URLs — replace with your real domain
APP_DOMAIN=<production-domain>
ALLURE_PUBLIC_URL=https://<production-domain>/allure
NOVNC_URL=https://<production-domain>/novnc

# Let's Encrypt
LETSENCRYPT_EMAIL=<ops-email-for-cert-renewal>
CERT_RESOLVER=letsencrypt

# Redis lock DSN (required for multiple workers — flock does NOT work)
LOCK_DSN=redis://magento-redis:6379

# Selenium
SE_NODE_MAX_SESSIONS=4
SE_VNC_NO_PASSWORD=false

# Test module repository
TEST_MODULE_REPO=git@github.com:your-org/your-mftf-tests.git
TEST_MODULE_BRANCH=main

# Magento Marketplace (for MFTF binary install)
MAGENTO_PUBLIC_KEY=<your-key>
MAGENTO_PRIVATE_KEY=<your-key>

# Notifications (optional)
SLACK_WEBHOOK_URL=https://hooks.slack.com/services/...
```

⚠️ **Never commit `.env`.** Back it up via secrets manager (1Password, AWS SSM, etc.) — see [Backup & Restore](../operations/backup-restore.md#secrets-env).

### 3. SSH Key for Test Module Repository

If your test module repo is private, mount an SSH key into the php container. See `docker/ssh/README.md` for details. Quick version:

```bash
mkdir -p ~/matre/docker/ssh
ssh-keygen -t ed25519 -f ~/matre/docker/ssh/id_ed25519 -N ""
# Add the public key to your test module repo's deploy keys
cat ~/matre/docker/ssh/id_ed25519.pub
# Restrict permissions
chmod 600 ~/matre/docker/ssh/id_ed25519
```

### 4. Validate SSL Configuration

Before bringing up Traefik, verify Let's Encrypt config:

```bash
docker compose exec php php bin/console app:validate-ssl-config
```

(After first start — see below.)

### 5. Initial Start

```bash
./prod.sh start
```

This runs `docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d` with the `production` profile (which activates the embedded Traefik for SSL termination), runs migrations, and warms cache.

### 6. Create Admin User

```bash
./prod.sh console app:create-admin
```

Follow the interactive prompts.

### 7. Verify

```bash
curl -I https://<production-domain>/admin
# Expect: HTTP/2 302 → /login
```

---

## Day-to-Day Deployment

The `./prod.sh` script wraps the common operations. See [CLI Reference](../operations/cli-reference.md) for the full subcommand list.

### Typical PHP-only deploy (most common)

For PHP code changes, with or without migrations:

```bash
ssh <production-server> "cd ~/matre && git pull origin master && \
  docker compose exec -T php php bin/console doctrine:migrations:migrate --no-interaction && \
  docker compose exec -T php php bin/console cache:clear --env=prod && \
  docker compose restart test-worker scheduler"
```

Code is volume-mounted in production, so `git pull` + cache clear + worker restart is sufficient. No image rebuild needed.

### Frontend (Vue/JS/CSS) deploy

Frontend assets are baked into the app image, then synced to the host's `public/build/`:

```bash
./prod.sh frontend
# OR force a fresh build (use after package.json / vite.config.mjs / tailwind.config.js changes)
./prod.sh frontend --no-cache
```

The script:
1. Builds the `frontend_build` Docker stage
2. Validates the manifest (checks `manifest.json` + Tailwind CSS size > 10 KB)
3. Atomically swaps `public/build/` (with rollback to `public/build.old/`)
4. Clears Symfony cache

To roll back a bad frontend deploy:
```bash
./prod.sh frontend-rollback
```

### Composer dependency change

Requires a full image rebuild:

```bash
./prod.sh update
```

### Docker / `docker-compose.yml` change

Same — `./prod.sh update` does a `pull`, `up -d --force-recreate`, migrate, cache clear.

---

## Decision Table

| Change Type | Command |
|---|---|
| PHP code only (no DB schema change) | `git pull` + `docker compose restart test-worker` |
| PHP code + migration | `git pull` + migrate + restart workers |
| Composer dependency | `./prod.sh update` |
| Vue/JS/CSS only | `./prod.sh frontend` |
| Docker / compose config | `./prod.sh update` |
| Docker engine itself | See [Docker Engine](docker-engine.md#planned-engine-upgrade) |

---

## Operational Hardening

After initial deployment, set up:

1. **EBS / volume snapshots** — daily or weekly. See [Backup & Restore](../operations/backup-restore.md).
2. **Disk monitoring + Slack alerts**:
   ```bash
   bash scripts/ops/install-host-ops-cron.sh
   ```
   Installs cron entries for disk monitoring, artifact retention, and safe Docker prune.
3. **Journald cap** — prevent log files filling the disk:
   ```bash
   sudo tee /etc/systemd/journald.conf.d/matre-cap.conf <<'EOF'
   [Journal]
   SystemMaxUse=500M
   SystemKeepFree=1G
   EOF
   sudo systemctl restart systemd-journald
   ```
4. **Daily artifact cleanup cron** at `/etc/cron.d/matre-cleanup` running `scripts/cleanup-cron.sh` (60-day retention).

---

## Verifying Production Health

```bash
# URL responds
curl -sI -k https://<production-domain>/admin | head -1
# Expect: HTTP/2 302

# All containers up
docker ps --format 'table {{.Names}}\t{{.Status}}'

# Daemon healthy with standard data root
docker info --format '{{.ServerVersion}} | {{.DockerRootDir}} | {{.Driver}}'
# Expect: 29.x | /var/lib/docker | overlay2

# Restart policies live on critical services
docker inspect matre_php matre_db matre_traefik_prod \
  --format '{{.Name}}: {{.HostConfig.RestartPolicy.Name}}'
# Expect: all "unless-stopped"

# Disk healthy
df -h /
docker system df
```

---

## SSL with Let's Encrypt (built-in via Traefik)

The `production` profile activates a Traefik reverse proxy with automatic Let's Encrypt SSL.

### How it works

- **Profile activation**: `--profile production` (which `./prod.sh start` passes)
- **HTTP-01 challenge**: Traefik requests certs via Let's Encrypt
- **Auto-renewal**: Traefik renews certs before expiry
- **HTTP redirect**: All HTTP traffic redirected to HTTPS
- **Persistent storage**: Certificates stored in the `traefik_certs` Docker volume

### Prerequisites

- Domain DNS A record points to the server's public IP
- Ports 80 and 443 open in security group / firewall
- No other service binding ports 80/443

### Troubleshooting SSL

```bash
# Traefik logs
docker logs matre_traefik_prod

# DNS resolution
dig +short <production-domain>

# HTTP challenge endpoint
curl http://<production-domain>/.well-known/acme-challenge/test
```

If you hit Let's Encrypt rate limits during testing, point Traefik at the staging CA in `docker/traefik/traefik.yml`:

```yaml
acme:
  caServer: https://acme-staging-v02.api.letsencrypt.org/directory
```

---

## See Also

- [Docker Engine](docker-engine.md) — engine choice, holds, restart policies, snap migration
- [Disaster Recovery](../operations/disaster-recovery.md) — incident playbook
- [Backup & Restore](../operations/backup-restore.md) — backup procedures and restoration
- [CLI Reference](../operations/cli-reference.md) — all available commands
- [Troubleshooting](../operations/troubleshooting.md) — common issues
