<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

Auth::requireAuth();

$rawId = $_GET['id'] ?? '';
try {
    $id = Validator::safeId($rawId);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo 'Invalid generation ID.';
    exit;
}

// Parse date from ID: gen_YYYYMMDD_xxxxxxxx → YYYY-MM-DD
$dateRaw  = substr($id, 4, 8);
$dateDir  = substr($dateRaw, 0, 4) . '-' . substr($dateRaw, 4, 2) . '-' . substr($dateRaw, 6, 2);
$filePath = STORAGE_PATH . '/generations/' . $dateDir . '/' . $id . '.json';

if (!file_exists($filePath)) {
    http_response_code(404);
    echo 'Generation not found.';
    exit;
}

$content = file_get_contents($filePath);
if ($content === false) {
    http_response_code(500);
    echo 'Could not read file.';
    exit;
}

$safeFilename = $id . '.json';

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $safeFilename . '"');
header('Content-Length: ' . strlen($content));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

echo $content;
