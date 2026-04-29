# MFTF Test Setup Guide

Complete end-to-end guide for setting up MFTF tests with MATRE from scratch.

> **Who is this for?** Developers setting up automated testing for a Magento module for the first time.

---

## Overview

MATRE orchestrates MFTF test execution across multiple Magento environments. To use MATRE, you need:

| Component | Description |
|-----------|-------------|
| **MATRE** | This project - test orchestration platform |
| **Test Module Repository** | Git repo with your MFTF tests (e.g., your Magento module) |
| **Target Magento Instance(s)** | Magento 2 stores where tests will execute |

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                              YOUR SETUP                                      │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  ┌──────────────┐      clones        ┌──────────────────────────────────┐   │
│  │              │ ─────────────────► │                                  │   │
│  │    MATRE     │                    │     Test Module Repository       │   │
│  │              │                    │     (your-module with MFTF)      │   │
│  └──────┬───────┘                    └──────────────────────────────────┘   │
│         │                                                                    │
│         │ executes tests on                                                  │
│         ▼                                                                    │
│  ┌──────────────────────────────────────────────────────────────────────┐   │
│  │                     Target Magento Instance(s)                        │   │
│  │                 (dev.example.com, staging.example.com)                │   │
│  └──────────────────────────────────────────────────────────────────────┘   │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## Prerequisites

Before starting, ensure you have:

| Requirement | Check |
|-------------|-------|
| Docker & Docker Compose | `docker --version && docker compose --version` |
| Git | `git --version` |
| SSH key or HTTPS credentials for your repos | Access to clone your test module |
| A Magento 2 instance (dev/staging) | URL + admin credentials |

### Magento 2 Instance Requirements

Your target Magento instance must be properly configured for MFTF testing.

**Supported Versions:**

| Magento Version | MFTF Version | Status |
|-----------------|--------------|--------|
| 2.4.6+ | 4.x | ✅ Recommended |
| 2.4.4 - 2.4.5 | 3.x | ✅ Supported |
| 2.4.0 - 2.4.3 | 3.x | ⚠️ Limited support |
| < 2.4.0 | 2.x | ❌ Not supported |

**Required Magento Configuration:**

```bash
# On your Magento instance, run:

# 1. Enable developer mode (recommended for testing)
bin/magento deploy:mode:set developer

# 2. Disable Two-Factor Auth for admin (required for MFTF)
bin/magento module:disable Magento_AdminAdobeImsTwoFactorAuth Magento_TwoFactorAuth
bin/magento setup:upgrade
bin/magento cache:flush

# 3. Verify Selenium can access the site
# Ensure firewall allows connections from MATRE's Docker network
```

**Required `.env` Variables on Magento:**

If running MFTF directly on Magento (not via MATRE), these are needed in `dev/tests/acceptance/.env`:

```dotenv
MAGENTO_BASE_URL=https://your-store.com/
MAGENTO_BACKEND_NAME=admin
MAGENTO_ADMIN_USERNAME=admin
MAGENTO_ADMIN_PASSWORD=your-password
BROWSER=chrome
SELENIUM_HOST=selenium-hub  # Or MATRE's Selenium container IP
```

> **Note:** When using MATRE, these are configured in the Test Environment settings — not in Magento's `.env` file.

**Verify Magento is MFTF-Ready:**

```bash
# On your Magento instance:

# Check MFTF is installed
composer show magento/magento2-functional-testing-framework

# Generate MFTF files (validates configuration)
vendor/bin/mftf generate:tests --remove

# If errors occur, MFTF configuration needs fixing
```

**Common Magento Issues:**

| Issue | Solution |
|-------|----------|
| Two-Factor Auth blocks admin login | Disable `Magento_TwoFactorAuth` module |
| "Admin user doesn't have permissions" | Create dedicated MFTF admin with all roles |
| Tests can't access storefront | Check firewall/security rules allow Selenium IP |
| HTTPS certificate errors | Add valid SSL cert or configure Selenium to ignore SSL |
| Session expires during tests | Increase admin session lifetime in Magento config |

---

## Step 1: Clone and Start MATRE

```bash
# Clone MATRE
git clone https://github.com/good-yellow-bee/matre.git
cd matre

# Copy environment template
cp .env.example .env

# Start all services
docker compose up -d --build

# Wait for containers to initialize (30-60 seconds)
docker compose ps

# Run database migrations
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction

# Create admin user
docker compose exec php php bin/console app:create-admin
```

**Verify MATRE is running:**
- Admin UI: http://localhost:8089
- Selenium Grid: http://localhost:4444
- Allure Reports: http://localhost:5050

---

## Step 2: Set Up Your Test Module Repository

You have two options:

### Option A: Use Existing Module with MFTF Tests

If your Magento module already has MFTF tests:

```bash
# Your module should have this structure:
your-module/
├── Test/
│   └── Mftf/
│       ├── ActionGroup/
│       ├── Data/
│       ├── Metadata/
│       ├── Page/
│       ├── Section/
│       ├── Suite/
│       └── Test/
│           ├── YourFirstTest.xml
│           └── YourSecondTest.xml
├── Cron/
│   └── data/                    # Optional: env files for MATRE
│       ├── .env.dev-us
│       └── .env.staging-eu
├── composer.json
└── registration.php
```

### Option B: Create New MFTF Test Module from Scratch

If starting fresh, create a new test module:

```bash
# Create module directory structure
mkdir -p my-tests/{Test/Mftf/{ActionGroup,Data,Page,Section,Test},Cron/data,etc}
cd my-tests

# Create registration.php
cat > registration.php << 'EOF'
<?php
use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(
    ComponentRegistrar::MODULE,
    'Vendor_MyTests',
    __DIR__
);
EOF

# Create module.xml
mkdir -p etc
cat > etc/module.xml << 'EOF'
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:Module/etc/module.xsd">
    <module name="Vendor_MyTests" setup_version="1.0.0"/>
</config>
EOF

# Create composer.json
cat > composer.json << 'EOF'
{
    "name": "vendor/my-tests",
    "description": "MFTF Tests for My Store",
    "type": "magento2-module",
    "require": {
        "magento/magento2-functional-testing-framework": "^4.0"
    },
    "autoload": {
        "files": ["registration.php"],
        "psr-4": {"Vendor\\MyTests\\": ""}
    }
}
EOF

# Initialize git repo
git init
git add .
git commit -m "Initial MFTF test module structure"
```

### Create Your First MFTF Test

```bash
# Create a simple storefront test
cat > Test/Mftf/Test/StorefrontHomePageTest.xml << 'EOF'
<?xml version="1.0" encoding="UTF-8"?>
<tests xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
       xsi:noNamespaceSchemaLocation="urn:magento:mftf:Test/etc/testSchema.xsd">
    <test name="StorefrontHomePageLoadTest">
        <annotations>
            <features value="Storefront"/>
            <stories value="Home Page"/>
            <title value="Verify home page loads successfully"/>
            <description value="Navigates to home page and verifies it loads"/>
            <severity value="CRITICAL"/>
            <group value="smoke"/>
        </annotations>

        <!-- Navigate to home page -->
        <amOnPage url="{{StorefrontHomePage.url}}" stepKey="goToHomePage"/>
        <waitForPageLoad stepKey="waitForPageLoad"/>

        <!-- Verify page loaded -->
        <seeInTitle userInput="Home Page" stepKey="seeHomePageTitle"/>
    </test>
</tests>
EOF

# Create required page reference
mkdir -p Test/Mftf/Page
cat > Test/Mftf/Page/StorefrontHomePage.xml << 'EOF'
<?xml version="1.0" encoding="UTF-8"?>
<pages xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
       xsi:noNamespaceSchemaLocation="urn:magento:mftf:Page/etc/PageObject.xsd">
    <page name="StorefrontHomePage" url="/" area="storefront" module="Vendor_MyTests">
    </page>
</pages>
EOF

# Commit your test
git add .
git commit -m "Add StorefrontHomePageLoadTest"
```

### Push to Git Repository

```bash
# Create repo on GitHub/GitLab/Bitbucket, then:
git remote add origin git@github.com:your-org/my-tests.git
git push -u origin main
```

---

## Step 3: Configure MATRE to Use Your Test Module

Edit your `.env` file:

```bash
# Open .env file
nano .env  # or vim, code, etc.
```

Update these variables:

```dotenv
# Your test module repository (SSH recommended)
TEST_MODULE_REPO=git@github.com:your-org/my-tests.git
TEST_MODULE_BRANCH=main

# For HTTPS repos, also set credentials:
# TEST_MODULE_REPO=https://github.com/your-org/my-tests.git
# REPO_USERNAME=your-username
# REPO_PASSWORD=your-token-or-password
```

**Verify configuration:**

```bash
# Restart containers to pick up env changes
docker compose restart php matre_test_worker

# Test git access (optional)
docker compose exec php git ls-remote $TEST_MODULE_REPO
```

---

## Step 4: Configure Target Magento Environment

### Via Admin UI (Recommended)

1. Login to MATRE: http://localhost:8089
2. Navigate to **Test Automation → Environments**
3. Click **+ Add Environment**
4. Fill in details:

| Field | Example Value | Description |
|-------|---------------|-------------|
| Name | `Dev US` | Display name |
| Code | `dev-us` | Unique identifier (used in CLI) |
| Region | `us` | Optional region tag |
| Base URL | `https://dev.yourstore.com/` | Magento storefront URL |
| Backend Name | `admin` | Admin panel path |
| Admin Username | `admin` | Magento admin username |
| Admin Password | `your-password` | Magento admin password |

5. Click **Create Environment**

### Via CLI (Bulk Import)

If your test module has environment files:

```bash
# Create env files in your test module
# File: Cron/data/.env.dev-us
MAGENTO_BASE_URL=https://dev.yourstore.com/
MAGENTO_BACKEND_NAME=admin
MAGENTO_ADMIN_USERNAME=admin
MAGENTO_ADMIN_PASSWORD=your-password
```

Then import:

```bash
docker compose exec php php bin/console app:test:import-env \
    /var/www/html/var/test-modules/current/Cron/data --dry-run

# If preview looks good, run without --dry-run
docker compose exec php php bin/console app:test:import-env \
    /var/www/html/var/test-modules/current/Cron/data
```

---

## Step 5: Import Environment Variables

MFTF tests use `{{_ENV.VARIABLE}}` placeholders. Import these from your module:

```bash
# Clone module and analyze env variable usage
docker compose exec php php bin/console app:env:import --clone

# Or for specific environment
docker compose exec php php bin/console app:env:import dev-us --clone
```

This will:
1. Clone your test module
2. Scan `.env.*` files for variables
3. Analyze MFTF tests for `{{_ENV.VAR}}` usage
4. Show which variables are used by which tests
5. Import variables to database

---

## Step 6: Run Your First Test

### Via Admin UI

1. Navigate to **Test Automation → Test Runs**
2. Click **Start New Run**
3. Select:
   - **Environment:** `Dev US` (or your environment)
   - **Type:** `MFTF`
   - **Test Filter:** `StorefrontHomePageLoadTest` (or leave empty for all)
4. Click **Start Run**
5. Watch progress (auto-refreshes)

### Via CLI

```bash
# Run specific test (sync - waits for completion)
docker compose exec php php bin/console app:test:run mftf dev-us \
    --filter="StorefrontHomePageLoad" --sync

# Run test group
docker compose exec php php bin/console app:test:run mftf dev-us \
    --filter="@smoke" --sync

# Run all tests (async - returns immediately)
docker compose exec php php bin/console app:test:run mftf dev-us
```

### Watch Live Execution

While tests run, click **Watch Live** on the test run page to see the browser automation in real-time via noVNC.

---

## Step 7: Local Development Mode (Optional)

For active test development, skip git clone on every run:

```bash
# Clone your test module locally
git clone git@github.com:your-org/my-tests.git test-module

# Enable dev mode
echo "DEV_MODULE_PATH=./test-module" >> .env

# Restart workers
docker compose restart matre_test_worker
```

Now you can:
1. Edit tests in `./test-module/`
2. Run tests immediately without waiting for git clone
3. Changes are reflected instantly

See [Dev Mode Guide](../development/dev-mode.md) for details.

---

## MFTF Test Structure Reference

### Required Directory Structure

```
your-module/
└── Test/
    └── Mftf/
        ├── ActionGroup/          # Reusable action sequences
        │   └── LoginActionGroup.xml
        ├── Data/                 # Test data entities
        │   └── ProductData.xml
        ├── Metadata/             # API operation definitions
        │   └── ProductMetadata.xml
        ├── Page/                 # Page object definitions
        │   └── AdminProductPage.xml
        ├── Section/              # Page section selectors
        │   └── AdminProductSection.xml
        ├── Suite/                # Test suite definitions
        │   └── SmokeTestSuite.xml
        └── Test/                 # Actual test cases
            └── CreateProductTest.xml
```

### Test XML Structure

```xml
<?xml version="1.0" encoding="UTF-8"?>
<tests xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
       xsi:noNamespaceSchemaLocation="urn:magento:mftf:Test/etc/testSchema.xsd">
    <test name="YourTestName">
        <annotations>
            <features value="YourFeature"/>
            <stories value="YourStory"/>
            <title value="Test Title"/>
            <description value="What this test verifies"/>
            <severity value="CRITICAL"/>  <!-- BLOCKER, CRITICAL, MAJOR, AVERAGE, MINOR -->
            <group value="smoke"/>         <!-- Used for filtering -->
            <group value="yourGroup"/>
        </annotations>

        <!-- Test steps -->
        <amOnPage url="{{YourPage.url}}" stepKey="step1"/>
        <fillField selector="{{Section.field}}" userInput="value" stepKey="step2"/>
        <click selector="{{Section.button}}" stepKey="step3"/>
        <see userInput="Expected Text" stepKey="step4"/>
    </test>
</tests>
```

### Using Environment Variables in Tests

```xml
<!-- Reference env vars with {{_ENV.VAR_NAME}} -->
<fillField selector="{{AdminLogin.username}}"
           userInput="{{_ENV.MAGENTO_ADMIN_USERNAME}}"
           stepKey="fillUsername"/>

<fillField selector="{{AdminLogin.password}}"
           userInput="{{_ENV.MAGENTO_ADMIN_PASSWORD}}"
           stepKey="fillPassword"/>

<!-- Custom variables -->
<fillField selector="{{ApiConfig.key}}"
           userInput="{{_ENV.API_KEY}}"
           stepKey="fillApiKey"/>
```

---

## Common Test Patterns

### Admin Login Test

```xml
<test name="AdminLoginTest">
    <annotations>
        <group value="admin"/>
        <group value="smoke"/>
    </annotations>

    <actionGroup ref="AdminLoginActionGroup" stepKey="loginAsAdmin"/>
    <actionGroup ref="AdminLogoutActionGroup" stepKey="logout"/>
</test>
```

### Storefront Navigation Test

```xml
<test name="StorefrontCategoryNavigationTest">
    <annotations>
        <group value="storefront"/>
        <group value="navigation"/>
    </annotations>

    <amOnPage url="/" stepKey="goToHome"/>
    <waitForPageLoad stepKey="waitForHome"/>

    <click selector="{{StorefrontHeader.menuItem('Women')}}" stepKey="clickWomen"/>
    <waitForPageLoad stepKey="waitForCategory"/>

    <seeInCurrentUrl url="women" stepKey="verifyUrl"/>
</test>
```

### Using ActionGroups

```xml
<!-- Test/Mftf/ActionGroup/AddProductToCartActionGroup.xml -->
<actionGroups>
    <actionGroup name="AddProductToCartActionGroup">
        <arguments>
            <argument name="product"/>
            <argument name="qty" type="string" defaultValue="1"/>
        </arguments>

        <amOnPage url="{{StorefrontProductPage.url(product.url_key)}}" stepKey="goToProduct"/>
        <fillField selector="{{StorefrontProductPage.qtyInput}}" userInput="{{qty}}" stepKey="fillQty"/>
        <click selector="{{StorefrontProductPage.addToCartButton}}" stepKey="addToCart"/>
        <waitForElementVisible selector="{{StorefrontMessages.success}}" stepKey="waitForSuccess"/>
    </actionGroup>
</actionGroups>

<!-- Using in test -->
<actionGroup ref="AddProductToCartActionGroup" stepKey="addProduct">
    <argument name="product" value="SimpleProduct"/>
    <argument name="qty" value="2"/>
</actionGroup>
```

---

## Troubleshooting

### "Module clone failed"

```bash
# Check git access
docker compose exec php git ls-remote $TEST_MODULE_REPO

# For SSH: ensure key is mounted
docker compose exec php ls -la ~/.ssh/

# For HTTPS: verify credentials
docker compose exec php env | grep REPO_
```

### "Test not found"

```bash
# Verify test exists in module
docker compose exec php ls -la var/test-modules/current/Test/Mftf/Test/

# Check test name matches exactly (case-sensitive)
docker compose exec php grep -r "name=\"YourTestName\"" var/test-modules/current/
```

### "Selenium unreachable"

```bash
# Check Selenium is running
docker compose ps selenium-hub chrome-node

# View Selenium logs
docker compose logs selenium-hub

# Access Selenium Grid UI
open http://localhost:4444
```

### "Environment variable not set"

```bash
# List configured variables
docker compose exec php php bin/console app:env:list

# Import missing variables
docker compose exec php php bin/console app:env:import --clone --dry-run
```

---

## Next Steps

- [Test Execution Details](../operations/test-execution.md) - Advanced execution options
- [Dev Mode](../development/dev-mode.md) - Local development workflow
- [Scheduling Tests](../operations/scheduling.md) - Automated cron runs
- [Allure Reports](../operations/allure-reports.md) - Report analysis
- [CLI Reference](../operations/cli-reference.md) - All commands

---

## Quick Reference

```bash
# Start MATRE
docker compose up -d

# Run test (sync)
docker compose exec php php bin/console app:test:run mftf <env> --filter="TestName" --sync

# Run test group
docker compose exec php php bin/console app:test:run mftf <env> --filter="@groupName" --sync

# Import env variables
docker compose exec php php bin/console app:env:import --clone

# Check target Magento
docker compose exec php php bin/console app:check-magento <env>

# View worker logs
docker compose logs -f matre_test_worker

# Enable dev mode
echo "DEV_MODULE_PATH=./test-module" >> .env
docker compose restart matre_test_worker
```
