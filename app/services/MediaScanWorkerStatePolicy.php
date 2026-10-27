<?php

/**
 * Conservative lifecycle policy for media-scan-worker cache entries.
 *
 * The worker state is a cache, not an authoritative catalog. A candidate that
 * disappears from a single discovery cycle must therefore never be deleted
 * immediately: removable/network filesystems can become partially visible
 * while the mount point itself is still readable.
 *
 * Cleanup is allowed only after the candidate path is explicitly confirmed
 * absent from a readable parent directory for multiple healthy cycles and for
 * a minimum grace period. Explicit lifecycle operations (ignored/excluded/root
 * relocation) continue to remove/reset state immediately in the worker.
 */
class MediaScanWorkerStatePolicy
{
    public const MISSING_CONFIRMATIONS = 3;
    public const MISSING_GRACE_SECONDS = 300;

    /**
     * Human-readable candidate label relative to the active runtime root.
     */
    public static function label(string $path, string $root): string
    {
        $path = self::normalizePath($path);
        $root = self::normalizePath($root);

        if ($path === '') {
            return '<unknown>';
        }

        if ($root !== '') {
            if ($path === $root) {
                return basename($path) !== '' ? basename($path) : $path;
            }

            if (strpos($path, $root . '/') === 0) {
                $relative = substr($path, strlen($root) + 1);
                return $relative !== '' ? $relative : $path;
            }
        }

        return $path;
    }

    /**
     * Clears delayed-missing bookkeeping when a candidate is seen again.
     * All import/readiness signatures are preserved verbatim.
     *
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    public static function markSeen(array $entry): array
    {
        unset(
            $entry['missing_since'],
            $entry['missing_cycles'],
            $entry['missing_last_confirmed_at']
        );

        return $entry;
    }

    /**
     * Evaluate one state entry absent from the current discovery result.
     *
     * The absence is authoritative only when:
     * - both known path and runtime root are absolute and related;
     * - the known directory itself is not currently present;
     * - its parent directory exists and can be listed successfully;
     * - the basename is absent from that successful listing.
     *
     * Any uncertainty resets the confirmation streak. This is deliberate: a
     * transient filesystem problem must bias toward preserving state.
     *
     * @param array<string,mixed> $entry
     * @return array{remove:bool,entry:array<string,mixed>,reason:string}
     */
    public static function evaluateMissing(
        array $entry,
        string $knownPath,
        string $runtimeRoot,
        int $now
    ): array {
        $knownPath = self::normalizePath($knownPath);
        $runtimeRoot = self::normalizePath($runtimeRoot);

        if (
            $knownPath === ''
            || $runtimeRoot === ''
            || $knownPath === $runtimeRoot
            || !self::pathWithin($knownPath, $runtimeRoot)
        ) {
            return [
                'remove' => false,
                'entry' => self::markSeen($entry),
                'reason' => 'unverifiable-path',
            ];
        }

        // The directory still exists: it may temporarily not qualify as a
        // candidate, but that is not proof that the state entry is obsolete.
        if (@is_dir($knownPath)) {
            return [
                'remove' => false,
                'entry' => self::markSeen($entry),
                'reason' => 'path-still-present',
            ];
        }

        $parent = self::normalizePath(dirname($knownPath));
        if (
            $parent === ''
            || !self::pathWithin($parent, $runtimeRoot)
            || !@is_dir($parent)
            || !@is_readable($parent)
        ) {
            return [
                'remove' => false,
                'entry' => self::markSeen($entry),
                'reason' => 'parent-unavailable',
            ];
        }

        $entries = @scandir($parent);
        if (!is_array($entries)) {
            return [
                'remove' => false,
                'entry' => self::markSeen($entry),
                'reason' => 'parent-scan-failed',
            ];
        }

        if (in_array(basename($knownPath), $entries, true)) {
            return [
                'remove' => false,
                'entry' => self::markSeen($entry),
                'reason' => 'directory-entry-still-present',
            ];
        }

        $missingSince = isset($entry['missing_since'])
            ? (int)$entry['missing_since']
            : $now;
        if ($missingSince <= 0 || $missingSince > $now) {
            $missingSince = $now;
        }

        $missingCycles = max(0, (int)($entry['missing_cycles'] ?? 0)) + 1;

        $entry['missing_since'] = $missingSince;
        $entry['missing_cycles'] = $missingCycles;
        $entry['missing_last_confirmed_at'] = $now;

        $graceElapsed = ($now - $missingSince) >= self::MISSING_GRACE_SECONDS;
        $enoughConfirmations = $missingCycles >= self::MISSING_CONFIRMATIONS;

        return [
            'remove' => $graceElapsed && $enoughConfirmations,
            'entry' => $entry,
            'reason' => $graceElapsed && $enoughConfirmations
                ? 'confirmed-missing'
                : 'missing-awaiting-confirmation',
        ];
    }

    private static function normalizePath(string $path): string
    {
        // Filesystem names are byte-significant. Do not trim whitespace.
        $path = str_replace('\\', '/', $path);
        if ($path === '' || $path[0] !== '/') {
            return '';
        }

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    private static function pathWithin(string $path, string $root): bool
    {
        if ($path === '' || $root === '') {
            return false;
        }
        if ($root === '/') {
            return isset($path[0]) && $path[0] === '/';
        }

        return $path === $root || strpos($path, $root . '/') === 0;
    }
}
