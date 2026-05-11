# Selenium + Allure Updates

Recurring task: keep Selenium Grid and Allure Docker images current.

**Cadence:** Monthly
**Time:** ~1h (up to 2h if breakage)

---

## Why

- Bug fixes → outdated images carry known issues (Allure refresh, Selenium sessions)
- Browser parity → Chrome node must match real-user Chrome versions
- Security patches → containers reach Magento envs, must stay patched
- Compatibility → small monthly bumps avoid large breaking jumps later

---

## What to Update

| Service | File | Current Tag |
|---------|------|-------------|
| Selenium Hub | `docker-compose.yml:181` | `selenium/hub:4.40.0-20260120` |
| Chromium Node | `docker-compose.yml:197` | `selenium/node-chromium:4.40.0-20260120` |
| Allure | `docker-compose.yml:254` | `frankescobar/allure-docker-service:latest` |

Selenium Hub + Chromium Node tags **must match**.

---

## Where to Find New Versions

- Selenium: https://hub.docker.com/r/selenium/hub/tags → pick newest `4.x.x-YYYYMMDD`
- Allure: https://hub.docker.com/r/frankescobar/allure-docker-service/tags → pick newest stable

Prefer dated/numbered tags over `latest` for reproducibility.

---

## Procedure

### 1. Local Test

```bash
# Pull candidates
docker pull selenium/hub:NEW_TAG
docker pull selenium/node-chromium:NEW_TAG
docker pull frankescobar/allure-docker-service:NEW_TAG

# Edit docker-compose.yml → bump tags
./local.sh restart

# Smoke test
./local.sh console app:test:run --suite=Smoke --env=preprod-es
```

✅ Expected: test run completes, Allure report renders, noVNC viewer works at `:7900`.

### 2. Verify

- [ ] Test run reaches `completed` status
- [ ] Allure report opens in UI
- [ ] Screenshots present in `var/test-artifacts/`
- [ ] No Selenium session errors in worker logs
- [ ] Chrome version inside node ≥ matches production Magento target

```bash
docker exec matre_chrome-node-1 google-chrome --version  # or chromium --version
```

### 3. Commit

```bash
git checkout -b deps/bump-selenium-allure-YYYY-MM
# edit docker-compose.yml
git commit -m "{TICKET} - Bump Selenium to X.Y.Z, Allure to A.B.C"
git push -u origin HEAD
```

Open PR → wait for CI green → merge.

### 4. Deploy to Prod

```bash
ssh abb "cd ~/matre && git pull origin master && ./prod.sh update"
```

`./prod.sh update` pulls new images, recreates containers, runs migrations, warms cache.

### 5. Post-Deploy Check

- [ ] `./prod.sh status` → all services healthy
- [ ] Trigger a smoke run via admin UI
- [ ] Allure report renders
- [ ] noVNC live preview accessible

---

## Rollback

If prod breaks after update:

```bash
ssh abb "cd ~/matre && git revert HEAD --no-edit && git push && ./prod.sh update"
```

Or pin previous tag manually in `docker-compose.yml` on prod, then `./prod.sh restart`.

---

## Common Issues

| Symptom | Cause | Fix |
|---------|-------|-----|
| Tests hang at session start | Hub/Node tag mismatch | Re-pin both to same tag |
| `ChromeDriver/Chrome version mismatch` | Node image stale | Pull latest node-chromium |
| Allure report 404 | Allure container failed to mount `var/allure-results` | Check `./prod.sh logs allure` |
| Allure empty after upgrade | Breaking change in result schema | Roll back Allure tag, file upstream issue |
| ARM/AMD arch errors locally | Wrong image variant | Use `node-chromium` (multi-arch), not `node-chrome` |

---

## Acceptance

- [ ] Newer tag pinned in `docker-compose.yml` (no `:latest` for Selenium)
- [ ] Smoke suite passes locally on new images
- [ ] PR merged, CI green
- [ ] Prod updated, status healthy, sample run passes
- [ ] Tracking ticket commented with: old tag → new tag, date, smoke run ID
