<?php
declare(strict_types=1);

// Прогрев: словарь → концепты (ru→en пивот + варианты ru,en).
//   php bin/seed.php
require __DIR__ . '/../src/ConceptService.php';

use Translator\ConceptService;

try {
    $n = (new ConceptService())->seedDictionary(fn($m) => fwrite(STDOUT, $m . "\n"));
    fwrite(STDOUT, "\nПрогрето концептов: $n\n");
} catch (\Throwable $e) {
    fwrite(STDERR, 'Ошибка: ' . $e->getMessage() . "\n");
    exit(1);
}
