# Backup & Restore

What gets backed up, how, where, and how to restore.

> Operators of a specific MATRE deployment should keep their concrete identifiers (volume ID, AWS profile, region) in a separate private runbook. Replace the `<placeholder>` values below with the deployment's actual values when running these commands.

---

## What needs to be backed up

| Data | Layer | Recovery RPO target |
|---|---|---|
| matre application database | Docker volume `matre_matre_db_data` | < 24 h |
| Magento harness DB | Docker volume `matre_matre_magento_db_data` | < 24 h (rebuildable) |
| Allure project history | `var/allure-projects/{env}/` | < 7 d (rebuildable from `var/allure-results/`) |
| Allure raw run results | `var/allure-results/run-{id}/` | < 30 d |
| Test artifacts (screenshots, HTML) | `var/test-artifacts/{runId}/` | < 30 d |
| Project code | git remote | always |
| `.env` (secrets) | NOT in git — backup separately | < 30 d |
| `traefik_certs` (Let's Encrypt) | Docker volume | regenerable from Let's Encrypt; nice-to-have |

---

## EBS snapshots (whole-disk, crash-consistent)

The simplest disaster-recovery primitive. Captures the entire EBS volume at a point in time. Survives total instance loss.

### Take a snapshot

```bash
aws ec2 create-snapshot --region <region> \
  --volume-id <volume-id> \
  --description "scheduled-$(date -u +%Y%m%d)" \
  --tag-specifications 'ResourceType=snapshot,Tags=[{Key=Name,Value=matre-scheduled}]'
```

Capture the returned `SnapshotId`.

### Wait for completion (important!)

```bash
SNAP_ID=snap-XXXXXXXXXXXXXXXXX
aws ec2 describe-snapshots --region <region> \
  --snapshot-ids $SNAP_ID --query 'Snapshots[0].{State:State,Progress:Progress}' --output table
```

First snapshot of a volume: 30-60 min. Subsequent (incremental): minutes. Until `State: completed`, the snapshot is not safe to rely on for recovery.

### List existing snapshots

```bash
aws ec2 describe-snapshots --region <region> \
  --filters "Name=volume-id,Values=<volume-id>" \
  --query 'Snapshots[*].{Id:SnapshotId,Date:StartTime,State:State,Description:Description}' \
  --output table
```

### Caveats

- **Crash-consistent, not application-consistent.** A snapshot taken while MariaDB is mid-write captures partial transactions. InnoDB recovery on restore handles this for matre's MariaDB, but for safety prefer combining with a `mariadb-dump` (see below) before high-stakes operations.
- **Cost grows.** Snapshots are incremental but not free. Set a retention policy.

---

## Application-consistent DB backup (mariadb-dump)

Recommended before any infrastructure change (engine upgrade, daemon migration, etc.).

### matre application DB

```bash
ssh <production-server> "mkdir -p ~/matre-backups && cd ~/matre-backups && \
  docker exec matre_db sh -c 'mariadb-dump -u root -p\${DB_ROOT_PASSWORD} --all-databases \
    --single-transaction --quick --lock-tables=false' > matre-db-\$(date -u +%Y%m%d).sql"
```

(The DB root password is in `.env` on the server — pass it explicitly to avoid storing it inline.)

Typical size: small (single-digit MB to low double-digit MB) — mostly schema + operational tables.

### Magento harness DB

```bash
ssh <production-server> "cd ~/matre-backups && \
  docker exec matre_magento_db sh -c 'mysqldump -u root -p\${MAGENTO_DB_ROOT_PASSWORD} --all-databases \
    --single-transaction --quick --lock-tables=false' > magento-db-\$(date -u +%Y%m%d).sql"
```

### Restore

```bash
# Drop existing database first (data loss!)
ssh <production-server> "docker exec -i matre_db mysql -u root -p<root-pass> \
  -e 'DROP DATABASE matre; CREATE DATABASE matre;'"

# Restore
ssh <production-server> "cat ~/matre-backups/matre-db-2026-04-27.sql | \
  docker exec -i matre_db mysql -u root -p<root-pass>"
```

---

## File-level backup of var/

Allure history, raw results, artifacts. Already retained on disk via cleanup cron (60-day window). For off-host backup:

```bash
# rsync to a developer machine
rsync -aHAXS --numeric-ids --delete \
  <production-server>:/home/ubuntu/matre/var/allure-projects/ \
  ~/local-backup/allure-projects/

# Or tar + scp for archival
ssh <production-server> "sudo tar czf /tmp/var-backup-\$(date -u +%Y%m%d).tar.gz \
  -C /home/ubuntu/matre var/allure-projects var/allure-results"
scp <production-server>:/tmp/var-backup-*.tar.gz ~/archives/
ssh <production-server> "rm /tmp/var-backup-*.tar.gz"
```

---

## Secrets (`.env`)

The `.env` file on the production server contains secrets (DB passwords, Slack webhook, Magento Marketplace keys, etc.). NOT in git.

Back up to a password manager or encrypted off-host store:
```bash
ssh <production-server> "cat ~/matre/.env" > ~/secure/matre-env-$(date -u +%Y%m%d).txt
# Then immediately encrypt or store in 1Password / AWS SSM Parameter Store
```

---

## Restore from EBS snapshot — full instance recovery

When the volume itself is lost or corrupted.

### Option A: Restore in place (existing instance OK, just bad data)

```bash
SNAP_ID=snap-XXXXXXXXXXXXXXXXX

# 1. Stop instance
aws ec2 stop-instances --region <region> --instance-ids <instance-id>

# 2. Detach old volume
aws ec2 detach-volume --region <region> --volume-id <old-volume-id>

# 3. Create new volume from snapshot (in the instance's AZ)
NEW_VOL=$(aws ec2 create-volume --region <region> \
  --availability-zone <az> \
  --snapshot-id $SNAP_ID \
  --volume-type gp3 \
  --query 'VolumeId' --output text)
echo "New volume: $NEW_VOL"

# 4. Attach as root
aws ec2 attach-volume --region <region> \
  --instance-id <instance-id> \
  --volume-id $NEW_VOL --device /dev/sda1

# 5. Start instance
aws ec2 start-instances --region <region> --instance-ids <instance-id>

# 6. Wait for reachability, bring stack up
ssh <production-server> "cd ~/matre && ./prod.sh start"
```

### Option B: Replace instance entirely

If the EC2 instance is also lost:
1. Launch a new EC2 instance (same type, same AZ) from a recent EBS snapshot, or fresh Ubuntu LTS + manual setup.
2. Re-attach the Elastic IP (DNS unaffected).
3. Pull the project: `git clone <repo-url> ~/matre`
4. Restore secrets to `~/matre/.env` from your secure store.
5. Install docker-ce per [Docker Engine](../deployment/docker-engine.md).
6. `./prod.sh start`.

---

## Restore granular: single Docker volume

If a Docker volume is corrupted but the rest of the system is fine:

```bash
# Inspect volume location
ssh <production-server> "docker volume inspect matre_matre_db_data --format '{{.Mountpoint}}'"
# /var/lib/docker/volumes/matre_matre_db_data/_data

# Stop the consuming container
ssh <production-server> "docker compose stop db"

# Restore data into the volume — choose one path:
#   (a) rsync from a snapshot-derived volume mounted at a temp path
#   (b) untar a previous file-level backup
#   (c) re-import via mariadb-dump SQL file (preferred — see "Restore" above)

# Bring container back
ssh <production-server> "docker compose up -d db"
```

For matre DB specifically, the `mariadb-dump` restore path is the cleanest — it re-creates schema + data from the SQL file, no filesystem-level surgery needed.

---

## Backup schedule (recommended)

| What | When | Where | Retention |
|---|---|---|---|
| EBS snapshot | Weekly (manual or AWS Backup) | AWS | 4 weekly + 12 monthly |
| EBS snapshot | Before any infra change | AWS | until verified post-change |
| `mariadb-dump` matre DB | Daily | Off-host (e.g. S3) | 30 days |
| `.env` secret backup | After any change | Password manager / SSM | indefinitely |
| `var/allure-*` rsync | Weekly | Off-host | 30 days |

To automate AWS-side, configure **AWS Backup** with the matre EBS volume as the resource and a daily/weekly plan.

---

## Verification

A backup you haven't restored is theoretical. Quarterly drill recommendation:

1. Take a fresh EBS snapshot.
2. Spawn a test instance from it (different name, isolated security group).
3. SSH in and verify `./prod.sh start` works on the cloned data.
4. Terminate the test instance.

For DB dumps:
1. Take a dump.
2. Spin up a throwaway MariaDB locally: `docker run --rm -d -e MYSQL_ROOT_PASSWORD=test -p 33068:3306 mariadb:11`.
3. Restore the dump into it.
4. Verify expected tables: `SHOW DATABASES; USE matre; SHOW TABLES;`.
