<?php

use Illuminate\Support\Facades\File;

/**
 * Text-level guards for method calls, which Pest arch tests cannot see. They also
 * catch changes made outside the agent's Edit hook, such as a shell edit.
 */
it('keeps institution scope escapes, raw table access, and unfiltered request input out of app/', function () {
    $patterns = [
        '/->withoutGlobalScopes?\s*\(/' => 'bypasses the institution scope (hard limit 1)',
        '/DB::(table|select|insert|update|delete|unprepared)\s*\(/' => 'raw query skips the institution scope (hard limit 1)',
        '/\$request->all\s*\(/' => 'unfiltered request input can carry institution_id (hard limit 2)',
    ];

    $violations = [];

    foreach (File::allFiles(app_path()) as $file) {
        foreach ($patterns as $pattern => $reason) {
            if (preg_match($pattern, $file->getContents()) === 1) {
                $violations[] = "app/{$file->getRelativePathname()}: {$reason}";
            }
        }
    }

    expect($violations)->toBe([]);
});
