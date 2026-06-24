<!-- verity-memory:start -->
## Project Memory

This project has a knowledge graph maintained at `.verity/memory/`. Before starting
non-trivial work, scan `.verity/memory/index.md` for decisions, gotchas, and patterns
that may apply to the change you are about to make. Open specific node files via
the Read tool when the title or scope suggests relevance.

The graph is auto-maintained by Verity. Files at `.verity/memory/_archive/` are
superseded — ignore them unless investigating history.

> Durable, hand-curated guidance goes in the preserve region below (it survives
> regeneration) or anywhere OUTSIDE these markers. Everything else between the
> markers is tool-owned and overwritten on each run.

<!-- verity-memory:preserve -->
<!-- Add binding, hand-curated guidance here; it survives Verity regeneration. -->
<!-- /verity-memory:preserve -->
<!-- verity-memory:end -->

## Post-task reflection
When a task is complete (you've created a PR, the user says "done" or "ship it",
or the work is clearly finished), ask the user one question before moving on:

> "Quick reflection for future agents: what's one thing you learned during this
> task that would help next time? A decision, a gotcha, a pattern — anything
> worth remembering. (Say 'skip' to skip.)"

If the user responds (not "skip"), run `verity reflect --user-input "<their response>"`.
