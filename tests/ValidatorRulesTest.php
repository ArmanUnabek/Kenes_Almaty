<?php

namespace Tests;

use App\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Covers the Validator rules that ValidatorTest did not exercise:
 * integer, max, in, url, regex, phone.
 */
class ValidatorRulesTest extends TestCase
{
    private function passes(array $data, array $rules): bool
    {
        return (new Validator())->validate($data, $rules);
    }

    public function testIntegerRule(): void
    {
        $this->assertTrue($this->passes(['n' => '42'], ['n' => 'integer']));
        $this->assertTrue($this->passes(['n' => 7], ['n' => 'integer']));
        $this->assertFalse($this->passes(['n' => 'abc'], ['n' => 'integer']));
    }

    public function testMaxRuleForStringsAndNumbers(): void
    {
        $this->assertTrue($this->passes(['s' => 'abc'], ['s' => 'max:3']));
        $this->assertFalse($this->passes(['s' => 'abcd'], ['s' => 'max:3']));
        $this->assertTrue($this->passes(['n' => 5], ['n' => 'max:10']));
        $this->assertFalse($this->passes(['n' => 11], ['n' => 'max:10']));
    }

    public function testInRule(): void
    {
        $this->assertTrue($this->passes(['status' => 'active'], ['status' => 'in:active,inactive']));
        $this->assertFalse($this->passes(['status' => 'deleted'], ['status' => 'in:active,inactive']));
    }

    public function testUrlRule(): void
    {
        $this->assertTrue($this->passes(['u' => 'https://example.com/x'], ['u' => 'url']));
        $this->assertFalse($this->passes(['u' => 'not a url'], ['u' => 'url']));
    }

    public function testRegexRule(): void
    {
        $this->assertTrue($this->passes(['code' => 'AB12'], ['code' => 'regex:/^[A-Z0-9]+$/']));
        $this->assertFalse($this->passes(['code' => 'ab-12'], ['code' => 'regex:/^[A-Z0-9]+$/']));
    }

    public function testPhoneRule(): void
    {
        $this->assertTrue($this->passes(['p' => '+7 701 123 45 67'], ['p' => 'phone']));
        $this->assertTrue($this->passes(['p' => '87011234567'], ['p' => 'phone']));
        $this->assertFalse($this->passes(['p' => '12345'], ['p' => 'phone']));
    }

    public function testErrorsAreCollected(): void
    {
        $validator = new Validator();
        $this->assertFalse($validator->validate(['n' => 'x'], ['n' => 'integer']));
        $this->assertArrayHasKey('n', $validator->getErrors());
    }
}
