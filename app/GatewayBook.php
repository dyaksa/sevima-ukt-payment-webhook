<?php

namespace App;

use Generator;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * The mock gateway's private, append-only record, independent of delivery.
 */
class GatewayBook
{
    public const string PATH = 'gateway-book.jsonl';

    /**
     * @param  list<array<string, mixed>>  $payloads
     */
    public function append(string $institutionCode, array $payloads): void
    {
        $disk = Storage::disk('local');

        if (! $disk->makeDirectory('.')) {
            throw new RuntimeException('Cannot create the gateway book directory.');
        }

        $lines = implode('', array_map(fn (array $payload): string => json_encode([
            'institution_code' => $institutionCode,
            'payload' => $payload,
        ], JSON_THROW_ON_ERROR)."\n", $payloads));

        try {
            $written = file_put_contents($disk->path(self::PATH), $lines, FILE_APPEND | LOCK_EX);
        } catch (Throwable $exception) {
            throw new RuntimeException('Cannot append to the gateway book. No notifications were delivered.', previous: $exception);
        }

        if ($written !== strlen($lines)) {
            throw new RuntimeException('Cannot append to the gateway book. No notifications were delivered.');
        }
    }

    /**
     * @return Generator<int, array{institution_code: string, payload: array<string, mixed>}>
     */
    public function entries(): Generator
    {
        $disk = Storage::disk('local');

        if (! $disk->exists(self::PATH)) {
            return;
        }

        if (! is_file($disk->path(self::PATH)) || ! is_readable($disk->path(self::PATH))) {
            throw new RuntimeException('Cannot read the gateway book.');
        }

        try {
            $handle = fopen($disk->path(self::PATH), 'rb');
        } catch (Throwable $exception) {
            throw new RuntimeException('Cannot read the gateway book.', previous: $exception);
        }

        if ($handle === false) {
            throw new RuntimeException('Cannot read the gateway book.');
        }

        try {
            if (! flock($handle, LOCK_SH)) {
                throw new RuntimeException('Cannot lock the gateway book for reading.');
            }

            while (($line = fgets($handle)) !== false) {
                $entry = json_decode($line, true, flags: JSON_THROW_ON_ERROR);

                if (! is_array($entry) || ! is_string($entry['institution_code'] ?? null) || ! is_array($entry['payload'] ?? null)) {
                    throw new RuntimeException('Invalid gateway book entry.');
                }

                yield $entry;
            }

            if (! feof($handle)) {
                throw new RuntimeException('Cannot finish reading the gateway book.');
            }

        } finally {
            fclose($handle);
        }
    }
}
