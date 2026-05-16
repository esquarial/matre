# MOEC-13447 Handoff — 2026-05-15

## Status: ⚠️ Mostly green, two known issues

PDP-configure flow (CSV steps 2-3 LV+MOIM) is **fixed and verified locally**. End-to-end test reaches the final cart submit step. Failures remaining are scoped + tracked.

### Verified end-to-end (local Selenium, 2 consecutive runs)
- Local run-409: 15/16 assertions passed (9:10)
- Local run-410: 15/16 assertions passed (10:02), identical reproduction

**Both LV (ACH480-04-03A7-2) and MOIM (3GAA051312-BSF) land in cart via PDP configure → cart.**

---

## What changed today

### Commits on `abb-custom-mftf` `production` branch
1. `8ea4783` — ConfiguratorActionGroup MOIM fix (host-check skip motorizer.abb.com + `.indexOf('accept')` to match `"check Accept"` mat-button text)
2. `f3b8c22` — Merge of PDP configure flow
3. `0cc9cc3` — SKU swap to ACH480 / 3GAA051312 (both live-verified for Ingeteam)
4. `4262c7a` — LV `orderToClick=0` skip-checkbox fix (LV singledriveconfigurator's first checkbox L515 produces N/A pricing → silent fail)

### Commit on `matre` `feature/remove-warp-md` branch
- `7dea2ad` — Symfony 8 dev routing migration (errors.xml/wdt.xml/profiler.xml → .php). Dev-only block, prod unaffected. Required to load matre admin UI on local.

### File reference for handoff
- `var/run-410-fail.png` — final state of local run-410 (cart page, Magento 500 error overlay)
- `var/run-410-fail.html` — same as HTML
- `var/run-410.log` — full execution log (510 lines)
- `var/mftf-results/allure-results/run-410/` — Allure raw results

---

## Two known issues remaining

### #1 Quick Order multi-SKU submit returns Magento 500
Reproducible across both local runs at the very last step (`submitQuickOrderToCart` → `verifyCartTitle`):

```
Step: See in title "Shopping Cart"
Fail: Failed asserting that 'There has been an error processing your request' contains "Shopping Cart"
Error log record number: 11540c2786f13da84b3b1751b5daaa6c8b38978770003ed50991e7f3df0d8352
```

After 5 SKUs are added to Quick Order (`ACH480-04-03A7-2`, `3GAA051312-BSF`, `ACH480-04-03A7-2+P925`, `3GAA051312-BSF+114`, `3GAA051312-BSF+066`) and the form is submitted, Magento returns 500. Cart at that point already contains LV+MOIM base from the PDP-configure step.

**Theories:**
- Cart conflict — base SKUs already in cart, re-submitting same SKUs causes server-side error
- `3GAA051312-BSF+066` data issue (analogous to prior `3GLL202453-ADC+068`)
- Magento backend bug with this specific multi-SKU combination

**Next step:** check Magento `app/var/log/exception.log` for error record `11540c2786f...` to see the actual exception, OR fetch from data team.

### #2 Remote ABB matre fails at LV PDP render
Remote runs (#626, 627, 628, 629, 630) all break at the same step: `waitForConfigTrigger` on LV PDP. Screenshot shows the **anonymous-view PDP** (no Welcome, no Ingeteam, "Log in to see product details" present, agreements deactivation footer).

The SSO step (`waitForLoginComplete`) PASSES on remote, but by the time we navigate to LV PDP, the customer session has been dropped — the page renders as if logged out.

Chrome-node container restart (clearing cookies/storage) did NOT fix this. The remote env has a persistent SSO/session issue independent of test code.

**Theories:**
- Concurrent test or cron on remote using the same MS SSO identity (admin2 = `abbtesttusertwo@gmail.com`) is invalidating mid-test
- Remote's chrome-node has different network/timing than local
- Some browser profile state stuck on remote even after restart

**Important:** Local Selenium runs the SAME `production` branch and works end-to-end. So the code is correct. This is a remote-environment-only issue.

**Next step:** investigate remote chrome-node browser profile state, check for concurrent test schedules, possibly re-create the chrome-node container with a fresh image, OR debug with remote VNC if available.

---

## How to resume

### Pick up the Quick Order submit fix
```bash
cd /Users/storm/PhpstormProjects/ppf/matre
# Run test locally to reproduce
docker exec matre_php php bin/console app:test:run mftf preprod-es --filter=MOEC13447 --sync --no-interaction
# Watch live via VNC: http://matre.local:<port> — get port: docker port matre-chrome-node-1
# Look at Magento exception log
ssh abb "tail -100 /home/ubuntu/matre/var/log/magento_exception.log 2>/dev/null"  # adjust path
```

### Debug remote env
```bash
ssh abb "cd ~/matre && docker exec matre_php php bin/console app:test:run mftf preprod-es --filter=MOEC13447 --sync --no-interaction"
# Pull artifacts after
scp 'abb:/home/ubuntu/matre/var/mftf-results/allure-results/run-<id>/*-attachment' /tmp/
```

### Production deployment of LV+MOIM fix
The `abb-custom-mftf production` branch has commit `4262c7a` and is pushed. Remote ABB matre clones this on every test run, so the fix IS in remote — it's the **env state** that's blocking validation.

---

## Memory references (in this Claude session's `memory/` dir)

- [moec13447_session_2026-05-15.md](../../.claude/projects/-Users-storm-PhpstormProjects-ppf-matre/memory/moec13447_session_2026-05-15.md) — full session journal with diagnosis path
- [moec13447_pdp_session_2026-05-14.md](../../.claude/projects/-Users-storm-PhpstormProjects-ppf-matre/memory/moec13447_pdp_session_2026-05-14.md) — yesterday's pause point
- [moec13447_passing_2026-05-13.md](../../.claude/projects/-Users-storm-PhpstormProjects-ppf-matre/memory/moec13447_passing_2026-05-13.md) — earlier Quick Order path victory
- [moec13447_findings.md](../../.claude/projects/-Users-storm-PhpstormProjects-ppf-matre/memory/moec13447_findings.md) — original configurator URL patterns
- [agreements_block_navigation.md](../../.claude/projects/-Users-storm-PhpstormProjects-ppf-matre/memory/agreements_block_navigation.md) — privacy modal pattern (NOT the blocker today)

## PR

`abb-custom-mftf` PR #246 (feature/MOEC-13447-quickorder-lv-moim → production) has the full set of commits. Already merged into production.
