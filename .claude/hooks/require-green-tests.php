<?php

/**
 * Claude Code Stop guard (CLAUDE.md hard limit 7): the agent cannot end its turn
 * while the test suite is red.
 *
 * Skipped when no PHP-related file changed in the working tree, and when Sail is
 * not running (tests cannot run, so the hook stays out of the way rather than
 * blocking the turn repeatedly). Claude Code itself stops after eight
 * consecutive blocks.
 */
$root = (string) (getenv('CLAUDE_PROJECT_DIR') ?: getcwd());

exec('git -C '.escapeshellarg($root).' status --porcelain -- app bootstrap config database routes tests', $changed);
if ($changed === []) {
    exit(0);
}

exec('cd '.escapeshellarg($root).' && docker compose ps --status running --services 2>/dev/null', $services);
if (! in_array('laravel.test', $services, true)) {
    exit(0);
}

exec('cd '.escapeshellarg($root).' && vendor/bin/sail artisan test --compact 2>&1', $output, $code);

if ($code !== 0) {
    echo json_encode([
        'decision' => 'block',
        'reason' => "Tests are red, so the work is not done (CLAUDE.md hard limit 7). Fix them first:\n"
            .implode("\n", array_slice($output, -40)),
    ]);
}

exit(0);
