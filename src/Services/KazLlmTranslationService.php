<?php

namespace App\Services;

/**
 * Перевод через локальный KazLLM (Ollama / llama.cpp OpenAI API).
 * Модель: issai/LLama-3.1-KazLLM-1.0-70B-GGUF4 — запускается на отдельном GPU-сервере.
 */
class KazLlmTranslationService
{
    private const MAX_TEXT_LEN = 4000;

    public static function isEnabled(): bool
    {
        return filter_var(getenv('KAZLLM_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN);
    }

    public static function translate(
        \PDO $db,
        string $text,
        string $source = 'ru',
        string $target = 'kk'
    ): array {
        $text = trim($text);
        if ($text === '') {
            throw new \InvalidArgumentException('Пустой текст для перевода');
        }
        if (mb_strlen($text) > self::MAX_TEXT_LEN) {
            throw new \InvalidArgumentException('Текст слишком длинный (макс. ' . self::MAX_TEXT_LEN . ' символов)');
        }

        if (!self::isEnabled()) {
            throw new \RuntimeException('KazLLM отключён. Установите KAZLLM_ENABLED=true и запустите Ollama/llama.cpp.');
        }

        $cached = self::getFromCache($db, $text, $source, $target);
        if ($cached !== null) {
            return ['text' => $cached, 'cached' => true];
        }

        $translated = self::callModel($text, $source, $target);
        self::saveToCache($db, $text, $source, $target, $translated);

        return ['text' => $translated, 'cached' => false];
    }

    public static function ping(): array
    {
        if (!self::isEnabled()) {
            return ['ok' => false, 'message' => 'KAZLLM_ENABLED=false'];
        }

        $apiUrl = rtrim(getenv('KAZLLM_API_URL') ?: 'http://127.0.0.1:11434', '/');
        $provider = strtolower(getenv('KAZLLM_PROVIDER') ?: 'ollama');

        try {
            if ($provider === 'openai') {
                $resp = self::httpGet($apiUrl . '/v1/models', 5);
            } else {
                $resp = self::httpGet($apiUrl . '/api/tags', 5);
            }
            return ['ok' => $resp['code'] >= 200 && $resp['code'] < 300, 'message' => 'HTTP ' . $resp['code']];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    private static function callModel(string $text, string $source, string $target): string
    {
        $provider = strtolower(getenv('KAZLLM_PROVIDER') ?: 'ollama');
        $apiUrl = rtrim(getenv('KAZLLM_API_URL') ?: 'http://127.0.0.1:11434', '/');
        $model = getenv('KAZLLM_MODEL') ?: 'kazllm';
        $timeout = max(10, (int)(getenv('KAZLLM_TIMEOUT') ?: 120));

        $system = self::systemPrompt($source, $target);
        $user = "Переведи следующий текст:\n\n" . $text;

        if ($provider === 'openai') {
            $body = [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
                'temperature' => 0.1,
                'max_tokens' => 1024,
            ];
            $resp = self::httpPost($apiUrl . '/v1/chat/completions', $body, $timeout);
            $json = $resp['json'];
            $content = $json['choices'][0]['message']['content'] ?? '';
        } else {
            $body = [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
                'stream' => false,
                'options' => ['temperature' => 0.1],
            ];
            $resp = self::httpPost($apiUrl . '/api/chat', $body, $timeout);
            $json = $resp['json'];
            $content = $json['message']['content'] ?? '';
        }

        if ($resp['code'] < 200 || $resp['code'] >= 300) {
            $err = $json['error'] ?? ('HTTP ' . $resp['code']);
            throw new \RuntimeException(is_string($err) ? $err : json_encode($err, JSON_UNESCAPED_UNICODE));
        }

        $content = trim(self::cleanModelOutput($content));
        if ($content === '') {
            throw new \RuntimeException('Модель вернула пустой ответ');
        }

        return $content;
    }

    private static function systemPrompt(string $source, string $target): string
    {
        $langNames = [
            'ru' => 'русский',
            'kk' => 'казахский',
            'kz' => 'казахский',
        ];
        $from = $langNames[$source] ?? $source;
        $to = $langNames[$target] ?? $target;

        return "Сен кәсіби аудармашысың. Мәтінді {$from} тілінен {$to} тіліне дәл аудар. "
            . "Тек аударма мәтінін қайтар, түсіндірме жазба. "
            . "Қоғамдық кеңес, мемлекеттік органдар және ресми лексиканы дұрыс қолдан. "
            . "Есімдер мен аббревиатураларды өзгертпе.";
    }

    private static function cleanModelOutput(string $text): string
    {
        $text = trim($text);
        $text = preg_replace('/^["\']+|["\']+$/u', '', $text) ?? $text;
        $text = preg_replace('/^(Аударма|Перевод|Translation)\s*:\s*/iu', '', $text) ?? $text;
        return trim($text);
    }

    private static function getFromCache(\PDO $db, string $text, string $source, string $target): ?string
    {
        try {
            $hash = hash('sha256', $source . '|' . $target . '|' . $text);
            $stmt = $db->prepare(
                'SELECT translated_text FROM translation_cache WHERE source_hash = ? AND source_lang = ? AND target_lang = ? LIMIT 1'
            );
            $stmt->execute([$hash, $source, $target]);
            $row = $stmt->fetchColumn();
            return $row !== false ? (string)$row : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function saveToCache(\PDO $db, string $text, string $source, string $target, string $translated): void
    {
        try {
            $hash = hash('sha256', $source . '|' . $target . '|' . $text);
            $driver = defined('DB_DRIVER') ? DB_DRIVER : 'mysql';
            if ($driver === 'sqlite') {
                $stmt = $db->prepare('
                    INSERT INTO translation_cache (source_hash, source_lang, target_lang, source_text, translated_text)
                    VALUES (?, ?, ?, ?, ?)
                    ON CONFLICT(source_hash) DO UPDATE SET translated_text = excluded.translated_text
                ');
            } else {
                $stmt = $db->prepare('
                    INSERT INTO translation_cache (source_hash, source_lang, target_lang, source_text, translated_text)
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE translated_text = VALUES(translated_text)
                ');
            }
            $stmt->execute([$hash, $source, $target, $text, $translated]);
        } catch (\Throwable $e) {
            error_log('translation_cache save failed: ' . $e->getMessage());
        }
    }

    private static function httpPost(string $url, array $body, int $timeout): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new \RuntimeException('Ошибка запроса к KazLLM: ' . $err);
        }
        $json = json_decode($raw, true);
        return ['code' => $code, 'json' => is_array($json) ? $json : [], 'raw' => $raw];
    }

    private static function httpGet(string $url, int $timeout): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['code' => $code, 'raw' => $raw !== false ? $raw : ''];
    }
}
