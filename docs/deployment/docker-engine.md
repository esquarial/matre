# Docker Engine — apt-only, locked, restart-resilient

MATRE production deployments must run **Docker CE installed via apt** (`docker-ce` package), not snap docker. This is a hard constraint — see [Why apt over snap](#why-apt-over-snap) below.

---

## Recommended state

| Component | Version (target) | Source |
|---|---|---|
| dockerd | 29.x | apt `docker-ce` |
| docker CLI | 29.x | apt `docker-ce-cli` |
| containerd | 2.x | apt `containerd.io` (system-managed) |
| docker-buildx-plugin | latest stable | apt |
| docker-compose-plugin | latest stable | apt |
| Storage driver | overlay2 | — |
| Cgroup driver | systemd | — |
| Data root | `/var/lib/docker` | (standard) |

All packages should be held from auto-upgrade:
```bash
apt-mark showhold | grep -E 'docker|containerd'
# docker-ce
# docker-ce-cli
# docker-ce-rootless-extras
# containerd.io
# docker-buildx-plugin
# docker-compose-plugin
```

If the host had snap docker historically, also ensure snap refresh is held:
```bash
snap refresh --time | grep docker   # should show "held" if snap docker exists
```

---

## Why apt over snap {#why-apt-over-snap}

Snap docker auto-refreshes packages without operator control. When the snap daemon refreshes the docker package, all containers are stopped during the daemon restart. Containers without `restart: unless-stopped` do NOT come back automatically — they stay stopped, taking the production stack offline.

**Snap downsides for a server like this**:

| | snap docker | apt docker-ce |
|---|---|---|
| Auto-updates | Forced by snapd, kills containers | Manual via `apt upgrade` |
| Update timing control | None (best-effort hold only) | Full |
| Filesystem location | `/var/snap/docker/common/var-lib-docker` (non-standard) | `/var/lib/docker` (standard, matches all docs) |
| Apparmor profiles | Restrictive — can cause "permission denied" stopping containers | Standard |
| Maintenance ergonomics | Worse | Better |
| Data root predictability | snap revisions can change paths | Stable |

If your deployment was historically on snap docker, see [Migration: snap → apt](#migration-snap--apt-historical) below.

---

## Maintenance procedures

### Routine: do nothing

The hold mechanism is intentional. Docker should NOT be auto-updated. Treat the engine as a long-lived service that only changes during planned maintenance.

### Planned engine upgrade

1. **Schedule a maintenance window** — engine restart drops all containers; restart policies bring them back, but expect 30-60s downtime.
2. **Verify current state**:
   ```bash
   ssh <production-server> "docker info --format '{{.ServerVersion}} | {{.DockerRootDir}}'"
   ssh <production-server> "apt-mark showhold | grep -E 'docker|containerd'"
   ```
3. **EBS snapshot** (defense in depth):
   ```bash
   aws ec2 create-snapshot --region <region> \
     --volume-id <volume-id> --description "pre-docker-upgrade-$(date -u +%Y%m%d)"
   ```
4. **Release holds**:
   ```bash
   ssh <production-server> "sudo apt-mark unhold docker-ce docker-ce-cli docker-ce-rootless-extras \
     containerd.io docker-buildx-plugin docker-compose-plugin"
   ```
5. **Stop the matre stack first** — avoids container restart-loop during daemon restart:
   ```bash
   ssh <production-server> "cd ~/matre && ./prod.sh stop"
   ```
6. **Upgrade**:
   ```bash
   ssh <production-server> "sudo apt update && sudo DEBIAN_FRONTEND=noninteractive apt install --only-upgrade -y \
     docker-ce docker-ce-cli docker-ce-rootless-extras containerd.io \
     docker-buildx-plugin docker-compose-plugin"
   ```
7. **Re-apply holds**:
   ```bash
   ssh <production-server> "sudo apt-mark hold docker-ce docker-ce-cli docker-ce-rootless-extras \
     containerd.io docker-buildx-plugin docker-compose-plugin"
   ```
8. **Bring the stack up**:
   ```bash
   ssh <production-server> "cd ~/matre && ./prod.sh start"
   ```
9. **Verify**:
   ```bash
   curl -sI -k https://<production-domain>/admin   # expect HTTP/2 302
   ssh <production-server> "docker info --format '{{.ServerVersion}}'"
   ssh <production-server> "docker ps --format 'table {{.Names}}\t{{.Status}}' | wc -l"
   ```

---

## Restart policies

All matre services declare `restart: unless-stopped` in `docker-compose.yml`. This is what lets the stack auto-recover from a daemon restart.

### Verify it's still in effect

For new containers (recreated since the policy was added):
```bash
ssh <production-server> "docker inspect matre_php matre_db matre_traefik_prod \
  --format '{{.Name}}: {{.HostConfig.RestartPolicy.Name}}'"
# Expect: all "unless-stopped"
```

If a container shows `no` (default), it was created before the policy was added. Recreate it:
```bash
ssh <production-server> "cd ~/matre && docker compose up -d --force-recreate <service>"
```

Or apply the policy live without recreating:
```bash
ssh <production-server> "docker update --restart unless-stopped \$(docker ps --format '{{.Names}}' | grep matre)"
```

---

## What "unless-stopped" actually means

| Daemon event | Container behavior with `unless-stopped` |
|---|---|
| `docker stop <container>` (manual) | Stays stopped on daemon restart |
| Container process crashes | Daemon restarts it |
| `docker compose down` | Container removed (policy is moot) |
| Daemon SIGTERM (graceful shutdown) | Container stops, marked "should run", restarted on daemon start |
| Daemon SIGKILL (crash) | Container forcibly stopped, marked "should run", restarted on daemon start |
| Host reboot | Daemon starts on boot, restarts container |

The `unless-stopped` semantic is what prevents a daemon-restart cascade: if the daemon dies for any reason short of a clean `docker stop`, containers come back when the daemon does.

---

## Forbidden actions

These will reintroduce the daemon-restart-cascade failure mode:

| Action | Why forbidden |
|---|---|
| `sudo snap install docker` | Snap auto-refresh will kill containers |
| `sudo apt-mark unhold docker-ce` (without immediate maintenance) | Unattended-upgrades will silently bump engine, kill containers |
| Removing `restart: unless-stopped` from `docker-compose.yml` | Loses auto-recovery for that service |
| Pinning containerd to a version incompatible with installed docker-ce | Daemon won't start; full outage |
| Adding a service to compose without a restart policy | Single point of failure on next engine event |

---

## Disabling snap docker if it's been re-enabled

If snap docker somehow gets re-enabled (e.g. a mistaken `apt install snapd` cycle re-activates it), here's the disable sequence:

```bash
ssh <production-server> "sudo systemctl stop snap.docker.dockerd.service"
ssh <production-server> "sudo snap disable docker"
ssh <production-server> "sudo snap refresh --hold docker"
# Verify apt docker is still primary:
ssh <production-server> "ls -la /var/run/docker.sock; docker info --format '{{.ServerVersion}}'"
```

---

## Migration: snap → apt (historical)

If a deployment was originally provisioned with snap docker and needs to migrate to apt-only, the procedure is:

### Phase 0 — Investigation (no downtime)

1. Confirm which daemon CLI talks to:
   ```bash
   docker info --format '{{.ServerVersion}} | DockerRootDir={{.DockerRootDir}}'
   # If DockerRootDir starts with /var/snap/... → snap is active
   ```
2. Confirm storage backend:
   ```bash
   docker info | grep -E 'Storage Driver|Image Store'
   # If "Storage Driver: overlay2" and no "Image Store: containerd image store" → simple migration
   # If containerd image store is enabled → migration is more complex (image data lives outside data-root)
   ```
3. Check whether apt docker-ce + containerd.io are also installed (often co-installed but idle):
   ```bash
   dpkg -l | grep -E 'docker-ce|containerd'
   systemctl is-active docker.service containerd.service snap.docker.dockerd.service
   ```

### Phase 1 — Add restart policies first (no downtime)

Before migrating, ensure all services in `docker-compose.yml` have `restart: unless-stopped`. Apply live with `docker update` so the change takes effect without recreating containers.

### Phase 2 — Migration (~3-5 min downtime)

1. Stop and disable any orphaned apt daemons running alongside snap (they typically aren't serving anything):
   ```bash
   sudo systemctl stop docker.service docker.socket containerd.service
   sudo systemctl disable docker.service docker.socket containerd.service
   ```
2. Take an EBS snapshot (defensive). Wait for `State: completed`.
3. Take application-consistent DB backups via `mariadb-dump`.
4. Save inventory: `docker images > images.txt; docker volume ls > volumes.txt; docker network ls > networks.txt` (so you can verify nothing is lost).
5. Upgrade apt docker-ce + containerd.io to match the snap version (or at least the same major):
   ```bash
   sudo apt install --only-upgrade docker-ce docker-ce-cli docker-ce-rootless-extras \
     containerd.io docker-buildx-plugin docker-compose-plugin
   ```
6. Move pre-existing apt data aside (it's typically idle leftovers from earlier installation):
   ```bash
   sudo mv /var/lib/docker /var/lib/docker.preexisting-backup
   sudo mkdir /var/lib/docker
   sudo chmod 710 /var/lib/docker
   ```
7. Initial live rsync of snap data → `/var/lib/docker` (overlay2 + classic image dir, NOT containerd image store; otherwise see step 0):
   ```bash
   sudo rsync -aHAXS --numeric-ids /var/snap/docker/common/var-lib-docker/ /var/lib/docker/
   ```
   This runs while production continues. Takes ~3-5 minutes per 10 GB.
8. **Downtime starts**: stop the stack and snap dockerd:
   ```bash
   cd ~/matre && ./prod.sh stop
   sudo systemctl stop snap.docker.dockerd.service
   ```
9. Final delta rsync (small, fast):
   ```bash
   sudo rsync -aHAXS --numeric-ids --delete /var/snap/docker/common/var-lib-docker/ /var/lib/docker/
   ```
10. Disable snap, enable + start apt:
    ```bash
    sudo snap disable docker
    sudo systemctl enable docker.service docker.socket containerd.service
    sudo systemctl start containerd.service
    sudo systemctl start docker.service
    ```
11. Verify daemon target + inventory matches:
    ```bash
    docker info --format '{{.ServerVersion}} | {{.DockerRootDir}} | {{.Driver}}'
    # Expect: 29.x | /var/lib/docker | overlay2
    docker images | wc -l           # match images.txt + 1 header
    docker volume ls | wc -l        # match volumes.txt + 1 header
    docker network ls | wc -l       # match networks.txt + 1 header
    ```
12. **Downtime ends**: bring stack up:
    ```bash
    cd ~/matre && ./prod.sh start
    ```
13. Verify URL.

### Phase 3 — Lockdown

```bash
sudo apt-mark hold docker-ce docker-ce-cli docker-ce-rootless-extras \
  containerd.io docker-buildx-plugin docker-compose-plugin
sudo snap refresh --hold docker
```

### Phase 4 — Cleanup (after ≥1 week stable)

```bash
sudo snap remove docker
sudo rm -rf /var/snap/docker /var/lib/docker.preexisting-backup
# Reclaims tens of GB
```

---

## References

- [Disaster Recovery](../operations/disaster-recovery.md) — what to do when something breaks
- [Production Deployment](production.md) — day-to-day deployment workflow
- [Backup & Restore](../operations/backup-restore.md) — backups, snapshots, restores
