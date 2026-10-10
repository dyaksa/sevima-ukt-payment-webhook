<?php

/**
 * Claude Code PreToolUse guard for Bash: refuses `git commit --no-verify` (or -n),
 * which would skip the project's git hooks in .githooks/ (CLAUDE.md hard limits 3, 6).
 *
 * Only the part of the command before the message (-m, -F, a heredoc) is checked,
 * so a commit message that merely mentions the flag is not blocked.
 */
$input = json_decode((string) stream_get_contents(STDIN), true) ?: [];
$command = (string) ($input['tool_input']['command'] ?? '');

$commitAt = strpos($command, 'git commit');
if ($commitAt === false) {
    exit(0);
}

$beforeMessage = preg_split('/\s(?:-m|-F|--message|--file)\b|<</', substr($command, $commitAt))[0];

if (preg_match('/(^|\s)(--no-verify|-[a-zA-Z]*n[a-zA-Z]*)(\s|$)/', $beforeMessage) === 1) {
    fwrite(STDERR, 'git commit --no-verify (or -n) is not allowed: the git hooks in .githooks/ are project guards.');

    exit(2);
}

exit(0);
