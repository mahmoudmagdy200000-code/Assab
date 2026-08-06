# Claude Workflow — Laravel Backend

Converted from the Flutter/Dart workflow bundle. Same structure, same discipline, backend rules.

---

## What maps to what

| Flutter bundle | Laravel bundle | Notes |
|---|---|---|
| `CLAUDE.md` Section B (Flutter/Dart) | `CLAUDE.md` Section B (Laravel/PHP) | Rewritten end to end |
| `skills/flutter-feature` | `skills/laravel-feature` | Clean-architecture reference + full scaffolding templates |
| `skills/flutter-cubit` | `skills/laravel-action` | Action + readonly DTO + typed exception |
| `skills/flutter-code-review` | `skills/laravel-code-review` | Adds DB, security, and API-contract sections |
| `agents/code-reviewer` | same name | Laravel criteria + severity labels + deploy risk |
| `agents/debugger` | same name | Laravel symptom→cause table, artisan commands |
| `agents/test-writer` | same name | Pest/PHPUnit, factories, fakes |
| `agents/git-expert` | same name | PR template gains API Impact / Database / Deploy Steps / Rollback |
| `hooks/settings.json` | same path | Laravel-aware guardrails |
| — | `skills/laravel-query-performance` | **New** |
| — | `skills/laravel-job-queue` | **New** |
| — | `agents/migration-reviewer` | **New** |

Concept mapping used throughout:

```
Widget/Screen   → Controller + API Resource
Cubit/State     → Action + DTO
UseCase         → Action
Repository      → Repository (interface in Domain, Eloquent impl in Infrastructure)
DataSource/Dio  → Eloquent / HTTP Gateway
Entity          → Model + readonly DTO
Failure/ApiResult → Typed domain exception + central exception handler
get_it/injectable → Laravel service container + DomainServiceProvider
core/           → app/Support/
```

**Deliberate change:** Flutter returns `ApiResult<T>` because Dart has no cheap exception ergonomics. PHP does. The Laravel bundle uses **typed exceptions rendered centrally**, not a Result type. Don't port `ApiResult` into PHP — it fights the framework.

---

## Install

```
your-laravel-project/
├── CLAUDE.md                      ← from this bundle (project root)
└── .claude/
    ├── settings.json              ← from hooks/settings.json
    ├── agents/                    ← the 5 agent .md files
    └── skills/                    ← the 5 skill folders
```

```bash
mkdir -p .claude/agents .claude/skills
cp CLAUDE.md ../your-project/CLAUDE.md
cp hooks/settings.json ../your-project/.claude/settings.json
cp agents/*.md ../your-project/.claude/agents/
cp -r skills/* ../your-project/.claude/skills/
```

For a personal (all-projects) setup, use `~/.claude/` instead. Commit `.claude/` so the whole team gets the same rules.

---

## Fill these in before first use

1. **Header of `CLAUDE.md`** — Laravel version, PHP version, layout choice (Modular vs Standard), test framework, static analysis level.
2. **Layout decision** — `skills/laravel-feature` has the trade-off table. Pick one and never mix.
3. **Repository layer** — the same skill has a "when to skip it" rule. Read it before scaffolding 12 useless interfaces.
4. **Replace the `Invoice` examples** with a real module from the project so Claude pattern-matches your actual naming.

---

## Changes made to the config itself

- **Model pins → family aliases.** `model: claude-opus-4-6` became `model: opus`, `claude-sonnet-4-6` became `sonnet`. Aliases auto-track the newest model in that family instead of going stale on every release. Pin a full ID again only if you need reproducibility.
- **Removed** `enabledPlugins` entries for `figma` and `swift-lsp` (Flutter/design tooling) and the `ANTHROPIC_CUSTOM_MODEL_OPTION` env pin. Re-add if you still want them.
- **Added a `deny` list** — `migrate:fresh`, `migrate:reset`, `db:wipe`, and reads of `.env`/`*.pem`/`*.key` are blocked, not just warned about. On a backend these are irreversible in a way a Flutter rebuild never is.
- **Added a Pint PostToolUse hook** — formats only modified files (`--dirty`), never blocks. Delete the `PostToolUse` block if you format in CI instead.
- **Extended the danger regex** to cover destructive artisan and SQL commands.

---

## Baseline assumptions

Laravel 13 (released March 2026, requires PHP 8.3+; supports 8.3/8.4/8.5). Everything here also works on Laravel 12/PHP 8.2 except: `#[Scope]` attribute (use `scopeX()` methods), and `readonly class` requires PHP 8.2+. Laravel 11 has been out of security support since March 2026 — if a project is still on it, that's a risk to raise with the client before adding features.
