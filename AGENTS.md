# AGENTS.md — Rules for AI Assistants

> 👉 **Naya AI/developer? Sabse pehle [`START-HERE.md`](START-HERE.md) padho** — server ki live state `server-snapshot/STATE.md` me hai.


> **RULE 0 — PARITY CONTRACT:** `docs/09-cpanel-parity-checklist.md` defines "done" for this project.
> Har feature us file ki ek row se juda hai. Kaam karke us row ka status ✅/🟡 update karna **compulsory** hai.
> Koi row hatana allowed **nahi**. Saare non-optional rows ✅ = project complete.

**Read `AI_CONTEXT.md` first. Then `docs/04-coding-standards.md` and `docs/08-module-blueprint.md`.**

## Hard Rules

1. **Never** write code that runs the web panel as root, or executes shell commands with
   string interpolation. Agent tasks only, array-form exec, allowlist in `agent/config/tasks.php`.
2. **Never** change WHM API 1 response shapes (billing software depends on them).
3. **Never** make license failures disable customer websites/email. Degrade the panel instead.
4. **Always** add/update:
   - feature test (and agent-task test when privileged code is involved)
   - permission entry (`config/permissions.php`) + `docs/03-security-matrix.md`
   - audit event for privileged actions
   - module doc (`docs/modules/<module>.md`) and `CHANGELOG.md` line
5. **Always** follow the module blueprint — one shape for all modules. No snowflake patterns.
6. **Always** use `declare(strict_types=1)`, full type hints, and docblocks with `@acp-task`
   annotation where a method maps to an agent task.
7. **Ask before** adding new dependencies, changing DB columns, or altering ports/URLs.

## When Unsure

- State assumptions explicitly in your summary.
- Prefer the smallest change that fits the blueprint.
- If a task crosses the privileged boundary, stop and follow `docs/03-security-matrix.md` §4.

## Commit / Change Conventions

- Conventional commits: `feat(accounts): ...`, `fix(email): ...`, `docs(...)`, `chore(...)`.
- Every PR/change summary must include: what changed, why, tests run, docs updated.
- Keep `AI_CONTEXT.md` §2 (Status) current.
