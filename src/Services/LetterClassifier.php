<?php

namespace App\Services;

class LetterClassifier
{
    private \PDO $db;

    private const KEYWORD_FALLBACK = [
        ['name' => 'Экономика и финансы', 'keywords' => ['бюджет', 'финанс', 'налог', 'экономик', 'инвестиц', 'субсид', 'казначейство', 'бухгалтер']],
        ['name' => 'Строительство и архитектура', 'keywords' => ['строительств', 'архитектур', 'земельн', 'земл', 'кадастр', 'реконструкц', 'застройк', 'ремонт дор']],
        ['name' => 'Коммунальные услуги', 'keywords' => ['коммунальн', 'водоснабж', 'электроэнерг', 'теплоснабж', 'газификаци', 'канализац', 'жкх', 'отоплен']],
        ['name' => 'Образование', 'keywords' => ['образован', 'школ', 'учебн', 'студент', 'педагог', 'университет', 'колледж', 'детсад', 'дошкольн']],
        ['name' => 'Здравоохранение', 'keywords' => ['здравоохранен', 'больниц', 'медицин', 'поликлиник', 'здоровь', 'лечен', 'аптек', 'врач']],
        ['name' => 'Транспорт', 'keywords' => ['транспорт', 'автобус', 'дорог', 'светофор', 'пешеход', 'парковк', 'пробк', 'маршрут']],
        ['name' => 'Экология', 'keywords' => ['эколог', 'зелен', 'парк', 'дерев', 'мусор', 'свалк', 'загрязнен', 'очистк']],
        ['name' => 'Культура и спорт', 'keywords' => ['культур', 'спорт', 'молодеж', 'театр', 'музей', 'библиотек', 'стадион', 'фестивал']],
        ['name' => 'Правопорядок', 'keywords' => ['правоохран', 'полиц', 'безопасност', 'преступлен', 'пожарн', 'чрезвычайн', 'мчс']],
        ['name' => 'Предпринимательство', 'keywords' => ['предприниматель', 'малый бизнес', 'торговл', 'рынок', 'мсп', 'бизнес', 'предприним']],
    ];

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    public static function ensureTable(\PDO $db): void
    {
        static $checked = false;
        if ($checked) return;
        $checked = true;

        $driver = $db->getAttribute(\PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'sqlite') {
                $db->exec("
                    CREATE TABLE IF NOT EXISTS letter_categories (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        name VARCHAR(255) NOT NULL,
                        commission_id INTEGER NULL,
                        keywords TEXT NULL,
                        sort_order INTEGER DEFAULT 0,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    )
                ");
            } else {
                $db->exec("
                    CREATE TABLE IF NOT EXISTS letter_categories (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        name VARCHAR(255) NOT NULL,
                        commission_id INT NULL,
                        keywords TEXT NULL,
                        sort_order INT DEFAULT 0,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_sort_order (sort_order),
                        FOREIGN KEY (commission_id) REFERENCES commissions(id) ON DELETE SET NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
            }
        } catch (\Throwable $e) {
            error_log('LetterClassifier::ensureTable failed: ' . $e->getMessage());
        }
    }

    /**
     * Classify a letter by subject and body using KazLLM or keyword fallback.
     * Returns ['category_id' => int|null, 'method' => 'llm'|'keyword'|'none'].
     */
    public function classifyLetter(?string $subject, ?string $body): array
    {
        $text = trim(($subject ?? '') . ' ' . ($body ?? ''));
        if ($text === '') {
            return ['category_id' => null, 'method' => 'none'];
        }

        $llmResult = $this->classifyViaLlm($text);
        if ($llmResult !== null) {
            return ['category_id' => $llmResult, 'method' => 'llm'];
        }

        $keywordResult = $this->classifyViaKeywords($text);
        if ($keywordResult !== null) {
            return ['category_id' => $keywordResult, 'method' => 'keyword'];
        }

        return ['category_id' => null, 'method' => 'none'];
    }

    /**
     * Suggest a commission_id for a given category.
     */
    public function suggestCommission(int $categoryId): ?int
    {
        self::ensureTable($this->db);
        $stmt = $this->db->prepare('SELECT commission_id FROM letter_categories WHERE id = ?');
        $stmt->execute([$categoryId]);
        $commissionId = $stmt->fetchColumn();
        return $commissionId ? (int)$commissionId : null;
    }

    private function classifyViaLlm(string $text): ?int
    {
        $apiKey = envValue('KAZLLM_API_KEY', '');
        $apiUrl = envValue('KAZLLM_API_URL', '');
        if (!$apiKey || !$apiUrl) {
            return null;
        }

        self::ensureTable($this->db);
        $categories = $this->db->query('SELECT id, name FROM letter_categories ORDER BY sort_order, id')->fetchAll();
        if (empty($categories)) {
            return null;
        }

        $categoryList = array_map(fn($c) => "{$c['id']}: {$c['name']}", $categories);
        $prompt = "Классифицируй письмо по одной из категорий. Верни ТОЛЬКО ID категории (число).\n\n"
            . "Категории:\n" . implode("\n", $categoryList) . "\n\n"
            . "Текст письма:\n" . mb_substr($text, 0, 1000);

        $payload = json_encode([
            'model' => envValue('KAZLLM_MODEL', 'kaz-llm'),
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'max_tokens' => 10,
            'temperature' => 0,
        ]);

        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            error_log('LetterClassifier: KazLLM API returned HTTP ' . $httpCode);
            return null;
        }

        $decoded = json_decode($response, true);
        $content = trim($decoded['choices'][0]['message']['content'] ?? '');
        $categoryId = (int)preg_replace('/\D/', '', $content);

        if ($categoryId > 0) {
            $stmt = $this->db->prepare('SELECT id FROM letter_categories WHERE id = ?');
            $stmt->execute([$categoryId]);
            if ($stmt->fetchColumn()) {
                return $categoryId;
            }
        }

        return null;
    }

    private function classifyViaKeywords(string $text): ?int
    {
        self::ensureTable($this->db);
        $categories = $this->db->query('SELECT id, name, keywords FROM letter_categories ORDER BY sort_order, id')->fetchAll();

        $lowerText = mb_strtolower($text);

        foreach ($categories as $cat) {
            $keywords = $this->parseKeywords($cat['keywords'] ?? '');
            foreach ($keywords as $kw) {
                if (mb_strpos($lowerText, mb_strtolower($kw)) !== false) {
                    return (int)$cat['id'];
                }
            }
        }

        $bestScore = 0;
        $bestId = null;
        foreach (self::KEYWORD_FALLBACK as $group) {
            $score = 0;
            foreach ($group['keywords'] as $kw) {
                if (mb_strpos($lowerText, mb_strtolower($kw)) !== false) {
                    $score++;
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $matchedCat = null;
                foreach ($categories as $cat) {
                    if (mb_stripos($cat['name'], $group['name']) !== false || mb_stripos($group['name'], $cat['name']) !== false) {
                        $matchedCat = (int)$cat['id'];
                        break;
                    }
                }
                $bestId = $matchedCat;
            }
        }

        return $bestId;
    }

    private function parseKeywords(string $raw): array
    {
        if (!$raw) return [];
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) return $decoded;
        return array_filter(array_map('trim', explode(',', $raw)));
    }
}
