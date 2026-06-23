# Test Coverage Analysis & Improvement Proposal

_Date: 2026-06-23_

## 1. Current state

The project uses **PHPUnit 10.5** with a single `Unit` test suite (`phpunit.xml`)
and PSR-4 autoloading (`App\` → `src/`). There are **10 test files** containing
roughly **56 test methods**.

| Test file | Target | Depth |
|-----------|--------|-------|
| `SecurityTest.php` | `CsrfMiddleware`, `RateLimiter` | Good (21 tests) |
| `TotpServiceTest.php` | `TotpService` | Good (7 tests) |
| `RegionServiceTest.php` | `RegionService` | Decent (6 tests) |
| `ValidatorTest.php` | `Validator`, `LetterService` | Shallow (5 tests) |
| `KazLlmTranslationServiceTest.php` | `KazLlmTranslationService` | Decent (4 tests) |
| `NotificationRecipientPolicyTest.php` | `NotificationRecipientPolicy` | Decent (4 tests) |
| `AuditSanitizerTest.php` | `AuditSanitizer` | Decent (3 tests) |
| `EventRepositoryTest.php` | `EventRepository` | Shallow (2 tests, mocked PDO) |
| `LetterPersistenceServiceTest.php` | `LetterPersistenceService` | Shallow (2 tests) |
| `SearchAndHealthTest.php` | *(none — see below)* | Anti-pattern (2 tests) |

### Code size vs. tested surface

| Layer | Approx. lines | Test status |
|-------|--------------:|-------------|
| `src/` (domain services, repos, middleware) | ~3,380 | Partially covered (~11 of 24 classes touched) |
| `api/` (30 HTTP endpoints) | ~5,940 | **0% — no automated tests** |
| `auth_middleware.php` (RBAC + region scoping) | ~280 | **0% — untested** |
| `db.php` | ~520 | **0% — untested** |
| `cron_*.php` (4 jobs) | ~615 | **0% — untested** |
| `api/js/` (frontend) | large | **0% — no JS test setup** |

## 2. Key findings

### 2.1 No coverage measurement is configured
`phpunit.xml` has no `<coverage>`/`<source>` section, and neither **Xdebug** nor
**PCOV** is installed in the environment. There is therefore **no way to measure
line/branch coverage today**, so regressions in coverage go unnoticed. Every
number above is derived by hand.

### 2.2 `SearchAndHealthTest` tests a copy of the logic, not the code
`testHealthResponseStructure` and `testSearchItemSortingByDate` **reimplement**
the health-payload and sort logic inline inside the test and then assert on that
local copy. They never call `api/health.php` or `api/search.php`. This gives
false confidence: the real endpoints could break and these tests would stay
green. This logic should be extracted into a testable class in `src/` and tested
through that class.

### 2.3 Security-critical RBAC is completely untested
`auth_middleware.php` implements the entire authorization model — role
normalization, `requireRole`, write/delete/export gating, and **region-based row
scoping** (`resolveRegionIdForWrite`, `getActiveRegionId`, `canAccessRegion`,
`assertEventRegionAccess`). A bug here means cross-region data leakage or
privilege escalation, yet none of it is tested. The test `bootstrap.php` even
stubs `canAccessRegion` to always return true, meaning region isolation is
*assumed* rather than verified.

### 2.4 Repository tests are shallow and DB-less
`EventRepositoryTest` only checks instantiation and one structural shape with a
mocked `PDO`. `UserRepository`, `MemberRepository` (CRUD), and
`CommissionRepository` have **no direct tests** — including the parts that build
region-scoped `WHERE` clauses, which is exactly where data-isolation bugs hide.
Because PDO is mocked, the actual SQL is never executed, so column/parameter
mistakes are invisible.

### 2.5 The entire HTTP/API layer is untested
All 30 endpoints under `api/` — auth, letters (563 lines), users, members,
import/export, telegram webhook (392 lines) — have zero automated tests. This is
the largest body of code in the project and the actual contract consumed by the
frontend.

## 3. Proposed improvements (prioritized)

### Priority 1 — Make coverage observable
1. Add **PCOV** (or Xdebug) to the dev/CI environment.
2. Add a `<source>` + `<coverage>` section to `phpunit.xml` scoping `src/`.
3. Wire `composer test -- --coverage-text` (and an HTML/Clover report) into CI
   so coverage is reported on every PR. Set a baseline threshold and ratchet up.

### Priority 2 — Cover the authorization & region-scoping logic
4. Test `auth_middleware.php` directly (not via the bootstrap stub): role
   normalization, every `requireRole`/`requireWriteAccess`/`requireDeleteAccess`
   path, and `resolveRegionIdForWrite` / `canAccessRegion` for in-region vs.
   out-of-region users. These are the highest-risk untested lines in the repo.

### Priority 3 — Real repository tests against SQLite in-memory
5. Stand up an **SQLite `:memory:` PDO** in a test base class, load a minimal
   schema, and test `UserRepository`, `MemberRepository`, and
   `CommissionRepository` CRUD **with region scoping** end-to-end. This catches
   SQL/parameter bugs that mocked-PDO tests cannot.

### Priority 4 — Replace the "copy of the logic" tests with real ones
6. Extract the health-check and search-ranking logic out of `api/health.php` /
   `api/search.php` into `src/` services, then point `SearchAndHealthTest` at the
   real code.

### Priority 5 — Broaden service coverage
7. Add tests for currently-untested services where logic (not just I/O) lives:
   `EmailService` (recipient/templating logic via an injected mailer),
   `TelegramService` / `telegram_webhook` command parsing, `FileCache`
   (get/set/expiry), `SecurityAuditService`, `ErrorHandler`, and the
   `Validator` rules that aren't yet exercised (`integer`, `max`, `phone`,
   `url`, `in`, `regex`).

### Priority 6 — Smoke-test the API layer
8. Add lightweight endpoint tests (include the script with mocked
   request/session globals, assert the JSON envelope + status codes) for at least
   `auth.php`, `letters.php`, `members.php`, and `import/export`.

### Priority 7 (optional) — Frontend
9. If the JS in `api/js/` carries real logic, introduce a minimal Jest/Vitest
   setup for the pure helpers in `utils.js` / `i18n.js`.

## 4. Suggested first PR
A high-value, low-effort starting point:
- Add PCOV + coverage config to `phpunit.xml` and CI (Priority 1), and
- Add `auth_middleware.php` RBAC/region tests (Priority 2).

Together these close the riskiest gap (authorization) and make all future
coverage measurable.

## 5. Implemented in this PR (first step)

This PR ships the suggested first step:

- **Coverage is now observable** — CI runs with the **PCOV** driver
  (`coverage: pcov`) plus a non-blocking `--coverage-text` summary step, and
  `phpunit.xml` gained a `<source>` section scoping coverage to `src/`.
- **Authorization logic is now tested** — the pure decision logic was extracted
  from `auth_middleware.php` into `App\Auth\AccessPolicy` (with an
  `App\Auth\AccessDenied` exception for deny paths). `auth_middleware.php` now
  delegates to it, so production and tests share one source of truth. The new
  `tests/AccessPolicyTest.php` adds **23 tests** covering role normalization, the
  write/delete/export capability matrix, `canAccessRegion`, and every
  region-resolution branch for read and write (admin vs. non-admin, missing
  region, cross-region write attempts). Suite grew from **54 → 77 tests**.

Remaining priorities (2–7 above) are still open follow-ups.
