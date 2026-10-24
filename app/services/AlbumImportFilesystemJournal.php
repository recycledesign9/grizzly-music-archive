<?php

/**
 * Filesystem compensation journal for one bound album import.
 *
 * DB transactions cannot roll back files. This journal therefore separates:
 *
 * - files created during the attempt:
 *     removed on rollback, retained on commit;
 *
 * - files that should be deleted because they were replaced:
 *     NEVER deleted before DB COMMIT, deleted only after commit.
 *
 * The journal itself performs no DB work.
 */
final class AlbumImportFilesystemJournal
{
    /** @var array<string,bool> */
    private array $createdFiles = [];

    /** @var array<string,bool> */
    private array $deleteAfterCommit = [];

    /** @var array<string,bool> canonical allowed filesystem roots */
    private array $allowedRoots = [];

    private bool $rootGuardEnabled = false;
    private bool $finalized = false;

    /**
     * @param string[] $allowedRoots
     *
     * Empty roots keep compatibility with legacy standalone tests only.
     * Production bound execution MUST provide explicit roots.
     */
    public function __construct(array $allowedRoots = [])
    {
        if (empty($allowedRoots)) {
            return;
        }

        foreach ($allowedRoots as $root) {
            if (!is_string($root)) {
                throw new InvalidArgumentException('Allowed filesystem root must be a string.');
            }

            $canonical = $this->canonicalizePath($root);
            if ($canonical === '') {
                throw new InvalidArgumentException('Allowed filesystem root is empty.');
            }

            if ($this->isFilesystemRoot($canonical)) {
                throw new RuntimeException(
                    'Filesystem root cannot be used as an import journal root: ' . $canonical
                );
            }

            $this->allowedRoots[$canonical] = true;
        }

        if (empty($this->allowedRoots)) {
            throw new RuntimeException('Filesystem journal has no valid allowed roots.');
        }

        $this->rootGuardEnabled = true;
    }

    public function recordCreatedFile(string $path): void
    {
        $this->assertOpen();

        $path = $this->canonicalizePath($path);
        if ($path === '') {
            throw new InvalidArgumentException('Created file path is empty.');
        }

        $this->assertAllowedPath($path, 'Created file');
        $this->createdFiles[$path] = true;
    }

    public function deferDeleteFile(string $path): void
    {
        $this->assertOpen();

        $path = $this->canonicalizePath($path);
        if ($path === '') {
            throw new InvalidArgumentException('Deferred delete path is empty.');
        }

        $this->assertAllowedPath($path, 'Deferred delete');
        $this->deleteAfterCommit[$path] = true;
    }

    /**
     * Finalize after DB COMMIT.
     *
     * Old/replaced files are deleted best-effort. Failure is diagnostic only:
     * the committed DB state remains valid and the old file is merely orphaned.
     *
     * @return array<string,mixed>
     */
    public function commit(): array
    {
        $this->assertOpen();

        $deleted = [];
        $deleteFailures = [];

        foreach (array_keys($this->deleteAfterCommit) as $path) {
            $deletePath = $this->revalidateBeforeDelete(
                $path,
                'Deferred delete'
            );

            if ($deletePath === null) {
                $deleteFailures[] = $path;
                continue;
            }

            if (!file_exists($deletePath)) {
                continue;
            }

            if (@unlink($deletePath)) {
                $deleted[] = $deletePath;
            } else {
                $deleteFailures[] = $deletePath;
            }
        }

        $report = [
            'created_files_kept' => array_values(array_keys($this->createdFiles)),
            'deleted_after_commit' => $deleted,
            'delete_failures' => $deleteFailures,
        ];

        $this->createdFiles = [];
        $this->deleteAfterCommit = [];
        $this->finalized = true;

        return $report;
    }

    /**
     * Compensate after DB ROLLBACK or failure before BEGIN.
     *
     * @return array<string,mixed>
     */
    public function rollback(): array
    {
        if ($this->finalized) {
            return [
                'created_files_removed' => [],
                'remove_failures' => [],
                'deferred_deletes_cancelled' => [],
            ];
        }

        $removed = [];
        $removeFailures = [];

        $created = array_reverse(array_keys($this->createdFiles));
        foreach ($created as $path) {
            $removePath = $this->revalidateBeforeDelete(
                $path,
                'Rollback remove'
            );

            if ($removePath === null) {
                $removeFailures[] = $path;
                continue;
            }

            if (!file_exists($removePath)) {
                continue;
            }

            if (@unlink($removePath)) {
                $removed[] = $removePath;
            } else {
                $removeFailures[] = $removePath;
            }
        }

        $report = [
            'created_files_removed' => $removed,
            'remove_failures' => $removeFailures,
            'deferred_deletes_cancelled' => array_values(
                array_keys($this->deleteAfterCommit)
            ),
        ];

        $this->createdFiles = [];
        $this->deleteAfterCommit = [];
        $this->finalized = true;

        return $report;
    }

    public function isFinalized(): bool
    {
        return $this->finalized;
    }

    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        return [
            'finalized' => $this->finalized,
            'created_files' => array_values(array_keys($this->createdFiles)),
            'delete_after_commit' => array_values(
                array_keys($this->deleteAfterCommit)
            ),
        ];
    }

    private function assertOpen(): void
    {
        if ($this->finalized) {
            throw new RuntimeException('Filesystem journal is already finalized.');
        }
    }

    /**
     * Re-check immediately before unlink to close the symlink/rename TOCTOU
     * window. A guard failure is reported as a filesystem failure instead of
     * throwing after DB COMMIT or masking the original rollback exception.
     */
    private function revalidateBeforeDelete(string $path, string $label): ?string
    {
        if (!$this->rootGuardEnabled) {
            return $path;
        }

        try {
            $canonical = $this->canonicalizePath($path);
            $this->assertAllowedPath($canonical, $label);
            return $canonical;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function assertAllowedPath(string $path, string $label): void
    {
        if (!$this->rootGuardEnabled) {
            return;
        }

        foreach (array_keys($this->allowedRoots) as $root) {
            if ($path !== $root && strpos($path, $root . '/') === 0) {
                return;
            }
        }

        throw new RuntimeException(
            $label . ' path is outside allowed filesystem roots: ' . $path
        );
    }

    /**
     * Canonicalizes an absolute file path even when the final path does not yet
     * exist. The longest existing ancestor is resolved with realpath(), so
     * symlink escapes and lexical ../ escapes cannot bypass the root guard.
     */
    private function canonicalizePath(string $path): string
    {
        $path = $this->normalizePath($path);
        if ($path === '') {
            return '';
        }

        if (!$this->isAbsolutePath($path)) {
            throw new InvalidArgumentException(
                'Filesystem journal paths must be absolute: ' . $path
            );
        }

        $probe = $path;
        $tail = [];

        while (!file_exists($probe) && !is_link($probe)) {
            $parent = $this->normalizePath(dirname($probe));
            if ($parent === '' || $parent === $probe) {
                break;
            }

            array_unshift($tail, basename($probe));
            $probe = $parent;
        }

        $real = realpath($probe);
        if ($real === false) {
            throw new RuntimeException(
                'Cannot canonicalize filesystem journal path: ' . $path
            );
        }

        $canonical = $this->normalizePath($real);

        foreach ($tail as $component) {
            if ($component === '' || $component === '.') {
                continue;
            }

            if ($component === '..') {
                $canonical = $this->normalizePath(dirname($canonical));
                continue;
            }

            $canonical = $canonical === '/'
                ? '/' . $component
                : $canonical . '/' . $component;
        }

        return $this->normalizePath($canonical);
    }

    private function isAbsolutePath(string $path): bool
    {
        return strpos($path, '/') === 0
            || (bool)preg_match('/^[A-Za-z]:\//', $path);
    }

    private function isFilesystemRoot(string $path): bool
    {
        return $path === '/'
            || (bool)preg_match('/^[A-Za-z]:\/$/', $path);
    }

    private function normalizePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));

        if ($path === '') {
            return '';
        }

        if ($path === '/' || preg_match('/^[A-Za-z]:\/$/', $path)) {
            return $path;
        }

        return rtrim($path, '/');
    }
}
