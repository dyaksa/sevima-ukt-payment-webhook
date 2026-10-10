<?php

/**
 * Claude Code PreToolUse guard for Edit and Write (CLAUDE.md hard limits 1, 3, 6).
 *
 * Denies a tool call by printing a permissionDecision; every other case exits 0
 * so the normal permission flow applies. It only sees edits made through the
 * Edit and Write tools, so tests/Feature/SourceGuardsTest.php backs it up.
 */
$input = json_decode((string) stream_get_contents(STDIN), true) ?: [];
$toolInput = $input['tool_input'] ?? [];

$path = (string) ($toolInput['file_path'] ?? '');
$text = (string) ($toolInput['content'] ?? $toolInput['new_string'] ?? '');
foreach ($toolInput['edits'] ?? [] as $edit) {
    $text .= "\n".($edit['new_string'] ?? '');
}

$root = rtrim((string) (getenv('CLAUDE_PROJECT_DIR') ?: getcwd()), '/').'/';
$relative = str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;

function deny(string $reason): never
{
    echo json_encode(['hookSpecificOutput' => [
        'hookEventName' => 'PreToolUse',
        'permissionDecision' => 'deny',
        'permissionDecisionReason' => $reason,
    ]]);

    exit(0);
}

// Hard limit 6: a committed migration is history; schema changes go in a new migration.
if (str_starts_with($relative, 'database/migrations/')) {
    exec('git -C '.escapeshellarg($root).' cat-file -e '.escapeshellarg('HEAD:'.$relative).' 2>/dev/null', $output, $code);

    if ($code === 0) {
        deny("{$relative} is already committed. Write a new migration instead (CLAUDE.md hard limit 6); editing it needs the candidate's approval.");
    }
}

// Hard limit 1: application code reaches domain tables only through the institution scope.
if (str_starts_with($relative, 'app/') && preg_match('/->withoutGlobalScopes?\s*\(|DB::table\s*\(/', $text) === 1) {
    deny('This bypasses the institution scope. Reach domain tables through Eloquent models and their scope (CLAUDE.md hard limit 1).');
}

// Hard limit 3: secret-shaped values. A 64-hex string is the shape of an HMAC secret here.
if (preg_match('/\b[0-9a-f]{64}\b/i', $text) === 1
    || preg_match('/^INSTITUTION_SECRET_[A-Z0-9_]+=\S/m', $text) === 1) {
    deny('This looks like an HMAC secret. Secrets live in the database or in an untracked .env, never in tracked files (CLAUDE.md hard limit 3).');
}

exit(0);
