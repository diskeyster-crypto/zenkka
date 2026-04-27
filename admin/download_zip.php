<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

Auth::requireAuth();

// ── Validate generation ID ────────────────────────────────────────────────────
$rawId = $_GET['id'] ?? '';
try {
    $id = Validator::safeId($rawId);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo 'Invalid generation ID.';
    exit;
}

// ── Load generation JSON ──────────────────────────────────────────────────────
$dateRaw  = substr($id, 4, 8);
$dateDir  = substr($dateRaw, 0, 4) . '-' . substr($dateRaw, 4, 2) . '-' . substr($dateRaw, 6, 2);
$filePath = STORAGE_PATH . '/generations/' . $dateDir . '/' . $id . '.json';

if (!file_exists($filePath)) {
    http_response_code(404);
    echo 'Generation not found.';
    exit;
}

$gen   = JsonStore::read($filePath);
$files = is_array($gen['files'] ?? null) ? $gen['files'] : [];

if (empty($files)) {
    http_response_code(404);
    echo 'No exported files found for this generation.';
    exit;
}

// ── Check ZipArchive availability ────────────────────────────────────────────
if (!class_exists('ZipArchive')) {
    http_response_code(500);
    echo 'ZipArchive extension is not available.';
    exit;
}

// ── Build ZIP from file list (never from user-supplied paths) ────────────────
$pathGuard = new PathGuard();
$exportsBase = EXPORTS_PATH;

$tmpZip = tempnam(sys_get_temp_dir(), 'zenkka_zip_') . '.zip';

$zip = new ZipArchive();
if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    echo 'Failed to create ZIP archive.';
    exit;
}

$addedCount = 0;
foreach ($files as $fileRecord) {
    if (!is_array($fileRecord)) {
        continue;
    }
    $filename = $fileRecord['filename'] ?? '';
    // path in metadata is relative (e.g. "vpn/file_1.txt") — resolve against exports base
    $relPath  = $fileRecord['path']     ?? $filename;

    // Always resolve through PathGuard to prevent traversal
    $absPath  = $exportsBase . '/' . ltrim($relPath, '/');
    $absPath  = realpath($absPath) ?: $absPath;

    // Ensure it's inside exports
    if (!$pathGuard->ensureInsideExports($absPath)) {
        Logger::error('download_zip: file outside exports', ['path' => $absPath]);
        continue;
    }

    if (!is_file($absPath)) {
        continue;
    }

    // Add with relative path inside zip (just filename, or with subfolder)
    $zipEntryName = $filename !== '' ? $filename : basename($absPath);
    $zip->addFile($absPath, $zipEntryName);
    $addedCount++;
}

$zip->close();

if ($addedCount === 0) {
    @unlink($tmpZip);
    http_response_code(404);
    echo 'No readable files found to zip.';
    exit;
}

// ── Build safe download filename ─────────────────────────────────────────────
$fnBuilder    = new FileNameBuilder();
$generationSlug = $fnBuilder->sanitizeFilename(
    ($gen['generation_slug'] ?? $id) . '_' . date('Ymd') . '.zip',
    false
);
// Force .zip extension
$generationSlug = pathinfo($generationSlug, PATHINFO_FILENAME) . '.zip';

// ── Send the ZIP to browser ───────────────────────────────────────────────────
$zipContent = file_get_contents($tmpZip);
@unlink($tmpZip);

if ($zipContent === false) {
    http_response_code(500);
    echo 'Failed to read ZIP archive.';
    exit;
}

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . addslashes($generationSlug) . '"');
header('Content-Length: ' . strlen($zipContent));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

echo $zipContent;
