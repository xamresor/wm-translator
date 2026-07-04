<?php
declare(strict_types=1);

require __DIR__ . '/../src/ConceptService.php';

use Translator\ConceptService;
use Translator\Lang;
use Translator\Db;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$svc    = new ConceptService();
$action = $_GET['action'] ?? 'translate';

if ($action === 'langs') {
    echo json_encode(['langs' => Lang::list(), 'ai_enabled' => $svc->aiEnabled()], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'stats') {
    echo json_encode(['ai_enabled' => $svc->aiEnabled()] + Db::stats(), JSON_UNESCAPED_UNICODE);
    exit;
}

$raw  = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
$text     = is_array($body) ? (string)($body['text'] ?? '')         : (string)($_GET['text'] ?? '');
$dir      = is_array($body) ? (string)($body['direction'] ?? 'f2m') : (string)($_GET['dir'] ?? 'f2m');
$lang     = is_array($body) ? (string)($body['lang'] ?? 'ru')       : (string)($_GET['lang'] ?? 'ru');
$moodShe  = (int)(is_array($body) ? ($body['mood_she'] ?? 50)       : ($_GET['mood_she'] ?? 50));
$moodHe   = (int)(is_array($body) ? ($body['mood_he']  ?? 50)       : ($_GET['mood_he']  ?? 50));

try {
    echo json_encode($svc->handle($text, $dir, $lang, $moodShe, $moodHe), JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
