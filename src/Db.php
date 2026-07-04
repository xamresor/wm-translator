<?php
declare(strict_types=1);

namespace Translator;

use PDO;

/**
 * SQLite-хранилище КОНЦЕПТОВ.
 * Один концепт = одна единица смысла (фраза + расшифровка) с вариантами на всех языках
 * и эмбеддингом канонической английской фразы (пивот).
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        $dir = dirname(__DIR__) . '/data';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $pdo = new PDO('sqlite:' . $dir . '/translator.sqlite');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode=WAL');
        self::migrate($pdo);
        return self::$pdo = $pdo;
    }

    private static function migrate(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS translations'); // старая схема v1
        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS concepts (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                direction  TEXT    NOT NULL,            -- f2m | m2f
                en_phrase  TEXT    NOT NULL,            -- канон. английская фраза (пивот)
                en_norm    TEXT    NOT NULL,            -- нормализованная (для UNIQUE)
                embedding  TEXT    NOT NULL,            -- JSON float[] эмбеддинга en_phrase
                variants   TEXT    NOT NULL,            -- JSON {lang:{phrase,meaning}}
                origin     TEXT    NOT NULL,            -- dict | ai
                hits       INTEGER NOT NULL DEFAULT 0,
                created_at TEXT    NOT NULL,
                updated_at TEXT    NOT NULL
            )
        SQL);
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_c_dir ON concepts(direction)');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_c_uniq ON concepts(direction, en_norm)');
    }

    public static function insert(string $direction, string $enPhrase, string $enNorm, array $embedding, array $variants, string $origin): int
    {
        $now = date('c');
        $sql = 'INSERT INTO concepts (direction, en_phrase, en_norm, embedding, variants, origin, created_at, updated_at)
                VALUES (:d,:p,:n,:e,:v,:o,:c,:u)
                ON CONFLICT(direction, en_norm) DO UPDATE SET
                    variants = excluded.variants, updated_at = excluded.updated_at';
        self::pdo()->prepare($sql)->execute([
            ':d' => $direction, ':p' => $enPhrase, ':n' => $enNorm,
            ':e' => json_encode($embedding, JSON_UNESCAPED_UNICODE),
            ':v' => json_encode($variants, JSON_UNESCAPED_UNICODE),
            ':o' => $origin, ':c' => $now, ':u' => $now,
        ]);
        return (int)self::pdo()->lastInsertId();
    }

    public static function updateVariants(int $id, array $variants): void
    {
        self::pdo()->prepare('UPDATE concepts SET variants = :v, updated_at = :u WHERE id = :id')
            ->execute([':v' => json_encode($variants, JSON_UNESCAPED_UNICODE), ':u' => date('c'), ':id' => $id]);
    }

    /** Все концепты направления — для брутфорс-косинуса. */
    public static function allFor(string $direction): array
    {
        $st = self::pdo()->prepare('SELECT id, en_phrase, embedding, variants, origin FROM concepts WHERE direction = :d');
        $st->execute([':d' => $direction]);
        return $st->fetchAll();
    }

    public static function bumpHit(int $id): void
    {
        self::pdo()->prepare('UPDATE concepts SET hits = hits + 1 WHERE id = :id')->execute([':id' => $id]);
    }

    public static function stats(): array
    {
        $pdo = self::pdo();
        $perLang = [];
        foreach ($pdo->query('SELECT variants FROM concepts')->fetchAll() as $row) {
            foreach (array_keys(json_decode($row['variants'], true) ?: []) as $lang) {
                $perLang[$lang] = ($perLang[$lang] ?? 0) + 1;
            }
        }
        return [
            'concepts'      => (int)$pdo->query('SELECT COUNT(*) FROM concepts')->fetchColumn(),
            'ai'            => (int)$pdo->query("SELECT COUNT(*) FROM concepts WHERE origin='ai'")->fetchColumn(),
            'dict'          => (int)$pdo->query("SELECT COUNT(*) FROM concepts WHERE origin='dict'")->fetchColumn(),
            'hits'          => (int)$pdo->query('SELECT COALESCE(SUM(hits),0) FROM concepts')->fetchColumn(),
            'variants_lang' => $perLang,
        ];
    }
}
