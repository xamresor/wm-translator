<?php
declare(strict_types=1);

namespace Translator;

require_once __DIR__ . '/Env.php';
require_once __DIR__ . '/Lang.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/GoogleAi.php';

/**
 * Мультиязычный переводчик на концепт-хранилище.
 *
 * Поток (фраза P, направление D, язык L):
 *   1. en = пивот: P→английский (если L≠en)
 *   2. vec = embed(en)
 *   3. ищем ближайший концепт по косинусу (все эмбеддинги — английские)
 *   4а. хит + есть вариант L  → отдаём из кэша
 *   4б. хит, нет варианта L   → доматериализуем L и кладём в тот же концепт
 *   4в. промах                → генерим смысл (en) + локализуем в L, создаём концепт
 */
final class ConceptService
{
    private float $threshold;

    public function __construct(
        private ?GoogleAi $ai = null,
    ) {
        Env::load(dirname(__DIR__) . '/.env');
        $this->ai ??= new GoogleAi(Env::get('GOOGLE_AI_API_KEY'));
        $this->threshold = (float)(Env::get('SIMILARITY_THRESHOLD') ?: 0.78);
    }

    public function aiEnabled(): bool
    {
        return $this->ai->enabled();
    }

    /**
     * @return array{text:string,empty:bool,direction:string,lang:string,source:string,similarity?:float}
     */
    public function handle(string $text, string $direction, string $lang, int $moodShe = 50, int $moodHe = 50): array
    {
        $dir = $direction === 'm2f' ? 'm2f' : 'f2m';
        $L   = Lang::normalize($lang);
        $base = ['empty' => false, 'direction' => $dir, 'lang' => $L];

        if (trim($text) === '') {
            return ['text' => '', 'source' => 'empty'] + ['empty' => true] + $base;
        }
        if (!$this->ai->enabled()) {
            return ['text' => 'Включи ключ Google AI в .env — без него мультиязычный режим не работает.', 'source' => 'no-key'] + $base;
        }

        // Сдвинут хоть один слайдер настроения → свежая расшифровка под эмоцию (без кэша)
        if (abs($moodShe - 50) > 10 || abs($moodHe - 50) > 10) {
            [$spMood, $liMood] = $dir === 'm2f' ? [$moodHe, $moodShe] : [$moodShe, $moodHe];
            $meaning = $this->ai->decodeMood(
                $text, $dir, Lang::promptName($L),
                self::moodDesc($spMood), self::moodDesc($liMood)
            );
            return ['text' => $meaning, 'source' => 'ai-mood', 'mood_she' => $moodShe, 'mood_he' => $moodHe] + $base;
        }

        // 1) канонический английский пивот
        $enPhrase = $L === Lang::PIVOT ? $text : $this->ai->toEnglish($text);
        $enNorm   = $this->norm($enPhrase);

        // 2) эмбеддинг пивота
        $vec = $this->ai->embed($enNorm);

        // 3) ближайший концепт
        $best = $this->nearest($dir, $vec);
        if ($best && $best['sim'] >= $this->threshold) {
            return $this->fromConcept($best, $L) + ['similarity' => round($best['sim'], 3)] + $base;
        }

        // 4в) промах — создаём концепт
        $enMeaning = $this->ai->decode($enPhrase, $dir);
        $variants  = [Lang::PIVOT => ['phrase' => $enPhrase, 'meaning' => $enMeaning]];
        $out       = $variants[Lang::PIVOT];
        if ($L !== Lang::PIVOT) {
            $out = $this->ai->localize($enPhrase, $enMeaning, Lang::promptName($L));
            $variants[$L] = $out;
        }
        Db::insert($dir, $enPhrase, $enNorm, $vec, $variants, 'ai');

        return ['text' => $out['meaning'], 'source' => 'ai'] + $base;
    }

    /** Отдать вариант L из концепта, при необходимости доматериализовав. */
    private function fromConcept(array $concept, string $L): array
    {
        $id       = (int)$concept['id'];
        $variants = json_decode($concept['variants'], true) ?: [];

        if (!empty($variants[$L]['meaning'])) {
            Db::bumpHit($id);
            return ['text' => $variants[$L]['meaning'], 'source' => 'cache'];
        }

        // 4б) доматериализуем язык из английского варианта
        $en = $variants[Lang::PIVOT] ?? null;
        if (!$en) {
            return ['text' => '(нет английского варианта для перевода)', 'source' => 'cache'];
        }
        $loc          = $this->ai->localize($en['phrase'], $en['meaning'], Lang::promptName($L));
        $variants[$L] = $loc;
        Db::updateVariants($id, $variants);
        Db::bumpHit($id);
        return ['text' => $loc['meaning'], 'source' => 'cache-new-lang'];
    }

    private function nearest(string $dir, array $vec): ?array
    {
        $best = null;
        foreach (Db::allFor($dir) as $row) {
            $emb = json_decode($row['embedding'], true);
            if (!is_array($emb)) {
                continue;
            }
            $sim = self::cosine($vec, $emb);
            if ($best === null || $sim > $best['sim']) {
                $best = $row + ['sim' => $sim];
            }
        }
        return $best;
    }

    /** 0 (happy) … 100 (angry) → словесное описание для промпта. */
    public static function moodDesc(int $m): string
    {
        return match (true) {
            $m <= 12 => 'very happy, loving and playful',
            $m <= 37 => 'in a good, content, relaxed mood',
            $m <= 62 => 'neutral / normal',
            $m <= 87 => 'noticeably irritated and annoyed',
            default  => 'really angry and upset',
        };
    }

    public static function cosine(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        $dot = $na = $nb = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $na  += $a[$i] * $a[$i];
            $nb  += $b[$i] * $b[$i];
        }
        return ($na == 0.0 || $nb == 0.0) ? 0.0 : $dot / (sqrt($na) * sqrt($nb));
    }

    private function norm(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = str_replace('ё', 'е', $s);
        $s = preg_replace('/[.,!?;:"\'()«»…\-—]+/u', ' ', $s) ?? $s;
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return trim($s);
    }

    /** Заполнить ВСЕ языковые варианты у всех концептов (по одному запросу на концепт). */
    public function fillAll(callable $log = null): array
    {
        $langs   = array_keys(Lang::LANGS);
        $touched = 0;
        $added   = 0;
        foreach (['f2m', 'm2f'] as $dir) {
            foreach (Db::allFor($dir) as $row) {
                $id       = (int)$row['id'];
                $variants = json_decode($row['variants'], true) ?: [];
                $en       = $variants[Lang::PIVOT] ?? null;
                if (!$en || empty($en['meaning'])) {
                    $log && $log("skip #$id: нет английского пивота");
                    continue;
                }
                $missing = [];
                foreach ($langs as $l) {
                    if (empty($variants[$l]['meaning'])) {
                        $missing[$l] = Lang::promptName($l);
                    }
                }
                if (!$missing) {
                    continue;
                }
                try {
                    $loc = $this->ai->localizeMany($en['phrase'], $en['meaning'], $missing);
                    foreach ($loc as $l => $v) {
                        if (!empty($v['meaning'])) {
                            $variants[$l] = $v;
                            $added++;
                        }
                    }
                    Db::updateVariants($id, $variants);
                    $touched++;
                    $log && $log("#$id «{$en['phrase']}» +" . count($loc) . " яз.");
                } catch (\Throwable $e) {
                    $log && $log("ERR #$id: " . $e->getMessage());
                }
            }
        }
        return ['concepts' => $touched, 'variants_added' => $added];
    }

    /** Прогрев словаря в концепты (ru→en пивот + варианты ru,en). */
    public function seedDictionary(callable $log = null): int
    {
        require_once __DIR__ . '/Translator.php';
        $dict  = new Translator();
        $count = 0;
        foreach (['f2m', 'm2f'] as $dir) {
            foreach ($dict->phrasebookFull($dir) as [$ruPhrase, $ruMeaning]) {
                try {
                    $enPhrase  = $this->ai->toEnglish($ruPhrase);
                    $enMeaning = $this->ai->toEnglish($ruMeaning);
                    $enNorm    = $this->norm($enPhrase);
                    $vec       = $this->ai->embed($enNorm);
                    $variants  = [
                        'en' => ['phrase' => $enPhrase, 'meaning' => $enMeaning],
                        'ru' => ['phrase' => $ruPhrase, 'meaning' => $ruMeaning],
                    ];
                    Db::insert($dir, $enPhrase, $enNorm, $vec, $variants, 'dict');
                    $count++;
                    $log && $log("[$dir] $ruPhrase → $enPhrase");
                } catch (\Throwable $e) {
                    $log && $log("SKIP [$dir] $ruPhrase: " . $e->getMessage());
                }
            }
        }
        return $count;
    }
}
