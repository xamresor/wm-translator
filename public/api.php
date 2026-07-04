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

// --- rate limit (protects the Gemini key from abuse: per-IP + global daily cap) ---
if ($action === 'translate') {
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0';
    $ip = trim(explode(',', $ip)[0]);
    $dir0 = __DIR__ . '/../data';
    @mkdir($dir0, 0777, true);
    $fp = @fopen($dir0 . '/ratelimit.json', 'c+');
    if ($fp) {
        flock($fp, LOCK_EX);
        $now = time();
        $d = json_decode(stream_get_contents($fp) ?: '{}', true) ?: [];
        $winIp = 300;  $maxIp  = 40;    // 40 requests / 5 min per IP
        $winDay = 86400; $maxDay = 1000; // 1000 translations / day, all IPs (Gemini quota guard)
        $d['ip'][$ip] = array_values(array_filter($d['ip'][$ip] ?? [], fn($t) => $t > $now - $winIp));
        $d['day']     = array_values(array_filter($d['day'] ?? [],     fn($t) => $t > $now - $winDay));
        $overDay = count($d['day']) >= $maxDay;
        $overIp  = count($d['ip'][$ip]) >= $maxIp;
        if ($overIp || $overDay) {
            flock($fp, LOCK_UN); fclose($fp);
            http_response_code(429);
            header('Retry-After: 60');
            echo json_encode(['error' => $overDay ? 'Дневной лимит исчерпан, загляни завтра.' : 'Слишком часто — притормози на минутку.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $d['ip'][$ip][] = $now;
        $d['day'][]     = $now;
        if (count($d['ip']) > 5000) { $d['ip'] = array_slice($d['ip'], -1000, null, true); }
        ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($d)); fflush($fp);
        flock($fp, LOCK_UN); fclose($fp);
    }
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
