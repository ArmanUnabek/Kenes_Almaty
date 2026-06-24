<?php

namespace Tests;

use App\ApiController;
use PHPUnit\Framework\TestCase;

/**
 * Unit-tests the shared JSON response envelopes of ApiController
 * (json/paginated/error/validationError/success) that every api/*.php endpoint
 * relies on. ApiController::json() echoes + exit()s, so we use an anonymous
 * subclass with a no-op constructor (no DB) that captures the payload instead.
 */
class ApiResponseEnvelopeTest extends TestCase
{
    private function controller(): object
    {
        return new class extends ApiController {
            public array $captured = [];

            // Skip the parent constructor (it opens a DB connection / session).
            public function __construct() {}

            // Capture instead of echo + exit.
            protected function json($data, int $code = 200): void
            {
                $this->captured = ['data' => $data, 'code' => $code];
            }

            // Public wrappers to reach the protected helpers under test.
            public function callPaginated(array $i, int $t, int $p, int $l): void { $this->paginated($i, $t, $p, $l); }
            public function callError(string $m, int $c = 400): void { $this->error($m, $c); }
            public function callValidationError(array $e): void { $this->validationError($e); }
            public function callSuccess($d = null, string $m = 'Success', int $c = 200): void { $this->success($d, $m, $c); }
        };
    }

    public function testPaginatedEnvelopeShapeAndPages(): void
    {
        $c = $this->controller();
        $c->callPaginated([['id' => 1], ['id' => 2]], 25, 2, 10);

        $this->assertSame(200, $c->captured['code']);
        $d = $c->captured['data'];
        $this->assertSame([['id' => 1], ['id' => 2]], $d['items']);
        $this->assertSame(25, $d['pagination']['total']);
        $this->assertSame(2, $d['pagination']['page']);
        $this->assertSame(10, $d['pagination']['limit']);
        $this->assertSame(3, $d['pagination']['pages'], 'ceil(25/10) = 3');
    }

    public function testPaginatedPagesEdgeCases(): void
    {
        $c = $this->controller();
        $c->callPaginated([], 0, 1, 10);
        $this->assertSame(0, $c->captured['data']['pagination']['pages'], 'empty result → 0 pages');

        // limit 0 must not divide-by-zero (guarded by max(1, limit)).
        $c->callPaginated([], 5, 1, 0);
        $this->assertSame(5, $c->captured['data']['pagination']['pages']);
    }

    public function testErrorEnvelope(): void
    {
        $c = $this->controller();
        $c->callError('Не найдено', 404);
        $this->assertSame(404, $c->captured['code']);
        $this->assertSame(['error' => 'Не найдено'], $c->captured['data']);
    }

    public function testErrorDefaultsTo400(): void
    {
        $c = $this->controller();
        $c->callError('Плохой запрос');
        $this->assertSame(400, $c->captured['code']);
    }

    public function testValidationErrorEnvelope(): void
    {
        $c = $this->controller();
        $c->callValidationError(['email' => ['Некорректный email']]);
        $this->assertSame(422, $c->captured['code']);
        $this->assertSame('Ошибка валидации', $c->captured['data']['error']);
        $this->assertSame(['email' => ['Некорректный email']], $c->captured['data']['errors']);
    }

    public function testSuccessEnvelopeWithData(): void
    {
        $c = $this->controller();
        $c->callSuccess(['id' => 7], 'Готово', 201);
        $this->assertSame(201, $c->captured['code']);
        $this->assertTrue($c->captured['data']['success']);
        $this->assertSame('Готово', $c->captured['data']['message']);
        $this->assertSame(['id' => 7], $c->captured['data']['data']);
    }

    public function testSuccessOmitsDataKeyWhenNull(): void
    {
        $c = $this->controller();
        $c->callSuccess();
        $this->assertTrue($c->captured['data']['success']);
        $this->assertArrayNotHasKey('data', $c->captured['data']);
    }
}
