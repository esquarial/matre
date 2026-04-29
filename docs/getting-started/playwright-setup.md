# Playwright Test Setup Guide

Guide for creating and running Playwright tests with MATRE.

> **MFTF vs Playwright:** MFTF is Magento's official testing framework with XML-based tests. Playwright is a modern JavaScript/TypeScript framework for end-to-end testing. Choose based on your team's skills and test requirements.

---

## Overview

| Aspect | MFTF | Playwright |
|--------|------|------------|
| Language | XML | JavaScript/TypeScript |
| Learning curve | Steeper (Magento-specific) | Easier (standard web testing) |
| Speed | Slower (heavyweight) | Faster (modern architecture) |
| Cross-browser | Chrome only | Chrome, Firefox, Safari |
| Debugging | Allure reports | Trace viewer, video, screenshots |
| Best for | Magento-specific flows | General e-commerce testing |

---

## Prerequisites

| Requirement | Check |
|-------------|-------|
| MATRE running | `docker compose ps` shows `matre_playwright` running |
| Node.js (for local dev) | `node --version` (18+) |
| Test module repository | Git repo with your tests |

---

## Step 1: Create Playwright Test Module

### Option A: Add to Existing Test Module

If you have an MFTF test module, add Playwright tests alongside:

```
your-module/
├── Test/
│   └── Mftf/           # Existing MFTF tests
├── playwright/         # NEW: Playwright tests
│   ├── tests/
│   │   └── storefront.spec.ts
│   ├── playwright.config.ts
│   └── package.json
├── Cron/
│   └── data/
└── composer.json
```

### Option B: Create Dedicated Playwright Module

```bash
mkdir playwright-tests && cd playwright-tests

# Initialize npm project
npm init -y

# Install Playwright
npm install -D @playwright/test allure-playwright

# Install browsers
npx playwright install chromium
```

### Project Structure

```
playwright-tests/
├── tests/
│   ├── storefront/
│   │   ├── homepage.spec.ts
│   │   ├── category.spec.ts
│   │   └── product.spec.ts
│   ├── checkout/
│   │   ├── cart.spec.ts
│   │   └── checkout.spec.ts
│   └── admin/
│       └── login.spec.ts
├── fixtures/
│   └── auth.fixture.ts
├── pages/
│   ├── storefront.page.ts
│   └── admin.page.ts
├── playwright.config.ts
├── package.json
└── .env.example
```

---

## Step 2: Configure Playwright

### playwright.config.ts

```typescript
import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './tests',

  // Run tests in parallel
  fullyParallel: true,

  // Fail fast on CI
  forbidOnly: !!process.env.CI,

  // Retry failed tests
  retries: process.env.CI ? 2 : 0,

  // Limit parallelism
  workers: process.env.CI ? 1 : undefined,

  // Reporter configuration
  reporter: [
    ['list'],
    ['allure-playwright'],  // Allure for MATRE integration
  ],

  use: {
    // Base URL from environment
    baseURL: process.env.BASE_URL || 'https://your-store.com',

    // Capture trace on failure
    trace: 'on-first-retry',

    // Screenshot on failure
    screenshot: 'only-on-failure',

    // Video on failure
    video: 'on-first-retry',
  },

  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});
```

### package.json

```json
{
  "name": "my-playwright-tests",
  "version": "1.0.0",
  "scripts": {
    "test": "playwright test",
    "test:headed": "playwright test --headed",
    "test:debug": "playwright test --debug",
    "report": "playwright show-report"
  },
  "devDependencies": {
    "@playwright/test": "^1.40.0",
    "allure-playwright": "^2.10.0"
  }
}
```

---

## Step 3: Write Your First Test

### tests/storefront/homepage.spec.ts

```typescript
import { test, expect } from '@playwright/test';

test.describe('Storefront Homepage', () => {

  test('should load homepage successfully', async ({ page }) => {
    await page.goto('/');

    // Wait for page load
    await expect(page).toHaveTitle(/Home/);

    // Verify key elements
    await expect(page.locator('.header')).toBeVisible();
    await expect(page.locator('.footer')).toBeVisible();
  });

  test('should display featured products', async ({ page }) => {
    await page.goto('/');

    // Check for product grid
    const products = page.locator('.product-item');
    await expect(products).toHaveCount({ minimum: 1 });
  });

  test('should navigate to category', async ({ page }) => {
    await page.goto('/');

    // Click category link
    await page.click('nav >> text=Women');

    // Verify navigation
    await expect(page).toHaveURL(/women/);
    await expect(page.locator('.category-title')).toContainText('Women');
  });
});
```

### tests/admin/login.spec.ts

```typescript
import { test, expect } from '@playwright/test';

test.describe('Admin Login', () => {

  test('should login to admin panel', async ({ page }) => {
    // Navigate to admin
    const backendName = process.env.BACKEND_NAME || 'admin';
    await page.goto(`/${backendName}`);

    // Fill credentials from environment
    await page.fill('#username', process.env.ADMIN_USERNAME || 'admin');
    await page.fill('#login', process.env.ADMIN_PASSWORD || 'admin123');

    // Submit
    await page.click('.action-login');

    // Verify dashboard loaded
    await expect(page.locator('.dashboard-main')).toBeVisible({ timeout: 30000 });
  });

  test('should reject invalid credentials', async ({ page }) => {
    const backendName = process.env.BACKEND_NAME || 'admin';
    await page.goto(`/${backendName}`);

    await page.fill('#username', 'invalid');
    await page.fill('#login', 'wrongpassword');
    await page.click('.action-login');

    // Verify error message
    await expect(page.locator('.message-error')).toBeVisible();
  });
});
```

---

## Step 4: Using Environment Variables

Playwright tests receive environment variables from MATRE:

| Variable | Description | Source |
|----------|-------------|--------|
| `BASE_URL` | Store URL | TestEnvironment.baseUrl |
| `ADMIN_USERNAME` | Admin login | TestEnvironment.adminUsername |
| `ADMIN_PASSWORD` | Admin password | TestEnvironment.adminPassword |
| Custom vars | Any variable | GlobalEnvVariable table |

### Accessing in Tests

```typescript
import { test, expect } from '@playwright/test';

test('use environment variables', async ({ page }) => {
  // BASE_URL is automatically used by page.goto('/')
  // when configured in playwright.config.ts

  // Access custom variables
  const apiKey = process.env.API_KEY;
  const customUrl = process.env.CUSTOM_ENDPOINT;

  // Use in test
  await page.goto(customUrl || '/');
});
```

---

## Step 5: Page Object Pattern (Recommended)

### pages/storefront.page.ts

```typescript
import { Page, Locator } from '@playwright/test';

export class StorefrontPage {
  readonly page: Page;
  readonly header: Locator;
  readonly searchInput: Locator;
  readonly cartIcon: Locator;
  readonly productGrid: Locator;

  constructor(page: Page) {
    this.page = page;
    this.header = page.locator('.header');
    this.searchInput = page.locator('#search');
    this.cartIcon = page.locator('.minicart-wrapper');
    this.productGrid = page.locator('.products-grid');
  }

  async goto() {
    await this.page.goto('/');
  }

  async search(term: string) {
    await this.searchInput.fill(term);
    await this.searchInput.press('Enter');
  }

  async getProductCount(): Promise<number> {
    return await this.productGrid.locator('.product-item').count();
  }
}
```

### Using Page Objects

```typescript
import { test, expect } from '@playwright/test';
import { StorefrontPage } from '../pages/storefront.page';

test('search for products', async ({ page }) => {
  const storefront = new StorefrontPage(page);

  await storefront.goto();
  await storefront.search('shirt');

  const count = await storefront.getProductCount();
  expect(count).toBeGreaterThan(0);
});
```

---

## Step 6: Configure MATRE for Playwright

### Set Test Module Repository

```dotenv
# .env
TEST_MODULE_REPO=git@github.com:your-org/playwright-tests.git
TEST_MODULE_BRANCH=main
```

### Run Playwright Tests

**Via Admin UI:**

1. Navigate to **Test Automation → Test Runs**
2. Click **Start New Run**
3. Select **Type: Playwright**
4. Set filter (optional): `homepage` or `@smoke`
5. Click **Start Run**

**Via CLI:**

```bash
# Run all Playwright tests
docker compose exec php php bin/console app:test:run playwright dev-us --sync

# Run specific test file
docker compose exec php php bin/console app:test:run playwright dev-us \
    --filter="homepage" --sync

# Run tests with tag
docker compose exec php php bin/console app:test:run playwright dev-us \
    --filter="@smoke" --sync
```

---

## Step 7: Local Development

### Run Tests Locally

```bash
cd your-playwright-tests/

# Install dependencies
npm install

# Set environment variables
export BASE_URL=https://your-store.com
export ADMIN_USERNAME=admin
export ADMIN_PASSWORD=admin123

# Run tests
npm test

# Run with UI mode (debugging)
npx playwright test --ui

# Run headed (see browser)
npm run test:headed
```

### Debug Tests

```bash
# Debug mode (step through)
npx playwright test --debug

# Generate test code
npx playwright codegen https://your-store.com
```

---

## Test Tagging

Use tags to organize and filter tests:

```typescript
import { test } from '@playwright/test';

// Tag with @smoke
test('critical checkout flow @smoke', async ({ page }) => {
  // ...
});

// Tag with @regression
test('edge case handling @regression', async ({ page }) => {
  // ...
});
```

Run by tag:

```bash
# MATRE
docker compose exec php php bin/console app:test:run playwright dev-us \
    --filter="@smoke" --sync

# Local
npx playwright test --grep "@smoke"
```

---

## Common Patterns

### Wait for Network Idle

```typescript
await page.goto('/', { waitUntil: 'networkidle' });
```

### Handle Modals/Popups

```typescript
// Cookie consent
const cookieModal = page.locator('.cookie-consent');
if (await cookieModal.isVisible()) {
  await page.click('.cookie-consent .accept');
}
```

### API Mocking

```typescript
await page.route('**/api/cart', route => {
  route.fulfill({
    status: 200,
    body: JSON.stringify({ items: [] }),
  });
});
```

### Screenshot Comparison

```typescript
await expect(page).toHaveScreenshot('homepage.png');
```

---

## Troubleshooting

### "Test timeout exceeded"

```typescript
// Increase timeout for slow pages
test.setTimeout(60000);

// Or per-action
await page.click('.slow-button', { timeout: 30000 });
```

### "Element not found"

```typescript
// Wait for element before interacting
await page.waitForSelector('.dynamic-element');
await page.click('.dynamic-element');

// Or use auto-waiting locator
await page.locator('.dynamic-element').click();
```

### "SSL Certificate error"

Configure in `playwright.config.ts`:

```typescript
use: {
  ignoreHTTPSErrors: true,
}
```

### Results not appearing in MATRE

Ensure Allure reporter is configured:

```typescript
reporter: [
  ['allure-playwright'],
],
```

---

## Next Steps

- [Quick Start Guide](./quick-start.md) - Running tests
- [Dev Mode](../development/dev-mode.md) - Local development
- [CLI Reference](../operations/cli-reference.md) - Command options
- [Playwright Docs](https://playwright.dev/) - Official documentation
