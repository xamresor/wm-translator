<?php
declare(strict_types=1);

namespace Translator;

/**
 * Клиент Google AI (Gemini): эмбеддинги + генерация/перевод.
 * Ключ берётся из env GOOGLE_AI_API_KEY.
 */
final class GoogleAi
{
    private const BASE        = 'https://generativelanguage.googleapis.com/v1beta/models/';
    private const EMBED_MODEL = 'gemini-embedding-001';
    private const EMBED_DIMS  = 768;
    private const GEN_MODEL   = 'gemini-flash-lite-latest';

    public function __construct(private readonly ?string $key)
    {
    }

    public function enabled(): bool
    {
        return is_string($this->key) && $this->key !== '';
    }

    /** Вектор фразы (768 float). */
    public function embed(string $text): array
    {
        $resp = $this->call(self::EMBED_MODEL . ':embedContent', [
            'model'                => 'models/' . self::EMBED_MODEL,
            'content'              => ['parts' => [['text' => $text]]],
            'outputDimensionality' => self::EMBED_DIMS,
        ]);
        $values = $resp['embedding']['values'] ?? null;
        if (!is_array($values)) {
            throw new \RuntimeException('embed: нет вектора в ответе');
        }
        return array_map('floatval', $values);
    }

    /** Перевести произвольную фразу на каноничный английский (пивот). */
    public function toEnglish(string $phrase): string
    {
        return $this->cleanLine($this->genText(
            "Translate the following short everyday phrase into natural English. "
            . "Keep it as the same kind of utterance a person would say. "
            . "Output ONLY the English phrase — no quotes, no notes.\n\nPhrase: «{$phrase}»",
            60
        ));
    }

    /** Расшифровать «настоящий смысл» английской фразы (на английском), по-доброму. */
    public function decode(string $enPhrase, string $direction): string
    {
        $role = $direction === 'm2f'
            ? 'A man said this to his partner. In a fun, casual way reveal what he actually means or feels underneath, so she gets it.'
            : 'A woman said this to her partner. In a fun, casual way reveal what she actually feels or needs underneath, so he gets it.';
        return $this->cleanLine($this->genText(
            $role . ' This is a lighthearted, funny helper for two people who like each other. '
            . 'Convey the REAL hidden meaning PRECISELY and honestly — do NOT smooth over the rough edges, '
            . 'soften it or water it down; say what is actually meant, even if it stings a bit. '
            . 'Reply with ONE short, casual, genuinely funny line (max 16 words). '
            . 'Deliver that honest truth playfully, with warmth underneath — but NOT sappy, cheesy or over-sweet, '
            . 'and never cynical, cruel, threatening or mocking. Avoid heart emojis. '
            . "No quotes, no prefixes.\n\nPhrase: «{$enPhrase}»\nWhat they really mean (precise, funny, not softened):",
            120
        ));
    }

    /**
     * Перевести английскую фразу и её расшифровку на целевой язык.
     * @return array{phrase:string,meaning:string}
     */
    public function localize(string $enPhrase, string $enMeaning, string $langName): array
    {
        $j = $this->genJson(
            "Translate these two short texts into {$langName} faithfully — keep the exact meaning without softening it, "
            . "and keep the funny, casual, lightly warm tone (not sappy or sweet; add nothing). "
            . "Use the language's native script (Ukrainian/Russian in Cyrillic), "
            . "Latin letters only if the language name explicitly says 'transliterated into Latin letters'. "
            . "Return JSON with keys \"phrase\" and \"meaning\".\n\n"
            . "PHRASE: {$enPhrase}\nMEANING: {$enMeaning}",
            220
        );
        return [
            'phrase'  => $this->cleanLine((string)($j['phrase'] ?? '')),
            'meaning' => $this->cleanLine((string)($j['meaning'] ?? '')),
        ];
    }

    /**
     * Расшифровка с учётом НАСТРОЕНИЯ говорящего и слушающего, сразу на нужном языке.
     * $speakerMood / $listenerMood — словесные описания (happy…angry).
     */
    public function decodeMood(string $phrase, string $direction, string $langName, string $speakerMood, string $listenerMood): string
    {
        [$speaker, $poss, $subj, $listener] = $direction === 'm2f'
            ? ['man', 'his', 'he', 'woman']
            : ['woman', 'her', 'she', 'man'];
        $role = "A {$speaker} said this to {$poss} partner (a {$listener}). "
            . "The {$speaker}'s actual mood right now is: {$speakerMood}. "
            . "The {$listener} hears it while feeling: {$listenerMood}. "
            . "Reveal what {$subj} REALLY means, clearly COLORED by {$poss} mood and by how it lands given the {$listener}'s mood.";
        return $this->cleanLine($this->genText(
            $role . ' This is a lighthearted, funny helper for two people who like each other. '
            . 'Convey the REAL hidden meaning PRECISELY and honestly — do NOT smooth the rough edges or soften it. '
            . 'Reply with ONE short, casual, genuinely funny line (max 18 words), playful with warmth underneath, '
            . 'not sappy, never cruel or mocking. Avoid heart emojis. '
            . "Write the answer in {$langName}. No quotes, no prefixes.\n\nPhrase: «{$phrase}»\nReal meaning:",
            150
        ));
    }

    /**
     * Перевести фразу+смысл сразу на НЕСКОЛЬКО языков за один запрос.
     * @param array<string,string> $codeToName  код языка => английское имя
     * @return array<string,array{phrase:string,meaning:string}>
     */
    public function localizeMany(string $enPhrase, string $enMeaning, array $codeToName): array
    {
        $listing = [];
        foreach ($codeToName as $code => $name) {
            $listing[] = "{$code} = {$name}";
        }
        $codes = implode(', ', array_keys($codeToName));
        $j = $this->genJson(
            "Translate the PHRASE and its MEANING into EACH of these languages faithfully — keep the exact meaning without softening it, "
            . "and keep the funny, casual, lightly warm tone (not sappy or sweet; add nothing). "
            . "Return a JSON object whose keys are EXACTLY these language codes: "
            . "[{$codes}], and each value is an object {\"phrase\":..., \"meaning\":...}.\n"
            . "IMPORTANT: write each language in its OWN native script (Ukrainian and Russian in Cyrillic, etc.). "
            . "Use Latin letters ONLY for an entry whose description explicitly says 'transliterated into Latin letters'.\n\n"
            . "Languages:\n" . implode("\n", $listing) . "\n\n"
            . "PHRASE: {$enPhrase}\nMEANING: {$enMeaning}",
            1400
        );
        $out = [];
        foreach ($codeToName as $code => $_) {
            if (isset($j[$code]) && is_array($j[$code])) {
                $out[$code] = [
                    'phrase'  => $this->cleanLine((string)($j[$code]['phrase'] ?? '')),
                    'meaning' => $this->cleanLine((string)($j[$code]['meaning'] ?? '')),
                ];
            }
        }
        return $out;
    }

    // --- низкоуровневое ---

    private function genText(string $prompt, int $maxTokens): string
    {
        $resp = $this->call(self::GEN_MODEL . ':generateContent', [
            'contents'         => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => ['temperature' => 0.9, 'maxOutputTokens' => $maxTokens, 'topP' => 0.95],
        ]);
        $out = (string)($resp['candidates'][0]['content']['parts'][0]['text'] ?? '');
        if (trim($out) === '') {
            throw new \RuntimeException('generate: пустой ответ');
        }
        return $out;
    }

    private function genJson(string $prompt, int $maxTokens): array
    {
        $resp = $this->call(self::GEN_MODEL . ':generateContent', [
            'contents'         => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => ['temperature' => 0.7, 'maxOutputTokens' => $maxTokens, 'responseMimeType' => 'application/json'],
        ]);
        $txt = (string)($resp['candidates'][0]['content']['parts'][0]['text'] ?? '');
        $txt = preg_replace('/^```(?:json)?|```$/m', '', trim($txt)) ?? $txt;
        $j = json_decode(trim($txt), true);
        if (!is_array($j)) {
            throw new \RuntimeException('genJson: не JSON: ' . mb_substr($txt, 0, 120));
        }
        // Gemini иногда оборачивает объект в массив [{...}] — разворачиваем
        if (array_is_list($j) && count($j) === 1 && is_array($j[0])) {
            $j = $j[0];
        }
        return $j;
    }

    private function cleanLine(string $s): string
    {
        $s = trim($s);
        $s = preg_replace('/^(перевод|meaning|расшифровка)\s*:\s*/iu', '', $s) ?? $s;
        return trim($s, "\"'«»\n\r ");
    }

    /** @return array decoded JSON */
    private function call(string $path, array $body): array
    {
        if (!$this->enabled()) {
            throw new \RuntimeException('GOOGLE_AI_API_KEY не задан');
        }
        $url     = self::BASE . $path . '?key=' . urlencode($this->key);
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE);

        $attempts = 4;
        for ($i = 1; $i <= $attempts; $i++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 30,
            ]);
            $raw  = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);

            if ($raw === false) {
                if ($i < $attempts) { sleep($i); continue; }
                throw new \RuntimeException("сеть: $err");
            }
            $data = json_decode((string)$raw, true);
            // 429/503 — временно, ретраим с backoff
            if (($code === 429 || $code === 503) && $i < $attempts) {
                sleep(2 * $i);
                continue;
            }
            if ($code < 200 || $code >= 300) {
                $msg = $data['error']['message'] ?? ('HTTP ' . $code);
                throw new \RuntimeException("Google AI: $msg");
            }
            return is_array($data) ? $data : [];
        }
        throw new \RuntimeException('Google AI: исчерпаны попытки');
    }
}
