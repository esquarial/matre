# API Reference

REST API for programmatic access to MATRE.

---

## Authentication

### Browser Sessions

Standard session-based authentication via `/login` form.

### Programmatic Access

Currently session-based. Obtain session cookie by:
1. POST to `/login` with credentials
2. Include session cookie in subsequent requests

### CSRF Protection

All POST/PUT/DELETE requests require CSRF token:
- Header: `X-CSRF-TOKEN: {token}`
- Or form field: `_token={token}`

Get token from any HTML page's meta tag or form.

---

## Dashboard API

### GET /api/dashboard/stats

Real-time system statistics.

**Response:**
```json
{
  "users": {
    "total": 5,
    "active": 4,
    "inactive": 1
  },
  "testRuns": {
    "total": 150,
    "completed": 120,
    "failed": 20,
    "running": 5,
    "pending": 5
  },
  "environments": {
    "total": 3,
    "active": 3
  },
  "suites": {
    "total": 8,
    "active": 6,
    "scheduled": 3
  },
  "activity": {
    "runningNow": 2
  }
}
```

**Notes:**
- `testRuns` counts are for last 30 days
- `activity.runningNow` is current active test count

---

## Test Runs API {#test-runs}

### GET /api/test-runs

List test runs with pagination and filtering.

**Query Parameters:**

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `page` | int | 1 | Page number |
| `limit` | int | 20 | Items per page (max: 100) |
| `status` | string | - | Filter: `pending`, `preparing`, `cloning`, `waiting`, `running`, `reporting`, `completed`, `failed`, `cancelled` |
| `type` | string | - | Filter: `mftf`, `playwright`, `both` |
| `environment` | int | - | Filter by environment ID |
| `suite` | int | - | Filter by suite ID |

**Example:**
```bash
curl "http://localhost:8089/api/test-runs?status=completed&limit=10"
```

**Response:**
```json
{
  "data": [
    {
      "id": 42,
      "status": "completed",
      "type": "mftf",
      "testFilter": "SmokeTestGroup",
      "triggeredBy": "manual",
      "createdAt": "2026-01-15T10:30:00+00:00",
      "startedAt": "2026-01-15T10:30:05+00:00",
      "completedAt": "2026-01-15T10:35:28+00:00",
      "duration": "5m 23s",
      "environment": {
        "id": 1,
        "name": "Staging",
        "code": "staging",
        "region": "default"
      },
      "suite": {
        "id": 3,
        "name": "Smoke Tests"
      },
      "executedBy": {
        "id": 1,
        "username": "admin"
      },
      "resultCounts": {
        "passed": 95,
        "failed": 2,
        "skipped": 3,
        "broken": 0,
        "total": 100
      },
      "canBeCancelled": false
    }
  ],
  "meta": {
    "page": 1,
    "limit": 10,
    "total": 150,
    "pages": 15
  }
}
```

---

### GET /api/test-runs/{id}

Get detailed test run with all results.

**Example:**
```bash
curl "http://localhost:8089/api/test-runs/42"
```

**Response:**
```json
{
  "id": 42,
  "status": "completed",
  "type": "mftf",
  "testFilter": "SmokeTestGroup",
  "triggeredBy": "manual",
  "createdAt": "2026-01-15T10:30:00+00:00",
  "startedAt": "2026-01-15T10:30:05+00:00",
  "completedAt": "2026-01-15T10:35:28+00:00",
  "duration": "5m 23s",
  "environment": {
    "id": 1,
    "name": "Staging",
    "code": "staging",
    "region": "default"
  },
  "suite": {
    "id": 3,
    "name": "Smoke Tests"
  },
  "executedBy": {
    "id": 1,
    "username": "admin"
  },
  "resultCounts": {
    "passed": 95,
    "failed": 2,
    "skipped": 3,
    "broken": 0,
    "total": 100
  },
  "canBeCancelled": false,
  "output": "Console output...",
  "errorMessage": null,
  "results": [
    {
      "id": 1001,
      "testName": "StorefrontCheckoutTest",
      "testId": "StorefrontCheckoutTest",
      "status": "passed",
      "duration": 12500,
      "errorMessage": null
    },
    {
      "id": 1002,
      "testName": "AdminCreateProductTest",
      "testId": "AdminCreateProductTest",
      "status": "failed",
      "duration": 8200,
      "errorMessage": "Element #save-button not found"
    }
  ],
  "reports": [
    {
      "id": 15,
      "type": "allure",
      "publicUrl": "http://localhost:5050/allure-docker-service/projects/run-42/reports/latest",
      "generatedAt": "2026-01-15T10:35:28+00:00"
    }
  ]
}
```

---

### POST /api/test-runs/{id}/cancel

Cancel a running test.

**Requirements:**
- Test must not be in a terminal status (`completed`, `failed`, `cancelled`)
- User must have `ROLE_ADMIN`
- Header `X-CSRF-Token` must contain a token valid for `test_run_api`

**Response:**
```json
{
  "message": "Run cancelled",
  "run": {
    "id": 42,
    "status": "cancelled",
    "type": "mftf",
    "canBeCancelled": false
  }
}
```

---

### POST /api/test-runs/{id}/retry

Retry a failed test.

**Requirements:**
- User must have `ROLE_ADMIN`
- Header `X-CSRF-Token` must contain a token valid for `test_run_api`

**Response:**
```json
{
  "message": "New run created",
  "run": {
    "id": 43,
    "status": "pending",
    "type": "mftf",
    "canBeCancelled": true
  }
}
```

---

## Response Format

### Success Response

```json
{
  "data": { ... },
  "meta": { ... }
}
```

Or for single items:
```json
{
  "id": 42,
  ...
}
```

### Error Response

```json
{
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "Invalid environment ID",
    "details": {
      "field": "environmentId",
      "value": 999
    }
  }
}
```

### Common Error Codes

| Code | HTTP Status | Description |
|------|-------------|-------------|
| `VALIDATION_ERROR` | 400 | Invalid request data |
| `NOT_FOUND` | 404 | Resource not found |
| `UNAUTHORIZED` | 401 | Authentication required |
| `FORBIDDEN` | 403 | Insufficient permissions |
| `CONFLICT` | 409 | Resource conflict (e.g., environment locked) |
| `SERVER_ERROR` | 500 | Internal server error |

---

## Rate Limiting

API requests are rate limited:

| Limit | Value |
|-------|-------|
| Average | 100 requests/minute |
| Burst | 50 requests |

**Response Headers:**
- `X-RateLimit-Limit` - Max requests per window
- `X-RateLimit-Remaining` - Remaining requests
- `X-RateLimit-Reset` - Seconds until reset

**Rate Limited Response (429):**
```json
{
  "error": {
    "code": "RATE_LIMITED",
    "message": "Too many requests",
    "details": {
      "retryAfter": 30
    }
  }
}
```

---

## Examples

### Create and Monitor Test Run

```bash
# Create test run
RUN_ID=$(curl -s -X POST "http://localhost:8089/api/test-runs" \
  -H "Content-Type: application/json" \
  -d '{"environmentId": 1, "type": "mftf"}' | jq -r '.id')

echo "Created run: $RUN_ID"

# Poll until complete
while true; do
  STATUS=$(curl -s "http://localhost:8089/api/test-runs/$RUN_ID" | jq -r '.status')
  echo "Status: $STATUS"

  if [ "$STATUS" = "completed" ] || [ "$STATUS" = "failed" ]; then
    break
  fi

  sleep 10
done

# Get final results
curl -s "http://localhost:8089/api/test-runs/$RUN_ID" | jq '.resultCounts'
```

### Get Failed Tests

```bash
curl -s "http://localhost:8089/api/test-runs?status=failed&limit=5" | \
  jq '.data[] | {id, environment: .environment.name, failed: .resultCounts.failed}'
```
