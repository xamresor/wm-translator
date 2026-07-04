<?php
declare(strict_types=1);

// Полное заполнение: словарь → концепты (если пусто) + ВСЕ 10 языков у каждого концепта.
//   php bin/fill.php
require __DIR__ . '/../src/ConceptService.php';

use Translator\ConceptService;
use Translator\Db;

$log = fn($m) => fwrite(STDOUT, $m . "\n");
$svc = new ConceptService();

if (!$svc->aiEnabled()) {
    fwrite(STDERR, "Нет ключа Google AI в .env\n");
    exit(1);
}

$have = Db::stats()['concepts'];
if ($have === 0) {
    $log('Концептов нет — прогреваю словарь…');
    $n = $svc->seedDictionary($log);
    $log("Прогрето концептов: $n\n");
}

$log('Заполняю все языки…');
$r = $svc->fillAll($log);
$log("\nГотово. Концептов затронуто: {$r['concepts']}, добавлено вариантов: {$r['variants_added']}");

$s = Db::stats();
$log('Итог по языкам: ' . json_encode($s['variants_lang'], JSON_UNESCAPED_UNICODE));
