# Dev Mode (Local Module Development)

Skip git clone on each test run by using a local module directory with live edits.

---

## Configuration

Set `DEV_MODULE_PATH` in `.env`:

```env
# Relative path (from project root)
DEV_MODULE_PATH=./test-module

# Or absolute path
DEV_MODULE_PATH=/path/to/your/module
```

Leave empty for normal git clone behavior:
```env
DEV_MODULE_PATH=
```

---

## How It Works

| DEV_MODULE_PATH | Behavior |
|-----------------|----------|
| Empty/unset | Clone from `TEST_MODULE_REPO` (production) |
| Set to path | Symlink local module (dev mode) |
| Invalid path | Fail with clear error |

When dev mode is enabled, `ModuleCloneService.cloneModule()`:
1. Skips git clone entirely
2. Creates symlink: `var/test-modules/run-{id}/` → your local module
3. Changes in your local module are instantly visible to test runs

---

## Usage

### 1. Place Module in Project

```bash
# Clone your module repo
git clone git@bitbucket.org:org/my-module.git test-module

# Or symlink existing checkout
ln -s /path/to/existing/module test-module
```

### 2. Enable Dev Mode

```bash
# Add to .env
echo "DEV_MODULE_PATH=./test-module" >> .env
```

### 3. Run Tests

```bash
php bin/console app:test:run --filter=YourTestName
```

Logs will show:
```
[info] Using local module (dev mode) {"source":"./test-module","target":".../var/test-modules/run-123"}
[info] Local module symlinked successfully
```

### 4. Edit & Re-run

Make changes to files in `test-module/` and run tests again — no clone needed.

---

## Switching Modes

| Action | Command |
|--------|---------|
| Enable dev mode | `sed -i '' 's/DEV_MODULE_PATH=.*/DEV_MODULE_PATH=.\/test-module/' .env` |
| Disable dev mode | `sed -i '' 's/DEV_MODULE_PATH=.*/DEV_MODULE_PATH=/' .env` |

---

## Troubleshooting

### Error: Dev module path does not exist

```
Dev module path does not exist: /path/to/module (DEV_MODULE_PATH=./test-module)
```

**Fix:** Ensure the path exists and is readable:
```bash
ls -la ./test-module
```

### Tests use old code after changes

Symlinks should reflect changes immediately. If not:
1. Clear Symfony cache: `php bin/console cache:clear`
2. Check symlink is valid: `ls -la var/test-modules/run-*/`

---

## Edge Cases & Advanced Scenarios

### Empty or Invalid Directory

| Scenario | Behavior |
|----------|----------|
| Path doesn't exist | ❌ Error: "Dev module path does not exist" |
| Path exists but empty | ⚠️ Symlink created, but tests fail (no tests found) |
| Path is a file (not dir) | ❌ Error: Path must be a directory |
| Path has wrong permissions | ❌ Error: Permission denied |

**Best practice:** Always verify module structure before enabling dev mode:

```bash
# Check module exists and has tests
ls test-module/Test/Mftf/Test/
# or for Playwright
ls test-module/tests/
```

### Symlinks Inside Docker

The `DEV_MODULE_PATH` is resolved **on the host**, then mounted into Docker.

```
Host: ./test-module  →  Docker: /var/www/html/var/test-modules/run-{id}
```

**Important considerations:**

| Path Type | Works? | Notes |
|-----------|--------|-------|
| Relative path (`./test-module`) | ✅ | Resolved from project root |
| Absolute path (`/home/user/module`) | ✅ | Must be accessible to Docker |
| Symlink pointing outside project | ⚠️ | May fail if target not in Docker volumes |
| Network mount (NFS, SMB) | ⚠️ | Works but may be slow |

**If using a symlink that points outside the project:**

```bash
# This might fail if /external/path is not mounted in Docker
ln -s /external/path/module test-module

# Solution: Mount the external path in docker-compose.yml
volumes:
  - /external/path:/external/path:ro
```

### Switching Between Modes

**Enable dev mode (use local module):**

```bash
# Set path in .env
echo "DEV_MODULE_PATH=./test-module" >> .env

# Restart workers to pick up change
docker compose restart test-worker
```

**Disable dev mode (use git clone):**

```bash
# Clear the path
sed -i '' 's/DEV_MODULE_PATH=.*/DEV_MODULE_PATH=/' .env

# Restart workers
docker compose restart test-worker
```

**Check current mode:**

```bash
grep DEV_MODULE_PATH .env
# Empty = production (git clone)
# Set = dev mode (local symlink)
```

### Testing Changes Before Pushing

Typical workflow for test development:

```bash
# 1. Enable dev mode
echo "DEV_MODULE_PATH=./test-module" >> .env
docker compose restart test-worker

# 2. Make changes to test files
vim test-module/Test/Mftf/Test/MyNewTest.xml

# 3. Run test immediately (no clone needed)
docker compose exec php php bin/console app:test:run mftf dev-us \
    --filter="MyNewTest" --sync

# 4. Iterate: edit → run → edit → run

# 5. When happy, commit and push
cd test-module
git add . && git commit -m "Add MyNewTest"
git push

# 6. Optionally switch back to production mode
sed -i '' 's/DEV_MODULE_PATH=.*/DEV_MODULE_PATH=/' .env
docker compose restart test-worker
```

### Performance Comparison

| Mode | Clone Time | Test Start | Use Case |
|------|------------|------------|----------|
| Git clone (production) | 5-30s | After clone | CI/CD, scheduled runs |
| Symlink (dev mode) | <1s | Instant | Active development |

**When to use each:**

- **Dev mode:** Writing tests, debugging, rapid iteration
- **Production mode:** Automated runs, CI pipelines, scheduled executions

### Multiple Module Versions

Dev mode only supports one local module. For testing different versions:

```bash
# Option 1: Git branches in local module
cd test-module
git checkout feature-branch
# Run tests...
git checkout main

# Option 2: Multiple module directories
DEV_MODULE_PATH=./test-module-v1  # Version 1
DEV_MODULE_PATH=./test-module-v2  # Version 2

# Option 3: Disable dev mode and use git
DEV_MODULE_PATH=
TEST_MODULE_BRANCH=feature-branch
```

### Concurrent Test Runs

Each test run creates its own symlink directory:

```
var/test-modules/
├── run-123/ → ./test-module (symlink)
├── run-124/ → ./test-module (symlink)
└── run-125/ → ./test-module (symlink)
```

**Note:** All runs point to the same local module. If you edit files during a run, changes may affect in-progress tests.

**Best practice for concurrent development:**

```bash
# Wait for current run to finish before editing
docker compose exec php php bin/console app:test:run mftf dev-us --sync

# Or use separate module copies for parallel work
DEV_MODULE_PATH=./test-module-experiment
```

---

## Related Files

| File | Purpose |
|------|---------|
| `src/Service/ModuleCloneService.php` | Dev mode logic |
| `config/services.yaml` | DI config for `$devModulePath` |
| `.env` / `.env.example` | `DEV_MODULE_PATH` variable |
