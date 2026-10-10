# Enforceable guardrails for an AI coding agent (Claude Code + Laravel)

Research date: 2026-10-10. Scope: mechanisms that **block** the agent or **fail** tests/CI, mapped to the seven "Hard limits for AI" in `CLAUDE.md`.

Versions checked in this repo: Claude Code 2.1.287 (`claude --version`), laravel/framework v13.35.0, pestphp/pest v5.3.1, pestphp/pest-plugin-arch v5.0.0, laravel/boost v2.10.3 (`composer.lock`). Host tools found: PHP 8.5.10, `jq`, `gitleaks` (installed on this machine, but not a project dependency). The GitHub repository `dyaksa/sevima-ukt-payment-webhook` is **private** (`gh repo view --json visibility`), and there is no `.github/workflows/` or `.claude/settings.json` yet.

## 1. Summary

- **CLAUDE.md, `.claude/rules/` and Boost `.ai/rules` only advise.** Claude Code's docs say so directly: "Permission rules are enforced by Claude Code, not by the model. Instructions in your prompt or `CLAUDE.md` shape what Claude tries to do, but they don't change what Claude Code allows" ([permissions](https://code.claude.com/docs/en/permissions)).
- **Claude Code hooks are the strongest guard against the agent itself, and they need no new dependency.** A `PreToolUse` hook that exits 2 or returns `permissionDecision: "deny"` stops the tool call. A `Stop` hook that returns `decision: "block"` stops Claude from ending its turn (for example, while tests are red) ([hooks](https://code.claude.com/docs/en/hooks)).
- **Permission rules (`deny` / `ask`) cover path-level and command-prefix limits** such as dependencies, `.env`, and guard config. They run in a fixed order: deny, then ask, then allow ([permissions](https://code.claude.com/docs/en/permissions)). An `ask` rule fits "needs the candidate's approval" exactly. Bash patterns are prefix and glob matches, so they are brittle.
- **Pest arch tests (already installed) only see class, function, trait and enum names (`PhpParser\Node\Name`). They never see method calls.** I checked the source: `->withoutGlobalScopes()`, `$request->all()` and `->update()` are invisible to `arch()`. They can enforce "every domain model uses `BelongsToInstitution`", "no `DB` facade outside `App\Actions`" and "the verifier uses `hash_hmac` + `hash_equals`". The rest needs behavioural tests or a plain-PHP source-scan test.
- **Only the database fully closes rule 4.** A PostgreSQL `BEFORE UPDATE OR DELETE` row trigger plus a `BEFORE TRUNCATE` statement trigger stops every write path, including the query builder and mass updates. Laravel skips model events for mass updates and deletes ([Eloquent](https://laravel.com/docs/13.x/eloquent)).
- **Git hooks are native and free, but `--no-verify` skips `pre-commit` and `commit-msg`.** `prepare-commit-msg` is **not** skipped by `--no-verify` ([githooks](https://git-scm.com/docs/githooks)), so the commit-message secret check belongs there. A Claude hook can also deny `git commit --no-verify`.
- **For rule 7, GitHub required status checks need GitHub Pro/Team or a public repo.** Protected branches are not available on private repos under GitHub Free ([GitHub docs](https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-protected-branches/about-protected-branches)). In this repo, rule 7 therefore depends on the Claude `Stop` hook.
- **Larastan + `spaze/phpstan-disallowed-calls` is the only option that sees method calls by type.** Both are new dependencies, and how they match Laravel facades and `__callStatic` forwarding is unverified here.

## 2. Hard limit to guard map

Strength key: **Blocks** = the agent's action is refused before it happens. **Fails** = a test, commit or CI run fails afterwards. **Advisory** = the agent only reads it.

| # | Hard limit | Candidate guard | Mechanism | New dependency? | Strength |
|---|---|---|---|---|---|
| 1 | No unscoped queries | `PreToolUse` hook on `Edit\|Write` denies `withoutGlobalScope(s)(` and `DB::table(` in `app/` | Claude Code hook | No | Blocks (text match) |
| 1 | | `arch()`: `App\Models` classes `toUseTrait(BelongsToInstitution)`; `App` `not->toUse(DB facade)` except `App\Actions` | Pest arch test | No | Fails tests |
| 1 | | Source-scan Pest test (regex over `app/**/*.php`) | Pest test | No | Fails tests |
| 1 | | Isolation feature tests (A cannot read B → 404), already required by CLAUDE.md | Pest feature test | No | Fails tests |
| 1 | | `disallowedMethodCalls` / `disallowedStaticCalls` on `withoutGlobalScopes`, `DB::table` | Static analysis | **Yes** (larastan, phpstan-disallowed-calls) | Fails CI |
| 2 | Never trust `institution_id` from the body | Feature test: POST a foreign `institution_id`, assert the stored row has the user's institution | Pest feature test | No | Fails tests |
| 2 | | Test that `institution_id` is not in `$fillable`; `Model::preventSilentlyDiscardingAttributes()` | Pest test / Laravel built-in | No | Fails tests (throws) |
| 2 | | `disallowedMethodCalls` on `Illuminate\Http\Request::all()` | Static analysis | **Yes** | Fails CI |
| 3 | No HMAC secrets in repo, logs, fixtures, commit messages | `permissions.deny` `Read(./.env)` (keeps secrets out of the agent's context and transcript) | Permission rule | No | Blocks (best-effort for file tools) |
| 3 | | `PreToolUse` hook on `Edit\|Write` denies secret-shaped content | Claude Code hook | No | Blocks (heuristic) |
| 3 | | Git `pre-commit` (staged diff) + `prepare-commit-msg` (message) secret scan | Git hook | No (grep) / **Yes** (gitleaks) | Fails commit |
| 3 | | `PreToolUse` hook denies `git commit --no-verify` / `-n` | Claude Code hook | No | Blocks |
| 4 | Transactions and webhook logs are append-only | PostgreSQL trigger raising on `UPDATE`/`DELETE`/`TRUNCATE` (new migration) | Database | No | Fails at runtime and in tests, for every code path |
| 4 | | Pest test asserting `update()`/`delete()` throws `QueryException` | Pest feature test | No | Fails tests |
| 4 | | `REVOKE UPDATE, DELETE, TRUNCATE` from a non-owner app role | Database | No (but needs a second DB role) | Fails at runtime |
| 5 | HMAC over the raw body | Feature test: sign raw bytes with odd whitespace/key order, expect accepted; sign re-encoded JSON, expect 401 | Pest feature test | No | Fails tests |
| 5 | | `arch()`: verifier class `toUse(['hash_hmac', 'hash_equals'])` | Pest arch test | No | Fails tests |
| 6 | No new deps, no edits to committed migrations, no real gateway | `permissions.ask` on `composer require/remove`, `npm install`, `Edit(composer.json)` etc. | Permission rule | No | Blocks until the human approves |
| 6 | | `PreToolUse` hook denies `Edit\|Write` to a migration that exists in `HEAD` | Claude Code hook | No | Blocks |
| 6 | | Git `pre-commit` rejects staged M/D/R under `database/migrations/` | Git hook | No | Fails commit |
| 6 | | `Http::preventStrayRequests()` in tests; `arch()` keeps the `Http` facade inside the mock gateway namespace | Laravel built-in / Pest arch | No | Fails tests |
| 7 | Never declare done before tests are green | `Stop` hook runs the suite and returns `decision: "block"` on failure | Claude Code hook | No | Blocks the agent from ending its turn (up to the 8-continuation cap) |
| 7 | | GitHub branch protection: required status checks | CI | **Needs** GitHub Actions + a paid plan for a private repo | Fails merge |
| all | Guard config must not be edited by the agent | `permissions.deny` `Edit(.claude/settings.json)`, `Edit(.claude/hooks/**)`; optional `ConfigChange` hook | Permission rule / hook | No | Blocks |
| all | Boost `.ai/rules`, `.claude/rules/*.md`, CLAUDE.md | Instructions | Advisory | No | Advisory |

## 3. Mechanisms

### 3.1 Claude Code hooks

Facts from the [hooks reference](https://code.claude.com/docs/en/hooks) and [hooks guide](https://code.claude.com/docs/en/hooks-guide):

- Hooks live under the `hooks` key of a settings file. `.claude/settings.json` is the shared, committable project file, and `.claude/settings.local.json` is personal and git-excluded ([settings](https://code.claude.com/docs/en/settings)).
- A command hook receives the event JSON on stdin. For tool events that includes `tool_name` and `tool_input`. `Write` input has `file_path` and `content`; `Edit` input has `file_path`, `old_string` and `new_string` ([hooks](https://code.claude.com/docs/en/hooks)).
- **Exit 2 blocks** on events that support blocking. For `PreToolUse` that means the tool call is blocked, and stderr becomes the reason given to Claude. Exit 2 blocks "even when your hook prints JSON", and even `permissionDecision: "allow"` cannot override it. Exit 0 with no decision means the "normal permission flow applies". Any other exit code is a non-blocking error unless valid JSON decides the outcome ([hooks](https://code.claude.com/docs/en/hooks)).
- Blocking per event: `PreToolUse` yes; `PostToolUse` no ("the tool already ran"; `decision: "block"` only adds feedback); `Stop` / `SubagentStop` yes ("Prevents Claude from stopping"); `TaskCompleted` yes; `ConfigChange` yes, except `policy_settings` ([hooks](https://code.claude.com/docs/en/hooks)).
- `PreToolUse` JSON control: `hookSpecificOutput.permissionDecision` is `allow|deny|ask|defer`, with `permissionDecisionReason`. `Stop` JSON control: top-level `decision: "block"` and a required `reason` ([hooks](https://code.claude.com/docs/en/hooks)).
- `Stop` loop protection: input field `stop_hook_active`, plus "an 8-consecutive-continuation cap … after stop hooks have continued the turn eight times in a row, Claude Code overrides the next block and ends the turn". The cap can be raised with `CLAUDE_CODE_STOP_HOOK_BLOCK_CAP` ([hooks](https://code.claude.com/docs/en/hooks)).
- Matchers: a value made only of letters, digits, `_`, `-`, spaces, `,` and `|` is an exact name list (`Edit|Write`). Anything else is an unanchored JavaScript regex. A per-handler `if` field takes one permission rule, for example `"Bash(git *)"` or `"Edit(*.ts)"` ([hooks](https://code.claude.com/docs/en/hooks)).
- `${CLAUDE_PROJECT_DIR}` refers to scripts relative to the project root ([hooks](https://code.claude.com/docs/en/hooks)).
- `PreToolUse` fires only on tool calls. `@file` mentions in a prompt don't trigger it, so use `Read` deny rules for those ([hooks](https://code.claude.com/docs/en/hooks)). `FileChanged` hooks run after the change and "can't block the write" ([hooks](https://code.claude.com/docs/en/hooks)).
- Interactive sessions hold back hooks until the workspace trust dialog is accepted. `-p`/SDK sessions treat the folder as trusted ([hooks, security](https://code.claude.com/docs/en/hooks)).
- Limitation (my inference, not a documented guarantee): an `Edit|Write` hook does not see files written through Bash (`sed -i`, `cat > file`). The permissions page points to [sandboxing](https://code.claude.com/docs/en/sandboxing) for "OS-level enforcement that restricts shell commands' filesystem and network access". I did not verify the sandbox configuration keys.

Example `.claude/settings.json` hooks block. The scripts are PHP because PHP 8.5 is on the host. `jq`, used in the docs' examples, also works.

```json
{
  "hooks": {
    "PreToolUse": [
      {
        "matcher": "Edit|Write",
        "hooks": [
          { "type": "command", "command": "php \"${CLAUDE_PROJECT_DIR}/.claude/hooks/guard-edit.php\"" }
        ]
      },
      {
        "matcher": "Bash",
        "hooks": [
          { "type": "command", "if": "Bash(git commit *)", "command": "php \"${CLAUDE_PROJECT_DIR}/.claude/hooks/guard-commit.php\"" }
        ]
      }
    ],
    "Stop": [
      {
        "hooks": [
          { "type": "command", "command": "php \"${CLAUDE_PROJECT_DIR}/.claude/hooks/require-green-tests.php\"", "timeout": 600 }
        ]
      }
    ]
  }
}
```

`.claude/hooks/guard-edit.php` covers rules 1, 3 and 6:

```php
<?php
// PreToolUse guard for Edit|Write. Denies by printing a permissionDecision; exit 0 otherwise.
$input = json_decode(stream_get_contents(STDIN), true);
$path = $input['tool_input']['file_path'] ?? '';
$text = $input['tool_input']['content'] ?? $input['tool_input']['new_string'] ?? '';
$root = rtrim(getenv('CLAUDE_PROJECT_DIR') ?: getcwd(), '/').'/';
$relative = str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;

$deny = function (string $reason): never {
    echo json_encode(['hookSpecificOutput' => [
        'hookEventName' => 'PreToolUse',
        'permissionDecision' => 'deny',
        'permissionDecisionReason' => $reason,
    ]]);
    exit(0);
};

// Rule 6: committed migrations are immutable; schema changes go in a new migration.
if (str_starts_with($relative, 'database/migrations/')) {
    exec('git -C '.escapeshellarg($root).' cat-file -e '.escapeshellarg('HEAD:'.$relative).' 2>/dev/null', $out, $code);
    if ($code === 0) {
        $deny("$relative is committed. Write a new migration instead (CLAUDE.md hard limit 6).");
    }
}

// Rule 1: no scope escapes in application code.
if (str_starts_with($relative, 'app/') && preg_match('/withoutGlobalScopes?\s*\(|DB::table\s*\(/', $text)) {
    $deny('Unscoped query in app/. Reach domain tables through the institution scope (hard limit 1).');
}

// Rule 3: secret-shaped literals (heuristic, tune to the project's secret format).
if (! str_ends_with($relative, '.env.example')
    && preg_match('/(secret|hmac|signing)[a-z_]*[\'"]?\s*(=>|=|:)\s*[\'"]?[A-Za-z0-9+\/_=-]{24,}/i', $text)) {
    $deny('Looks like a hard-coded secret. Read it from the database or config() (hard limit 3).');
}
exit(0);
```

`.claude/hooks/guard-commit.php` covers rule 3 by closing the `--no-verify` bypass of the git hooks:

```php
<?php
$command = json_decode(stream_get_contents(STDIN), true)['tool_input']['command'] ?? '';
if (preg_match('/git\s+commit\b.*(--no-verify|\s-[a-zA-Z]*n[a-zA-Z]*\b)/', $command)) {
    fwrite(STDERR, 'git commit --no-verify/-n is not allowed: the git hooks are project guards.');
    exit(2);
}
exit(0);
```

`.claude/hooks/require-green-tests.php` covers rule 7. Tests run through Sail, matching `.claude/rules/laravel-boost.md`:

```php
<?php
$input = json_decode(stream_get_contents(STDIN), true);
$root = getenv('CLAUDE_PROJECT_DIR') ?: getcwd();
// Skip when nothing PHP-related changed in the working tree.
exec('git -C '.escapeshellarg($root).' status --porcelain -- app tests database routes config', $changed);
if ($changed === []) {
    exit(0);
}
exec('cd '.escapeshellarg($root).' && vendor/bin/sail artisan test --compact 2>&1', $output, $code);
if ($code !== 0) {
    echo json_encode([
        'decision' => 'block',
        'reason' => "Tests are red, so the work is not done (hard limit 7). Fix them first:\n"
            .implode("\n", array_slice($output, -40)),
    ]);
}
exit(0);
```

Notes: this runs the whole suite on every stop, which can be slow, so narrow it with `--filter` or `--dirty` if needed. When `stop_hook_active` is `true` the hook still blocks, and the documented 8-continuation cap ends the loop. Sail must be running, otherwise the command fails and the hook blocks with that output.

Optional: a `ConfigChange` hook with matcher `project_settings` and exit 2 stops a mid-session edit of `.claude/settings.json` from taking effect ([hooks](https://code.claude.com/docs/en/hooks), [hooks guide](https://code.claude.com/docs/en/hooks-guide)).

Not deterministic: `type: "prompt"` and `type: "agent"` hooks (for example the guide's "Verify that all unit tests pass" agent Stop hook) ask a model to decide. The docs mark agent hooks as experimental ([hooks](https://code.claude.com/docs/en/hooks)).

### 3.2 Claude Code permission rules

Facts from [permissions](https://code.claude.com/docs/en/permissions) and [settings](https://code.claude.com/docs/en/settings):

- Evaluation order is deny, then ask, then allow. "An allow rule can't carve an exception out of a deny rule", and a matching ask rule prompts even when a narrower allow rule also matches.
- Project `deny` and `ask` rules "apply right away". Project `allow` rules wait for workspace trust ([settings](https://code.claude.com/docs/en/settings)).
- Only `Edit(path)` and `Read(path)` rules are consulted for file permissions. A `Write(...)` path rule is accepted but never consulted, so use `Edit(...)`. `Edit` rules apply to all built-in editing tools. `Read` rules are applied "best-effort" to Grep/Glob and `@file`. A `Read` deny also blocks Edit/Write on that path (Claude Code ≥ 2.1.208 for edits, ≥ 2.1.228 for writes; 2.1.287 is installed).
- Path anchors: `/x` is relative to the project root, `//x` is absolute, `~/x` is under home, and a bare `x/**` in a deny rule matches at any depth.
- Bash patterns: `*` matches any text in its position, and a trailing ` *` also matches the bare command. The docs call content matching on Bash arguments fragile (for example `git -c` can run any program). A content rule like `Bash(command:rm *)` is ignored because compound commands could bypass it.
- Managed settings (`allowManagedPermissionRulesOnly`, and so on) can stop user and project settings from overriding policy. Use them only if the reviewer must also be unable to change the guards.

Example `permissions` block for `.claude/settings.json`:

```json
{
  "permissions": {
    "deny": [
      "Read(./.env)",
      "Edit(.claude/settings.json)",
      "Edit(.claude/hooks/**)",
      "Edit(.githooks/**)"
    ],
    "ask": [
      "Bash(composer require *)",
      "Bash(composer remove *)",
      "Bash(vendor/bin/sail composer require *)",
      "Bash(vendor/bin/sail composer remove *)",
      "Bash(npm install *)",
      "Bash(vendor/bin/sail npm install *)",
      "Edit(composer.json)",
      "Edit(composer.lock)",
      "Edit(package.json)",
      "Edit(package-lock.json)"
    ]
  }
}
```

`ask` puts a human approval prompt in front of the action. That matches hard limit 6 ("without the candidate's approval") better than `deny`. Prefix variants (`./vendor/bin/sail …`, `sail …` aliases) need their own rules, which is why the docs call Bash matching brittle.

### 3.3 Pest architecture tests (installed: pest 5.3.1, pest-plugin-arch 5.0.0)

Facts:

- `arch()->expect(...)` accepts "relative namespaces, fully qualified namespaces, or function names". Presets are `arch()->preset()->php()`, `->security()`, `->laravel()`, `->strict()` and `->relaxed()`, and `->ignoring(...)` excludes targets ([Pest arch testing](https://pestphp.com/docs/arch-testing)). Custom presets are registered with `Pest\Preset::custom($name, Closure)` (`vendor/pestphp/pest/src/Preset.php`).
- Detection source (`vendor/ta-tikoma/phpunit-architecture-test/src/Asserts/Dependencies/ObjectDependenciesDescription.php`): the tool collects every `PhpParser\Node\Name` that resolves to an existing function, class, interface, trait or enum. `vendor/pestphp/pest-plugin-arch/src/Support/PhpCoreExpressions.php` adds the language constructs `clone`, `empty`, `eval`, `exit`/`die`, `isset` and `print`. **Method names are not `Name` nodes**, so method calls cannot be expressed in arch tests.
- `security` preset (`vendor/pestphp/pest/src/ArchPresets/Security.php`) forbids `md5, sha1, uniqid, rand, mt_rand, tempnam, str_shuffle, shuffle, array_rand, eval, exec, shell_exec, system, passthru, create_function, unserialize, extract, mb_parse_str, dl, assert`. `laravel` preset (`.../Laravel.php`) forbids `dd, ddd, dump, env, exit, ray` across the app, requires `App\Http\Requests` to extend `FormRequest`, limits controller public methods to resource methods, and more. None of the presets covers the project's hard limits directly.
- Expectation names verified in `vendor/pestphp/pest/src/Expectation.php`: `toUse`, `toOnlyBeUsedIn`, `toBeUsedIn`, `toUseTrait`, `toUseTraits`, `toExtend`, `toImplement`, `toHaveMethod`, `toHaveSuffix`, `classes()` (used by the presets), plus `not->…` on `OppositeExpectation`.

Example (`tests/Unit/ArchTest.php`). Run it once against the current code before relying on it, because the `laravel` preset may flag existing classes:

```php
<?php

arch()->preset()->security();

arch('domain models carry the institution scope')
    ->expect('App\Models')
    ->classes()
    ->toUseTrait('App\Models\Concerns\BelongsToInstitution')
    ->ignoring(['App\Models\Institution', 'App\Models\User']);

arch('raw DB facade only in Actions (transactions)')
    ->expect('App')
    ->not->toUse('Illuminate\Support\Facades\DB')
    ->ignoring('App\Actions');

// Catches withoutGlobalScope(InstitutionScope::class), not the zero-argument withoutGlobalScopes().
arch('InstitutionScope is referenced only by the scoping layer')
    ->expect('App\Models\Scopes\InstitutionScope')
    ->toOnlyBeUsedIn(['App\Models', 'App\Http\Middleware', 'App\Http\Requests']);

// Hypothetical class name: adjust to the real webhook verifier once it exists.
arch('webhook signature uses HMAC and constant-time compare')
    ->expect('App\Http\Middleware\VerifyWebhookSignature')
    ->toUse(['hash_hmac', 'hash_equals']);

// Keep real HTTP calls inside the mock gateway namespace (namespace is hypothetical).
arch('outbound HTTP only in the gateway boundary')
    ->expect('App')
    ->not->toUse('Illuminate\Support\Facades\Http')
    ->ignoring('App\Gateway');
```

A plain Pest source-scan test covers the method calls that arch tests miss. It needs no dependency, but it is text-level: comments can trigger false positives, and dynamic calls can evade it.

```php
<?php

use Illuminate\Support\Facades\File;

it('keeps scope escapes and raw table access out of app/', function () {
    $patterns = [
        '/->withoutGlobalScopes?\s*\(/' => 'bypasses the institution scope',
        '/DB::(table|select|insert|update|delete|statement|unprepared)\s*\(/' => 'raw query skips the institution scope',
        '/\$request->(all|input)\(\s*[\'"]?institution_id/' => 'institution_id must not come from the request',
    ];

    $violations = [];
    foreach (File::allFiles(app_path()) as $file) {
        foreach ($patterns as $pattern => $why) {
            if (preg_match($pattern, $file->getContents())) {
                $violations[] = $file->getRelativePathname().": $why";
            }
        }
    }

    expect($violations)->toBe([]);
});
```

### 3.4 Behavioural tests (Pest, no new dependency)

These are the only guards for rules 2 and 5 that check the actual behaviour.

- **Rule 5, raw body.** `MakesHttpRequests::call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)` (verified in `vendor/laravel/framework/src/Illuminate/Foundation/Testing/Concerns/MakesHttpRequests.php`) sends exact bytes. Send a body with unusual spacing and key order, signed over those raw bytes, and expect it to be accepted. Sign `json_encode(json_decode($raw))` instead and expect 401. A verifier that re-serialises the parsed payload fails the first case.
- **Rule 2, institution from auth.** Post `institution_id` set to another institution, then assert the stored row belongs to the authenticated user's institution. `app/Models/Invoice.php` already documents that `institution_id` is not fillable. `Model::preventSilentlyDiscardingAttributes()` turns any attempt to mass-assign it into an exception ([Eloquent, mass assignment exceptions](https://laravel.com/docs/13.x/eloquent)). The method is verified in `vendor/.../Eloquent/Model.php`. The docs enable it with `$this->app->isLocal()`, so enable it for the testing environment too if you want tests to fail.
- **Rule 6, no real gateway.** `Http::preventStrayRequests()` (verified in `vendor/.../Support/Facades/Http.php`) makes any unfaked outbound HTTP call throw in tests ([HTTP client docs](https://laravel.com/docs/13.x/http-client)).

### 3.5 Static analysis: Larastan + phpstan-disallowed-calls (new dependencies)

- Larastan requires PHP 8.2+ and Laravel 11.15+. Install with `composer require --dev "larastan/larastan:^3.0"`, then add `includes: [vendor/larastan/larastan/extension.neon]` and a `level` ([Larastan README](https://github.com/larastan/larastan/blob/3.x/README.md)). Built-in Laravel rules include `noEnvCallsOutsideOfConfig`, which is on by default and enforces the project's "`env()` only in `config/`" convention, and an opt-in `noImplicitQueryBuilderCall` ([Larastan rules](https://github.com/larastan/larastan/blob/3.x/docs/rules.md)).
- Custom PHPStan rules implement `PHPStan\Rules\Rule` (`getNodeType()`, `processNode()`) and return errors built with `RuleErrorBuilder` ([PHPStan custom rules](https://phpstan.org/developing-extensions/rules)). Because they are type-aware, they can match `->withoutGlobalScopes()` on an Eloquent `Builder`, which neither arch nor grep can do reliably.
- `spaze/phpstan-disallowed-calls` (`composer require --dev spaze/phpstan-disallowed-calls`) gives config-only rules: `disallowedMethodCalls`, `disallowedStaticCalls`, `disallowedFunctionCalls`, `disallowedClasses`, and others, with `message` and `allowIn` (fnmatch paths) ([README](https://github.com/spaze/phpstan-disallowed-calls/blob/main/README.md), [custom rules](https://github.com/spaze/phpstan-disallowed-calls/blob/main/docs/custom-rules.md), [allow in paths](https://github.com/spaze/phpstan-disallowed-calls/blob/main/docs/allow-in-paths.md)).

```neon
includes:
    - vendor/larastan/larastan/extension.neon
    - vendor/spaze/phpstan-disallowed-calls/extension.neon
parameters:
    level: 5
    paths:
        - app
    disallowedMethodCalls:
        -
            method: 'Illuminate\Database\Eloquent\Builder::withoutGlobalScopes()'
            message: 'bypasses InstitutionScope (hard limit 1)'
        -
            method: 'Illuminate\Http\Request::all()'
            message: 'use FormRequest::validated(); institution_id never comes from the body (hard limit 2)'
    disallowedStaticCalls:
        -
            method: 'Illuminate\Support\Facades\DB::table()'
            message: 'raw table access skips the institution scope (hard limit 1)'
```

Unverified: whether these entries match facade calls (`DB::table`, which goes through `__callStatic`) and model static forwarding (`Invoice::withoutGlobalScopes()`) once Larastan resolves them. Prove each entry with a deliberately failing fixture before relying on it.

### 3.6 Laravel Boost rules (installed v2.10.3): advisory only

- Boost "project rules" are Markdown files in `.ai/rules/` with `paths:` frontmatter globs. Boost keeps `.ai/rules/index.md` up to date, and agents are "instructed to consult this index before planning or editing" ([Boost docs, Project Rules](https://laravel.com/docs/13.x/boost)). Rules are recorded through the `record-rule` MCP tool (`vendor/laravel/boost/src/Mcp/Tools/RecordRule.php`).
- `config/boost.php` here has `rules.enabled` (default `true`), `rules.scoped_guidelines` (opt-in; moves path-scoped guidelines to `.ai/rules/boost/`), `guidelines.exclude` (currently `['deployments']`), `skills.exclude`, and `agents.claude_code.guidelines_path` = `.claude/rules/laravel-boost.md`. `.ai/rules/` does not exist yet.
- Custom guidelines go in `.ai/guidelines/…`, and a file with a matching path overrides a Boost built-in ([Boost docs](https://laravel.com/docs/13.x/boost)).
- I found no agent hook, permission or blocking feature in Boost. `grep -rli hook vendor/laravel/boost/src` only matches `BoostServiceProvider::hookIntoResponses` (browser-log injection into HTTP responses) and upgrade prompt templates. Use Boost to explain to the agent *why* a guard fires, not as a guard.

### 3.7 Git hooks and secret scanning

Facts from [githooks](https://git-scm.com/docs/githooks):

- The hooks directory defaults to `$GIT_DIR/hooks` and can be changed with `core.hooksPath`. Hooks without the executable bit are ignored. `core.hooksPath` is local git config, so each clone runs `git config core.hooksPath .githooks` once.
- `pre-commit` and `commit-msg` can be bypassed with `--no-verify`. A non-zero exit aborts the commit.
- `prepare-commit-msg` "is not suppressed by the `--no-verify` option". A non-zero exit aborts the commit, and the hook receives the message file path as `$1`. That makes it the right place for the commit-message secret check.

`.githooks/pre-commit` (no dependency) covers rules 3 and 6:

```sh
#!/bin/sh
# Rule 6: committed migrations are immutable.
changed=$(git diff --cached --name-only --diff-filter=MDR -- database/migrations)
if [ -n "$changed" ]; then
  echo "Committed migrations must not change; add a new migration:" >&2
  echo "$changed" >&2
  exit 1
fi
# Rule 3: secret-shaped additions in the staged diff (heuristic).
if git diff --cached -U0 -- . ':(exclude).env.example' \
   | grep -E '^\+' | grep -Eiq '(secret|hmac|signing)[a-z_]*["'"'"']?[[:space:]]*(=>|=|:)[[:space:]]*["'"'"']?[A-Za-z0-9+/_=-]{24,}'; then
  echo "Staged change looks like a hard-coded secret." >&2
  exit 1
fi
```

`.githooks/prepare-commit-msg`:

```sh
#!/bin/sh
if grep -Eiq '(secret|hmac)[a-z_]*[[:space:]]*[=:][[:space:]]*[A-Za-z0-9+/_=-]{24,}' "$1"; then
  echo "Commit message looks like it contains a secret." >&2
  exit 1
fi
```

Gitleaks (new tool) is a regex-based secret detector with `git`, `dir` and `stdin` scan modes. It can run through the `pre-commit` framework or a copied `pre-commit.py`, and the `stdin` mode lets a native hook pipe `git diff --cached` into it ([gitleaks README](https://github.com/gitleaks/gitleaks/blob/master/README.md)). The README now says "Gitleaks is feature complete … Future releases will be security patches only" and points to Betterleaks. It is installed on this host, but using it in the workflow still needs approval.

### 3.8 PostgreSQL: append-only tables

Facts:

- `CREATE TRIGGER … { BEFORE | AFTER } { INSERT | UPDATE | DELETE | TRUNCATE } … FOR EACH { ROW | STATEMENT }`. TRUNCATE triggers can be defined "only FOR EACH STATEMENT" ([CREATE TRIGGER](https://www.postgresql.org/docs/current/sql-createtrigger.html)).
- Statement-level and AFTER triggers "might still abort the entire operation by raising an error". A BEFORE row trigger can raise an exception to reject the row ([PL/pgSQL trigger functions](https://www.postgresql.org/docs/current/plpgsql-trigger.html)).
- `REVOKE { UPDATE | DELETE | TRUNCATE } ON table FROM role`. The owner "is always treated as holding all grant options", so the revoke only binds a role that is neither the owner nor a superuser ([REVOKE](https://www.postgresql.org/docs/current/sql-revoke.html)). Sail's default app user owns the tables, so REVOKE alone does nothing here without a second, restricted role.

Trigger as a **new** migration (`php artisan make:migration make_ledger_tables_append_only`), once `transactions` and `webhook_logs` exist:

```php
public function up(): void
{
    DB::unprepared(<<<'SQL'
        CREATE FUNCTION reject_ledger_change() RETURNS trigger LANGUAGE plpgsql AS $$
        BEGIN
            RAISE EXCEPTION '% is append-only; write a correcting row instead', TG_TABLE_NAME;
        END $$;

        CREATE TRIGGER transactions_append_only BEFORE UPDATE OR DELETE ON transactions
            FOR EACH ROW EXECUTE FUNCTION reject_ledger_change();
        CREATE TRIGGER transactions_no_truncate BEFORE TRUNCATE ON transactions
            FOR EACH STATEMENT EXECUTE FUNCTION reject_ledger_change();
        CREATE TRIGGER webhook_logs_append_only BEFORE UPDATE OR DELETE ON webhook_logs
            FOR EACH ROW EXECUTE FUNCTION reject_ledger_change();
        CREATE TRIGGER webhook_logs_no_truncate BEFORE TRUNCATE ON webhook_logs
            FOR EACH STATEMENT EXECUTE FUNCTION reject_ledger_change();
    SQL);
}
```

Follow it with a Pest test that `Transaction::query()->update([...])` and `$transaction->delete()` throw `Illuminate\Database\QueryException`. The trigger also fires inside `RefreshDatabase` transactions, so the tests exercise it. Caveat: a test suite that resets with `TRUNCATE` (for example `DatabaseTruncation`) would hit the TRUNCATE trigger. This repo uses PostgreSQL with a separate test database, so check which reset trait the tests use before adding the TRUNCATE triggers.

### 3.9 GitHub branch protection (rule 7 in CI)

- With "Require status checks before merging", required checks "must have a successful, skipped, or neutral status before collaborators can make changes to a protected branch" ([about protected branches](https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-protected-branches/about-protected-branches)).
- Admins bypass protection unless "Do not allow bypassing the above settings" is enabled (same page).
- Availability: "public repositories with GitHub Free … public and private repositories with GitHub Pro, GitHub Team, GitHub Enterprise" (same page). This repo is private, so it needs a paid plan, plus a CI workflow, which does not exist yet.

### 3.10 Curated Laravel / Pest rule sets for agents

- **Laravel Boost** is the first-party Laravel package for AI agents: guidelines, skills, project rules and an MCP server ([Boost docs](https://laravel.com/docs/13.x/boost)). It is installed, and it is advisory.
- **Pest arch presets** (`php`, `security`, `laravel`, `strict`, `relaxed`) are the maintained, first-party rule sets that actually fail ([Pest arch testing](https://pestphp.com/docs/arch-testing)). They are general hygiene rules, not agent-specific.
- I found no first-party, maintained "AI agent guardrail" rule set for Laravel or Pest beyond these two. Third-party collections were not traced to a primary owner, so they are left out.

## 4. Recommended minimal set

### A. No new dependency (do these first)

1. **`.claude/settings.json` (committed)**:
   - `permissions.deny`: `Read(./.env)` and self-protection of the guard files.
   - `permissions.ask`: `composer require/remove`, `npm install`, and edits to `composer.json`/`composer.lock`/`package*.json` (rule 6: approval).
   - `PreToolUse` hook `guard-edit.php`: committed-migration block (rule 6), scope-escape block (rule 1), secret-literal block (rule 3).
   - `PreToolUse` Bash hook denying `git commit --no-verify` (rule 3).
   - `Stop` hook that runs `vendor/bin/sail artisan test --compact` and blocks on red (rule 7).
2. **PostgreSQL append-only trigger** in a new migration, plus a test that update/delete throws (rule 4). It is the only guard that covers every write path.
3. **Pest tests in the existing suite:**
   - `arch()` for `BelongsToInstitution` on models, the `DB` facade outside `App\Actions`, and `hash_hmac`/`hash_equals` in the verifier.
   - The `security` preset.
   - A source-scan test for `withoutGlobalScopes`, `DB::table` and `$request->…institution_id` (rules 1, 2, 5).
   - Behavioural tests for the raw-body HMAC (rule 5) and for a request `institution_id` being ignored (rule 2).
   - `Http::preventStrayRequests()` in tests (rule 6, real gateway).
4. **Native git hooks in `.githooks/`** (`core.hooksPath`): `pre-commit` (migrations immutable, staged secret grep) and `prepare-commit-msg` (message secret grep, which `--no-verify` cannot skip) (rules 3, 6).
5. **Keep CLAUDE.md, `.claude/rules/` and Boost rules** as the explanation layer. Point each hard limit at the guard that enforces it, so a blocked agent knows what to do instead.

### B. Optional, need the candidate's approval

- **Larastan + `spaze/phpstan-disallowed-calls`**: type-aware bans on `withoutGlobalScopes`, `Request::all()` and `DB::table`. Prove each entry with a failing fixture.
- **Gitleaks** (or its successor Betterleaks) in `pre-commit` and CI, in place of the grep heuristic.
- **GitHub Actions workflow + branch protection with required status checks**: needs a paid GitHub plan for this private repo, or a public repo.
- **A restricted PostgreSQL app role** with `REVOKE UPDATE, DELETE, TRUNCATE` on ledger tables: a second layer on top of the trigger. It changes the Sail and database setup.
- **Claude Code sandboxing** to cover writes made through Bash, which `Edit|Write` hooks do not see. I did not verify the configuration keys ([sandboxing](https://code.claude.com/docs/en/sandboxing)).

## 5. Sources

Primary sources fetched for this note:

- Claude Code hooks reference: https://code.claude.com/docs/en/hooks
- Claude Code hooks guide: https://code.claude.com/docs/en/hooks-guide
- Claude Code permissions: https://code.claude.com/docs/en/permissions
- Claude Code settings: https://code.claude.com/docs/en/settings
- Claude Code sandboxing (linked from permissions, not read in detail): https://code.claude.com/docs/en/sandboxing
- Pest architecture testing: https://pestphp.com/docs/arch-testing
- Pest source (installed): `vendor/pestphp/pest/src/Expectation.php`, `vendor/pestphp/pest/src/ArchPresets/*.php`, `vendor/pestphp/pest/src/Preset.php`, `vendor/pestphp/pest-plugin-arch/src/**`, `vendor/ta-tikoma/phpunit-architecture-test/src/Asserts/Dependencies/ObjectDependenciesDescription.php`
- Laravel Boost docs: https://laravel.com/docs/13.x/boost; source: `vendor/laravel/boost/config/boost.php`, `vendor/laravel/boost/src/Mcp/Tools/RecordRule.php`, `vendor/laravel/boost/src/Install/RuleComposer.php`
- Laravel Eloquent (mass updates/deletes skip events, `preventSilentlyDiscardingAttributes`): https://laravel.com/docs/13.x/eloquent
- Laravel HTTP client (`preventStrayRequests`): https://laravel.com/docs/13.x/http-client; source `vendor/laravel/framework/src/Illuminate/Support/Facades/Http.php`
- Larastan README and rules: https://github.com/larastan/larastan/blob/3.x/README.md, https://github.com/larastan/larastan/blob/3.x/docs/rules.md
- PHPStan custom rules: https://phpstan.org/developing-extensions/rules
- phpstan-disallowed-calls: https://github.com/spaze/phpstan-disallowed-calls/blob/main/README.md, https://github.com/spaze/phpstan-disallowed-calls/blob/main/docs/custom-rules.md, https://github.com/spaze/phpstan-disallowed-calls/blob/main/docs/allow-in-paths.md
- Git hooks: https://git-scm.com/docs/githooks; git commit: https://git-scm.com/docs/git-commit
- Gitleaks README: https://github.com/gitleaks/gitleaks/blob/master/README.md
- PostgreSQL CREATE TRIGGER: https://www.postgresql.org/docs/current/sql-createtrigger.html; PL/pgSQL trigger functions: https://www.postgresql.org/docs/current/plpgsql-trigger.html; REVOKE: https://www.postgresql.org/docs/current/sql-revoke.html
- GitHub protected branches: https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-protected-branches/about-protected-branches
