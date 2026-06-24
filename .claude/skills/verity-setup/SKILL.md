# /verity-setup — Configure Verity for this project

You are setting up Verity, a quality gate for AI-generated code. Follow every step below. Do not skip steps or reorder them.

**Reference files** (installed in this project at `.claude/skills/verity-setup/`):
- `patterns-reference.yaml` — Research-backed patterns catalog (quality, security, language-specific)
- `standard-template.yaml` — YAML skeleton for the Standard

---

## Step 1: Check prerequisites

1. Verify `verity` CLI is installed: `which verity`
   - If missing: tell the user to run the installer: `curl -fsSL https://raw.githubusercontent.com/codacy/verity/main/install.sh | bash` and stop.
2. Verify `codacy-analysis` is installed: `which codacy-analysis`
   - If missing: tell the user to run `npm install -g @codacy/analysis-cli` and stop.
3. Check if `VERITY.md` already exists in the project root:
   - If it exists and user didn't pass `--force`: say "Already configured. Run `/verity-setup --force` to reconfigure." and stop.
   - If it exists with `--force`: continue (will overwrite).
4. Get the git remote URL: `git remote get-url origin`
   - If no remote: ask the user for a project identifier to use instead.

---

## Step 2: Analyze the codebase

Detect the following by reading project files:

**Languages** — count files by extension:
```bash
find . -type f \( -name "*.ts" -o -name "*.tsx" -o -name "*.js" -o -name "*.jsx" -o -name "*.py" -o -name "*.go" -o -name "*.java" -o -name "*.kt" -o -name "*.rb" -o -name "*.rs" -o -name "*.c" -o -name "*.cpp" \) -not -path "*/node_modules/*" -not -path "*/.git/*" -not -path "*/dist/*" -not -path "*/vendor/*" | sed 's/.*\.//' | sort | uniq -c | sort -rn
```

**Frameworks** — check config files and dependencies:
- Read `package.json` → check `dependencies` and `devDependencies` for React, Express, Next.js, Vue, Angular, Fastify, NestJS, etc.
- Read `pyproject.toml` or `requirements.txt` → check for Django, Flask, FastAPI, etc.
- Read `go.mod` → check for gin, echo, fiber, etc.
- Read `pom.xml` or `build.gradle` → check for Spring, etc.

**Architecture** — check for markers:
- `lerna.json`, `pnpm-workspace.yaml`, `nx.json`, `turbo.json` → monorepo
- Multiple `go.mod` or multiple `package.json` → monorepo
- `services/` or `packages/` directories → monorepo or microservices
- Single root → monolith

**Existing tool configs** — check for configs already present:
- `eslint.config.*` or `.eslintrc*` → ESLint already configured (note the path)
- `.semgrep*` or `semgrep.yaml` → Semgrep rules
- `trivy.yaml` → Trivy config (note the path)
- `ruff.toml` or `pyproject.toml [tool.ruff]` → Ruff config
- `.pylintrc` → Pylint config
- `.codacy/tools-configs/` → Codacy tool configs (note any files here)

**Context** — read for additional insight:
- `README.md` — project description, purpose
- `CLAUDE.md` — existing agent instructions
- Recent `git log --oneline -10` — commit patterns

Record all findings. You will use them in the next steps.

---

## Step 3: Ask analysis intensity

Use the **AskUserQuestion** tool to ask:

> **Analysis intensity:**
> - **lightweight** — Critical security only. Fastest (~3s). Good for rapid iteration.
> - **balanced** (recommended) — Security + quality. Good coverage (~8s).
> - **thorough** — All tools, all rules. Most comprehensive (~15s).
>
> Which mode? [balanced]

Default to `balanced` if the user says "default" or doesn't specify a preference.

---

## Step 3b: Ask about cost & usage telemetry (opt-in)

Cost & usage observability is **opt-in** and **required** for the `/usage` dashboard — all
cost and token data comes from Claude Code's own OpenTelemetry export, so with telemetry off
the dashboard stays empty.

First check the current state (so you don't re-ask if it's already on):

```bash
verity telemetry check
```

- If it reports **enabled**, don't re-ask — tell the user telemetry is already on and continue
  (mention `verity telemetry uninstall` only if they want to turn it off).
- If **disabled**, use the **AskUserQuestion** tool:

> **Enable Claude Code cost & usage telemetry for this project?**
> Verity can show cost, tokens, a per-agent / per-session / model breakdown, and a fleet cost
> tree — by receiving Claude Code's built-in **OpenTelemetry** export. This sends usage
> **metrics and traces only** (model names, token counts, USD cost, agent types, session IDs).
> It does **NOT** send your prompts, code, or tool input/output. Without it, the `/usage`
> dashboard stays empty.
> - **Yes** (recommended) — enable telemetry
> - **No** — skip (you can enable later with `verity telemetry install`)

Remember the answer; you act on it in Step 7b (the token must exist first). **Declining is
fine and reversible** — nothing is written, and the next `/verity-setup` re-offers it.

---

## Step 4: Synthesize the Standard

Read `.claude/skills/verity-setup/patterns-reference.yaml` and `.claude/skills/verity-setup/standard-template.yaml` from the project root.

Generate `.verity/standard.yaml` by filling in the template:

1. **knowledge_spec**: Fill from Step 2 findings (project_name from git remote or directory, languages, frameworks, architecture, build_system, test_framework).

2. **quality_dimensions**: Keep all 4. Adjust thresholds if the existing codebase diverges significantly (e.g., if average file length is 500, set threshold to 400 instead of 300). Add language-specific type_safety signals from the patterns reference.

3. **security_patterns**: Keep all 7. Populate `enforced_by` fields based on detected languages and the tool recommendations in the patterns reference. For tools with existing configs, add those references.

4. **custom_patterns**: Synthesize 2-5 project-specific patterns by analyzing:
   - Project structure (e.g., "all files in `api/` use auth middleware")
   - README/docs stated conventions
   - Existing code patterns (e.g., "RLS policies on all Supabase tables")
   - Each pattern needs: id, description, severity, rationale

5. **process_constraints**: Set `analysis_mode` to the user's choice from Step 3. Keep `self_healing_limit: 2`. Leave the knowledge-system flags (`compound_enabled`, `memory_graph_enabled`, `memory_graph_budget_tokens`, `knowledge_injection_budget_tokens`, `finding_autosuppress_threshold`) and intent thresholds (`intent_fail_threshold`, `intent_warn_threshold`) at their template defaults — they're surfaced so users can tune later, not so you pick new values during setup.

6. **tool_configuration**: Based on detected languages + mode, select tools from the patterns reference `tool_recommendations` section. For existing tool configs, set the config_file path.

Write the file to `.verity/standard.yaml`. Show the user a summary:
> **Standard synthesized:**
> - Languages: typescript, python
> - Quality dimensions: 4 (comprehensibility, modularity, type_safety, test_adequacy)
> - Security patterns: 7 (3 critical, 4 high)
> - Custom patterns: 3 (auth-middleware, rls-policy, error-boundary)
> - Analysis mode: balanced
> - Tools: ESLint9, Semgrep, Trivy

Ask for confirmation before proceeding.

---

## Step 5: Configure analysis CLI

**ALWAYS OVERWRITE `.codacy/codacy.config.json` completely.** Do NOT read an existing config and tweak it — delete it and write a fresh file from scratch using the template below. Do NOT run `codacy-analysis init` either.

### Why this matters

`"patterns": []` means **ALL default patterns** for a tool:
- ESLint9 = 2,900+ rules → massive output, slow, token explosion
- Semgrep = 2,517 rules → massive output, slow, token explosion
- Ruff = 773 rules → same problem

**NEVER use `"patterns": []`.** Always populate patterns with specific patternId entries from `patterns-reference.yaml` section 8 (`curated_patterns`). This is the single most important step for keeping analysis fast and token-efficient.

### How to build the config

1. Read `patterns-reference.yaml` → `curated_patterns` section
2. For each tool selected for this mode, copy its pattern list into the config
3. For tools with an **existing local config** (e.g., `eslint.config.js`): use `localConfigurationFile` to point to it. When `localConfigurationFile` is set, the tool uses its native config and the `patterns` array is ignored — but you still MUST include at least one pattern in the array (the CLI crashes without it). Use a single placeholder: `[{ "patternId": "no-eval" }]` for ESLint, etc.
4. For tools **without** a local config: populate the full curated pattern list from `patterns-reference.yaml`

### Template — write this file exactly

Delete `.codacy/codacy.config.json` and write a new one. This example is for TypeScript **balanced** mode. Adapt the tool list and patterns for the detected languages and mode.

```json
{
  "version": 1,
  "metadata": {
    "source": "local",
    "languages": ["TypeScript"]
  },
  "tools": [
    {
      "toolId": "ESLint9",
      "localConfigurationFile": "./eslint.config.js",
      "patterns": [
        { "patternId": "no-eval" },
        { "patternId": "no-implied-eval" },
        { "patternId": "no-new-func" },
        { "patternId": "no-script-url" },
        { "patternId": "no-unused-vars" },
        { "patternId": "no-undef" },
        { "patternId": "no-unreachable" },
        { "patternId": "no-constant-condition" },
        { "patternId": "no-dupe-keys" },
        { "patternId": "no-duplicate-case" },
        { "patternId": "no-fallthrough" },
        { "patternId": "no-self-assign" },
        { "patternId": "no-self-compare" },
        { "patternId": "use-isnan" },
        { "patternId": "valid-typeof" },
        { "patternId": "no-loss-of-precision" },
        { "patternId": "no-unsafe-optional-chaining" },
        { "patternId": "@typescript-eslint/no-explicit-any" },
        { "patternId": "@typescript-eslint/no-unused-vars" },
        { "patternId": "@typescript-eslint/no-unsafe-assignment" },
        { "patternId": "@typescript-eslint/no-unsafe-call" },
        { "patternId": "@typescript-eslint/no-unsafe-return" },
        { "patternId": "eqeqeq" },
        { "patternId": "no-var" },
        { "patternId": "prefer-const" }
      ]
    },
    {
      "toolId": "Semgrep",
      "patterns": [
        { "patternId": "javascript.lang.security.audit.sqli.node-sequelize-sqli" },
        { "patternId": "javascript.lang.security.audit.sqli.node-knex-sqli" },
        { "patternId": "typescript.lang.security.audit.sqli.node-sequelize-sqli" },
        { "patternId": "javascript.lang.security.audit.command-injection" },
        { "patternId": "javascript.lang.security.audit.unsafe-html" },
        { "patternId": "generic.secrets.security.detected-generic-api-key" },
        { "patternId": "javascript.express.security.audit.express-jwt-not-revoked" },
        { "patternId": "javascript.jsonwebtoken.security.jwt-hardcode" }
      ]
    },
    {
      "toolId": "Trivy",
      "patterns": [
        { "patternId": "trivy_vuln" },
        { "patternId": "trivy_secret" },
        { "patternId": "trivy_config" },
        { "patternId": "trivy_license" }
      ]
    }
  ],
  "exclude": [
    "**/node_modules/**",
    "**/dist/**",
    "**/build/**",
    "**/.git/**",
    "**/vendor/**",
    "**/coverage/**"
  ]
}
```

**Tool selection by language** — ONLY include tools that apply to the detected languages:

| Language | Balanced mode tools |
|----------|-------------------|
| TypeScript/JavaScript | ESLint9 + Semgrep + Trivy |
| Python | Ruff + Semgrep + Trivy |
| Go | Semgrep + Trivy |
| Java | PMD7 + Semgrep + Trivy |
| Kotlin | detekt + Semgrep + Trivy |
| Shell | shellcheck + Trivy |
| C/C++ | cppcheck + flawfinder + Trivy |
| Dockerfile | Hadolint + Trivy |

Use curated patterns from `patterns-reference.yaml` for each tool. For Python, use the Ruff and Semgrep Python patterns. For TypeScript, use ESLint9 and Semgrep JS/TS patterns.

**Do NOT include tools for languages not in the project.** For example:
- Do NOT add ESLint9 to a Python project
- Do NOT add Checkov unless the project has Terraform/CloudFormation/K8s files
- Do NOT add Hadolint unless the project has Dockerfiles
- Do NOT add shellcheck unless the project has shell scripts

### Format rules (the CLI will crash without these)

- **Every tool MUST have a `"patterns"` array with at least one entry.** Without it: `Cannot read properties of undefined (reading 'map')`.
- **NEVER use `"patterns": []`** — this enables ALL defaults (thousands of rules).
- `"toolId"` is the field name, NOT `"name"` or `"tool"`.
- Exact adapter IDs: `ESLint9`, `Semgrep`, `Trivy`, `Ruff`, `Bandit`, `shellcheck`, `Hadolint`, `PMD7`, `Checkstyle`, `detekt`, `cppcheck`, `flawfinder`, `Lizard`, `PyLintPython3`.
- `"metadata.languages"` — capitalize first letter: `"TypeScript"`, `"Python"`, `"JavaScript"`, `"Go"`, `"Java"`, `"Shell"`.

### Tool selection by mode

| Mode | Tools | Approximate pattern count |
|------|-------|--------------------------|
| lightweight | Trivy only | ~4 patterns |
| balanced | Language linter + Semgrep + Trivy | ~25 + ~8-17 + 4 = ~40 patterns |
| thorough | All applicable + Lizard | ~60-80 patterns |

### Verify

```bash
codacy-analysis analyze --install-dependencies --files src/some-small-file.ts --log-level error --output-format json 2>/dev/null
```

Expected: single-digit findings per file, not hundreds. If you see 50+ issues from one file, you likely have `"patterns": []` somewhere — fix it. The `--install-dependencies` flag ensures any missing tool binaries (ESLint9, Semgrep, Trivy, etc.) are auto-installed by the CLI — no need to install them manually.

---

## Step 6: Register with Verity service

Use the `verity` CLI to register. It handles credential storage and service URL automatically.

```bash
verity auth register --project "PROJECT_NAME" --remote "GIT_REMOTE_URL"
```

This command:
- **New project**: Registers, stores token + service URL in `.verity/credentials`, prints `project_id`. Continue.
- **Already registered**: Automatically discovers the project, ensures `.verity/credentials` has the service URL. If a token already exists in credentials, it updates the file and succeeds. If no token exists, it asks you to paste one.
- **Other errors**: Show the error and stop.

After this step, `.verity/credentials` will contain both `token` and `service_url` — all subsequent `verity` commands will work.

---

## Step 7: Upload Standard and config

### Upload the Standard

The `verity` CLI handles YAML→JSON conversion automatically:

```bash
verity standard push
```

This reads `.verity/standard.yaml`, converts it to JSON, and uploads it. Verify the output shows `version: 1`.

### Upload analysis config

```bash
verity config push
```

This reads `.codacy/codacy.config.json` and uploads it.

### Seed the knowledge base

```bash
verity memory seed
```

This derives a small set of descriptive memory nodes from what you already analyzed in Steps 2–4: a `domain/project-overview.md` from `knowledge_spec`, one `integrations/{framework}.md` per detected framework, a `domain/project-purpose.md` from the README's first paragraph if present, and sections of `CLAUDE.md` split into the appropriate domains if the file exists. Nodes are marked `source: extractor`, `created_by: seed`, so the user can tell them apart from reflections they author later. The command is idempotent — re-running is a no-op unless `--force` is passed. Tell the user how many were seeded (the command prints the count) and point them at `/knowledge` on the dashboard to review.

---

## Step 7b: Enable telemetry (only if the user opted in at Step 3b)

If — and only if — the user said **Yes** in Step 3b, enable the Claude Code telemetry export
now (the token from Step 6 must already exist):

```bash
verity telemetry install
```

This writes the `OTEL_*` env block to the gitignored `.claude/settings.local.json` (which
holds your token) and points Claude Code's OpenTelemetry exporter at Verity's OTLP endpoint.
Tell the user it takes effect on their **next** Claude Code session, that first metrics appear
within ~60s of activity, and point them at `/usage`.

If the user declined, skip this step and note that `/usage` will stay empty until they run
`verity telemetry install`.

---

## Step 8: Generate VERITY.md file

Create `VERITY.md` at the project root with this content:

```markdown
# VERITY.md — Quality Gate

> This project uses [Verity](https://verity.md) to enforce quality and security standards on AI-generated code.

**URL:** ${SERVICE_URL}
**Project:** ${PROJECT_ID}
**Standard:** v1

## Quality Dimensions
- Comprehensibility (file length, complexity, naming)
- Modularity (separation of concerns, shallow abstractions)
- Type Safety (strict types, explicit returns)
- Test Adequacy (coverage, test quality)

## Security Patterns
- No hardcoded secrets (CWE-798)
- Input sanitization (CWE-20)
- Parameterized queries (CWE-89)
- Dependency verification (CWE-1395)
- No unsafe deserialization (CWE-502)
- Access control checks (CWE-639)
- Config file integrity (CWE-15)

## How It Works
Every time the coding agent stops, the Verity hook:
1. Runs static analysis via @codacy/analysis-cli
2. Sends results + code to the Verity service
3. Gemini independently reviews the code
4. Returns PASS / WARN / FAIL with actionable findings
```

---

## Step 8b: Add knowledge base pointer and reflection instructions to CLAUDE.md

If `CLAUDE.md` exists (or `.claude/CLAUDE.md`), append these two blocks. If it doesn't exist, create it with these blocks. Do NOT overwrite existing content — append only.

```markdown

## Project Knowledge Base
> Read `.verity/memory/index.md` for accumulated project context, decisions, and conventions.

## Post-task reflection
When a task is complete (you've created a PR, the user says "done" or "ship it",
or the work is clearly finished), ask the user one question before moving on:

> "Quick reflection for future agents: what's one thing you learned during this
> task that would help next time? A decision, a gotcha, a pattern — anything
> worth remembering. (Say 'skip' to skip.)"

If the user responds (not "skip"), run `verity reflect --user-input "<their response>"`.
```

These blocks enable two capabilities:
1. **Knowledge Base pointer** — agents can browse `.verity/memory/` for project context on demand (KNOWLEDGE-SYSTEM §8.2 Path B)
2. **Post-task reflection** — the compound moment where the user's judgment enters the knowledge base (KNOWLEDGE-SYSTEM §9.3)

---

## Step 9: Verify hooks

The installer already wired the Claude Code hooks via `verity hooks install`. Verify they're in place:

```bash
verity hooks check
```

Expected output:
```
[verity] Stop hook (verity analyze): installed
[verity] Intent hook (verity intent capture): installed
```

If hooks are missing (e.g., user installed manually without the installer), install them:

```bash
verity hooks install
```

This wires both the Stop hook (`verity analyze`) and UserPromptSubmit hook (`verity intent capture`) into `.claude/settings.json`, preserving any existing hooks.

---

## Step 10: Update .gitignore

Add these entries to `.gitignore` (create it if it doesn't exist, append if it does):

```
# Verity
.verity/credentials
.verity/.cache/
.verity/.logs/
.verity/.last-analysis
.verity/.iteration-count
.verity/.last-pass-hash
.verity/.last-intent
.verity/.memory-sync-state.json
.verity/memory/log.md
.claude/settings.local.json
```

`.claude/settings.local.json` holds the telemetry env block **including your token**, so it
must never be committed (`verity telemetry install` also adds this entry automatically).

Do NOT gitignore `.verity/standard.yaml` or `VERITY.md` — those should be committed.

**Commit the knowledge graph, but not its log.** The nodes under `.verity/memory/<domain>/` and `.verity/memory/index.md` are durable project knowledge meant to be committed and reviewed. But `.verity/memory/log.md` is an append-only, per-run timestamped activity log — it churns on every analysis and carries no reviewable content, so it is gitignored above. If a project already committed it, untrack it once with `git rm --cached .verity/memory/log.md`.

The `.verity/credentials` file contains the project token and MUST be gitignored.

---

## Step 11: Show summary

Print a complete summary:

```
=== Verity Setup Complete ===

Project:      ${PROJECT_NAME}
Languages:    ${LANGUAGES}
Frameworks:   ${FRAMEWORKS}
Architecture: ${ARCHITECTURE}
Mode:         ${MODE}

Standard:     v1 (4 quality, 7 security, N custom patterns)
Tools:        ${TOOL_LIST}
Service:      registered (project_id: ${PROJECT_ID})
Hooks:        installed (verity analyze + verity intent capture)
Telemetry:    ${TELEMETRY_STATUS}   (enabled → cost+usage at /usage, or disabled)

Files created:
  .verity/standard.yaml        — Quality standard
  .codacy/codacy.config.json   — Analysis CLI config
  VERITY.md                    — Project quality overview
  .claude/settings.json        — Hook configuration (verified)
  .claude/settings.local.json  — Telemetry env (only if enabled; gitignored)
  .gitignore                   — Updated with Verity entries

Next: You should now be seeing the first analysis happening below. The hook will fire automatically every time your agent stops working on something.
```
