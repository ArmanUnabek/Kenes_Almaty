# VERITY.md — Quality Gate

> This project uses [Verity](https://verity.md) to enforce quality and security standards on AI-generated code.

**Project:** ArmanUnabek/Kenes_Almaty
**Standard:** v1 (`.verity/standard.yaml`)
**Service:** _not yet registered_ — run `verity auth register` with a Verity token to enable the hosted review (see below).

## Quality Dimensions
- Comprehensibility (file length, complexity, naming)
- Modularity (separation of concerns, shallow abstractions over PDO)
- Type Safety (typed params/returns, `ATTR_EMULATE_PREPARES=false`)
- Test Adequacy (PHPUnit; repositories covered via in-memory SQLite)

## Security Patterns (CWE-mapped)
- No hardcoded secrets (CWE-798)
- Input sanitization (CWE-20/80/89/117)
- Parameterized queries — PDO prepared statements (CWE-89)
- Dependency verification (CWE-1395)
- No unsafe deserialization (CWE-502)
- Access-control checks — `auth_middleware` + `App\Auth\AccessPolicy` (CWE-639/862)
- Config file integrity (CWE-15)

## Project-specific patterns
- **api-auth-required** — endpoints under `api/` must gate on `checkAuth()`/`requireRole()`.
- **region-scoping** — region-owned data must pass `region_id` / `AccessPolicy`; no cross-region access.
- **pdo-prepared-statements** — all SQL via PDO `prepare()`/`execute()`.
- **output-escaping** — escape user data in HTML (`AppUtils.escapeHtml` / `htmlspecialchars`).
- **csrf-on-mutations** — POST/PUT/DELETE protected by `CsrfMiddleware` + the `csrf-handler.js` fetch wrapper.

## How It Works
Every time the coding agent stops, the Verity Stop hook (`verity analyze`):
1. Runs static analysis via `@codacy/analysis-cli`
2. Sends results + code to the Verity service
3. An independent model reviews the code against the Standard
4. Returns PASS / WARN / FAIL with actionable findings

## ⚠️ PHP coverage note
Verity's curated static-analysis pattern catalog does **not** include PHP (this
project's primary language). The configured automated tool is **Trivy**
(dependency/secret/config scanning, language-agnostic). PHP enforcement relies on
the AI reviewer + the project-specific patterns above. To add PHP static analysis,
wire up `phpstan` / `php_codesniffer` separately.

## Finishing setup (requires a Verity account/token)
This local config was generated without registering the project. To enable the
hosted gate:
```bash
verity auth register --project "ArmanUnabek/Kenes_Almaty" --remote "<git-remote>"
verity standard push      # upload .verity/standard.yaml
verity config push        # upload .codacy/codacy.config.json
verity memory seed        # seed the knowledge base
```
