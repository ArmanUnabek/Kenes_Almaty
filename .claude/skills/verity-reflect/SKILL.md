# /verity-reflect — Capture learnings after a task

Trigger knowledge extraction for the current task, or submit a human reflection that becomes a high-confidence knowledge node.

## Auto-reflection (extract from task history)

When called without `--user-input`, triggers the LLM extractor to review the current task's run history and produce 0-3 knowledge nodes:

```bash
verity reflect
```

This is the same extraction that runs automatically on task close and every 5 runs. Use this to manually trigger it mid-task if you've learned something significant.

## Human reflection (the compound moment)

When the user provides their own insight, it becomes a high-confidence node (`source: user`, `confidence: 1.0`) that is never auto-archived:

```bash
verity reflect --user-input "The Stripe retry logic needs idempotency keys or we double-charge"
```

The CLI will:
1. Call the extractor LLM to classify the reflection into a domain (decisions/, patterns/, gotchas/, etc.)
2. Set `source: 'user'` and `confidence: 1.0`
3. Opportunistically link to existing nodes
4. Write the node locally and sync to cloud

## When to reflect

The agent should ask for a reflection at natural task-completion moments:

- After creating a PR
- When the user says "done", "ship it", or "that's it"
- When a task is explicitly closed

The reflection prompt (installed in CLAUDE.md during setup):

> "Quick reflection for future agents: what's one thing you learned during this task that would help next time? A decision, a gotcha, a pattern — anything worth remembering. (Say 'skip' to skip.)"

If the user says "skip", do NOT call `verity reflect`. The reflection is optional.

## Examples of good reflections

- "The Stripe retry logic needs to use idempotency keys or we double-charge. Learned this the hard way." → `gotchas/stripe-idempotency-keys.md`
- "We decided to use advisory locks instead of optimistic locking because Supabase supports it natively." → `decisions/advisory-locks-for-versioning.md`
- "Don't touch the RLS policies without updating the cleanup cron job — they're coupled." → `gotchas/rls-cleanup-coupling.md`
