<?php
declare(strict_types=1);

class PathGuard
{
    private static function exportsBase(): string
    {
        return STORAGE_PATH . '/exports';
    }

    /**
     * Sanitize a user-supplied relative path so it is safe to use inside /storage/exports.
     * Removes null bytes, prevents ../ traversal, strips dangerous characters.
     * Returns a clean relative path like "vpn" or "blog/articles" (no leading/trailing slashes).
     */
    public function sanitizeRelativePath(string $path): string
    {
        $path = str_replace("\0", '', $path);
        $path = str_replace('\\', '/', $path);
        $path = ltrim($path, '/');

        $segments = explode('/', $path);
        $safe     = [];
        foreach ($segments as $seg) {
            $seg = trim($seg);
            if ($seg === '' || $seg === '.' || $seg === '..') {
                continue;
            }
            // Allow letters (any Unicode), digits, dash, underscore, dot
            $seg = (string)preg_replace('/[^\p{L}\p{N}._\-]/u', '_', $seg);
            $seg = trim($seg, '._');
            if ($seg !== '') {
                $safe[] = $seg;
            }
        }

        return implode('/', $safe);
    }

    /**
     * Resolve a user-supplied relative path to an absolute path inside /storage/exports.
     * Always returns a path inside the exports base; never allows traversal outside it.
     */
    public function resolveExportPath(string $relativePath): string
    {
        $base  = self::exportsBase();
        $clean = $this->sanitizeRelativePath($relativePath);

        if ($clean === '') {
            return $base;
        }

        $resolved = $base . '/' . $clean;

        if (!$this->ensureInsideExports($resolved)) {
            return $base;
        }

        return $resolved;
    }

    /**
     * Verify that an absolute path is inside /storage/exports.
     */
    public function ensureInsideExports(string $absolutePath): bool
    {
        $base = self::exportsBase();

        // Normalize separators
        $norm     = str_replace('\\', '/', $absolutePath);
        $baseNorm = str_replace('\\', '/', $base);

        // Remove trailing slashes for comparison
        $baseNorm = rtrim($baseNorm, '/');

        return str_starts_with($norm, $baseNorm . '/') || $norm === $baseNorm;
    }

    /**
     * Create a directory (recursively) if it doesn't exist.
     * Returns false when creation is not possible.
     */
    public function createFolderIfNotExists(string $path): bool
    {
        if (is_dir($path)) {
            return true;
        }
        return @mkdir($path, 0755, true);
    }
}
