<?php

namespace Tests;

use App\Services\LetterService;
use App\Validator;
use PHPUnit\Framework\TestCase;

class ValidatorTest extends TestCase
{
    public function testEmailValidation(): void
    {
        $validator = new Validator();
        $this->assertTrue($validator->validate(
            ['email' => 'test@example.com'],
            ['email' => 'email']
        ));
    }

    public function testRequiredFieldFailsWhenMissing(): void
    {
        $validator = new Validator();
        $this->assertFalse($validator->validate([], ['title' => 'required|string|min:2']));
        $this->assertNotEmpty($validator->getErrors());
    }

    public function testDateValidation(): void
    {
        $validator = new Validator();
        $this->assertTrue($validator->validate(
            ['date' => '2026-06-17'],
            ['date' => 'date']
        ));
        $this->assertFalse($validator->validate(
            ['date' => '17-06-2026'],
            ['date' => 'date']
        ));
    }
}

class LetterServiceTest extends TestCase
{
    public function testNormalizeOutgoingType(): void
    {
        $this->assertSame('gov', LetterService::normalizeOutgoingType(null));
        $this->assertSame('jt', LetterService::normalizeOutgoingType('JT'));
        $this->assertSame('gov', LetterService::normalizeOutgoingType('invalid'));
    }

    public function testSeqBaseline(): void
    {
        $this->assertSame(1327, LetterService::getSeqBaseline('incoming_letters'));
        $this->assertSame(1399, LetterService::getSeqBaseline('outgoing_letters'));
        $this->assertSame(0, LetterService::getSeqBaseline('other'));
    }
}
