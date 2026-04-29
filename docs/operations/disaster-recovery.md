# Disaster Recovery Runbook

Step-by-step recovery procedures for production incidents. Read top to bottom and pick the matching scenario.

> Operators of a specific MATRE deployment should keep their concrete identifiers (domain, instance ID, AWS profile, EBS volume) in a separate private runbook. The procedures below assume those values are known.

---

## Triage — Where Did It Break?

| Symptom | Section |
|---|---|
| App URL returns 404 (text/plain, ~19 bytes) | [Stack Down](#stack-down) |
| App URL returns 502/504 | [App Layer Down](#app-layer-down) |
| URL times out at TLS / no response on port 443 | [Network or Instance Down](#instance-impaired) |
| Containers exit on Docker daemon restart / auto-update | [Docker Daemon Restart Cascade](#docker-daemon-restart) |
| MariaDB fails with "Can't lock aria control file" | [Database Stale Locks](#db-stale-locks) |
| Container creation fails: "container with given ID already exists" | [Stale Container Shims](#stale-shims) |
| "permission denied" stopping containers | [Permission Denied on Stop](#permission-denied-stop) |
| Disk full alerts | [Disk Full](#disk-full) |
| Allure reports unresponsive | [Allure Down](#allure-down) |

---

## Stack Down {#stack-down}

**Symptom**: 404 plaintext from Traefik for every URL — no router matches because backend services aren't running.

**Diagnose**:
```bash
ssh <production-server> "cd ~/matre && docker compose ps"
```
If only `traefik` is up, the rest of the stack stopped.

**Recovery**:
```bash
ssh <production-server> "cd ~/matre && ./prod.sh start"
```

If `prod.sh start` fails with "container with given ID already exists" → see [Stale Shims](#stale-shims) first, then re-run.

If db fails with lock error → see [DB Stale Locks](#db-stale-locks) first, then re-run.

**Verify**:
```bash
curl -sI -k https://<production-domain>/admin
# Expect: HTTP/2 302 → /login
```

---

## App Layer Down {#app-layer-down}

**Symptom**: Traefik responds with 502/504. Backend (php/nginx) container is stopped or unhealthy.

**Diagnose**:
```bash
ssh <production-server> "docker ps --format 'table {{.Names}}\t{{.Status}}'"
```

**Common causes & fixes**:

| Cause | Fix |
|---|---|
| Nginx caching dead PHP IP | `docker compose restart nginx` |
| PHP container OOM | Check `docker logs matre_php`; increase `memory_limit` in `php.ini` |
| Worker crash | `docker compose restart test-worker scheduler` |

---

## Instance Impaired {#instance-impaired}

**Symptom**: SSH times out, all TCP probes fail, web URL hangs at TLS.

**Quick path** (replace placeholders with your deployment's values):
```bash
# 1. Diagnose at AWS API level
aws ec2 describe-instance-status --region <region> --instance-ids <instance-id>

# 2. Console log (sees kernel panic / OOM / disk full)
aws ec2 get-console-output --region <region> --instance-id <instance-id> \
  --output text | tail -100

# 3. Snapshot BEFORE destructive recovery
aws ec2 create-snapshot --region <region> \
  --volume-id <volume-id> --description "incident-$(date -u +%Y%m%d)"

# 4. Stop + start (data preserved on EBS, EIP stays attached)
aws ec2 stop-instances  --region <region> --instance-ids <instance-id>
aws ec2 start-instances --region <region> --instance-ids <instance-id>

# 5. Bring stack up after instance reachable
ssh <production-server> "cd ~/matre && ./prod.sh start"
```

**Notes**:
- Cloud-init auto-expands the root partition on boot if EBS was resized.
- Elastic IP stays attached through stop/start — DNS unchanged.
- First snapshot of a fresh volume can take 30-60 min. Incremental snapshots: minutes.

---

## Docker Daemon Restart Cascade {#docker-daemon-restart}

**Symptom**: Stack went dark. Most containers `Exited (255)` or `Exited (128)` simultaneously. Reason: docker engine restarted (manual / package update / OOM) and containers without `restart: unless-stopped` did not return.

**Should NOT happen on a properly configured deployment** because:
1. All services in `docker-compose.yml` have `restart: unless-stopped`
2. `apt-mark hold` blocks docker-ce auto-upgrades (see [Docker Engine](../deployment/docker-engine.md))
3. If snap docker is installed, `snap refresh --hold docker` blocks snap re-enablement

If it does happen anyway:

**Diagnose**:
```bash
ssh <production-server> "sudo journalctl -u docker.service --since '30 minutes ago' | tail -100"
ssh <production-server> "docker ps -a --format 'table {{.Names}}\t{{.Status}}\t{{.RunningFor}}' | head -30"
```

If most are `Exited (...) X minutes ago` simultaneously → daemon restarted.

**Recovery**:
```bash
ssh <production-server> "cd ~/matre && ./prod.sh start"
```

**Find root cause** (so it doesn't repeat):
```bash
# Did snap try to refresh? (should be held)
ssh <production-server> "snap refresh --time && snap list docker 2>/dev/null"

# Was apt-mark hold disturbed?
ssh <production-server> "apt-mark showhold | grep -E 'docker|containerd'"

# Manual restart? (someone running update)
ssh <production-server> "last -n 20"
```

**Reference**: snap docker auto-refresh is a known cause of this cascade. The migration to apt-only docker-ce (with all docker packages held) is documented in [Docker Engine](../deployment/docker-engine.md).

---

## Database Stale Locks {#db-stale-locks}

**Symptom**: After daemon restart, db container fails to start with:
```
[ERROR] mariadbd: Can't lock aria control file '/var/lib/mysql/aria_log_control' for exclusive use
[ERROR] InnoDB: Unable to lock ./ibdata1 error: 11
```

**Cause**: An orphan `mariadbd` process from the previous container is still holding the lock files. Happens when dockerd was killed without graceful container shutdown.

**Diagnose**:
```bash
# Find what's holding the lock (replace volume name as needed)
ssh <production-server> "sudo lsof /var/lib/docker/volumes/matre_matre_db_data/_data/ibdata1 2>/dev/null | head -5"
# If output shows mariadbd PID(s), those are orphans

ssh <production-server> "ps auxf | grep -iE 'maria|mysql' | grep -v grep"
```

**Recovery**:
```bash
# Replace PIDs with whatever lsof showed
ssh <production-server> "sudo kill <pid> <pid>"
# Wait, then verify
ssh <production-server> "ps auxf | grep -iE 'maria|mysql' | grep -v grep || echo 'NO_ORPHANS'"
# Restart stack
ssh <production-server> "cd ~/matre && ./prod.sh start"
```

---

## Stale Container Shims {#stale-shims}

**Symptom**: New test runs fail to start dynamic Magento env containers with:
```
ERROR: Failed to start container matre_magento_env_1: Error response from daemon: 
failed to create task for container: failed to create shim task: 
OCI runtime create failed: runc create failed: container with given ID already exists
```

**Cause**: A previous container's containerd shim/runc state was left behind (typically after a daemon crash or migration). The container ID is registered with containerd, so Docker can't create a new one with the same name.

**Diagnose**:
```bash
ssh <production-server> "docker ps -a --filter 'status=exited' | grep matre_magento_env"
```

**Recovery**:
```bash
# Force-remove stale containers (replace names as needed)
ssh <production-server> "docker rm -f matre_magento_env_1 matre_magento_env_3 matre_magento_env_4 matre_magento_env_6"
```

**Bulk cleanup of any stale dynamic containers**:
```bash
# List ALL exited containers
ssh <production-server> "docker ps -a --filter 'status=exited' --format 'table {{.Names}}\t{{.Status}}'"

# Remove ONLY matre dynamic env containers (NEVER remove main matre_* services!)
ssh <production-server> "docker ps -a --filter 'status=exited' --format '{{.Names}}' | grep -E '^matre_magento_env_' | xargs -r docker rm -f"
```

---

## Permission Denied on Stop {#permission-denied-stop}

**Symptom**:
```
Container matre_X Error while Stopping
Error response from daemon: cannot stop container: <id>: permission denied
```

**Cause**: snap docker apparmor profiles refused to send signals to containers. Should NOT happen on apt-installed docker-ce.

**If it occurs**:
1. Check whether snap docker has been re-enabled:
   ```bash
   ssh <production-server> "snap list docker 2>/dev/null; systemctl is-active snap.docker.dockerd.service 2>/dev/null"
   ```
2. If snap is active: disable it (`sudo snap disable docker`) and use apt's dockerd. See [Docker Engine](../deployment/docker-engine.md) for migration procedure.
3. As emergency, restart the docker daemon to force-stop containers:
   ```bash
   ssh <production-server> "sudo systemctl restart docker.service"
   ssh <production-server> "cd ~/matre && ./prod.sh start"
   ```

---

## Disk Full {#disk-full}

**Symptom**: Containers fail with "no space left on device". Scheduler exits and stays down.

**Diagnose**:
```bash
ssh <production-server> "df -h /; docker system df"
```

**Recovery**:
```bash
# Run safe cleanup scripts
ssh <production-server> "cd ~/matre && bash scripts/ops/safe-docker-prune.sh"
ssh <production-server> "cd ~/matre && bash scripts/ops/artifact-retention.sh"

# Verify
ssh <production-server> "df -h /"

# Restart stopped services
ssh <production-server> "cd ~/matre && docker compose up -d scheduler test-worker"
```

**Long-term**: Daily cleanup cron should be installed at `/etc/cron.d/matre-cleanup` with 60-day retention. Verify it's still active:
```bash
ssh <production-server> "ls -la /etc/cron.d/matre-cleanup; tail -20 /var/log/matre-cleanup.log"
```

If EBS volume is also full and can't be cleaned to under threshold, **resize online** (no downtime):
```bash
aws ec2 modify-volume --region <region> --volume-id <volume-id> --size <NEW_SIZE_GB>
# Trigger expansion now if cloud-init doesn't do it on next boot:
ssh <production-server> "sudo growpart /dev/nvme0n1 1 && sudo resize2fs /dev/nvme0n1p1"
```

---

## Allure Report Service Unresponsive {#allure-down}

**Symptom**: Allure URL returns 502, reports don't generate.

**Recovery**:
```bash
ssh <production-server> "docker compose restart allure"
ssh <production-server> "curl -s http://localhost:5050/allure-docker-service/version"
```

**⚠️ Never** use the Allure API `clean-results` endpoint — it permanently deletes ALL test history with no backup or recovery option:
- ❌ `DELETE /allure-docker-service/projects/{id}/results` — FORBIDDEN
- ❌ `GET /allure-docker-service/clean-results?project_id={id}` — FORBIDDEN

For selective cleanup with backups, use `scripts/allure_cleanup.py` with `--base-path` overridden to your deployment's actual report directory (the script's default path may be stale — see [scripts/README.md](../../scripts/README.md)).

---

## After Any Recovery — Verify

```bash
# 1. URL responds
curl -sI -k https://<production-domain>/admin
# Expect: HTTP/2 302 → /login

# 2. All containers up + healthy
ssh <production-server> "docker ps --format 'table {{.Names}}\t{{.Status}}'"

# 3. Daemon healthy + standard data root
ssh <production-server> "docker info --format '{{.ServerVersion}} | {{.DockerRootDir}} | {{.Driver}}'"
# Expect: 29.x | /var/lib/docker | overlay2

# 4. No orphan db processes
ssh <production-server> "ps auxf | grep -iE 'maria|mysql' | grep -v grep"
# Should only show containerized mariadbd (parent: containerd-shim)

# 5. Restart policies still applied
ssh <production-server> "docker inspect matre_php matre_db matre_traefik_prod \
  --format '{{.Name}}: {{.HostConfig.RestartPolicy.Name}}'"
# Expect: all "unless-stopped"
```

If any TestRun records were stuck in `running` status during the incident, mark them failed via the admin UI to prevent stale state and free the per-environment lock.
