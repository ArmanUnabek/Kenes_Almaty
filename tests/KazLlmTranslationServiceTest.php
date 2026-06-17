<?php

use PHPUnit\Framework\TestCase;
use App\Services\KazLlmTranslationService;

class KazLlmTranslationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('KAZLLM_ENABLED=false');
    }

    public function testDisabledByDefault(): void
    {
        $this->assertFalse(KazLlmTranslationService::isEnabled());
    }

    public function testPingWhenDisabled(): void
    {
        $ping = KazLlmTranslationService::ping();
        $this->assertFalse($ping['ok']);
    }

    public function testTranslateThrowsWhenDisabled(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE translation_cache (
            id INTEGER PRIMARY KEY,
            source_hash VARCHAR(64) UNIQUE,
            source_lang VARCHAR(5),
            target_lang VARCHAR(5),
            source_text TEXT,
            translated_text TEXT
        )');

        $this->expectException(RuntimeException::class);
        KazLlmTranslationService::translate($pdo, 'Тест', 'ru', 'kk');
    }

    public function testEmptyTextRejected(): void
    {
        putenv('KAZLLM_ENABLED=true');
        $pdo = new PDO('sqlite::memory:');
        $this->expectException(InvalidArgumentException::class);
        KazLlmTranslationService::translate($pdo, '   ', 'ru', 'kk');
    }
}
