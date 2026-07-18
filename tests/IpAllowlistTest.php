<?php

namespace Tests;

use App\Services\IpAllowlist;
use PHPUnit\Framework\TestCase;

/**
 * Unit-tests App\Services\IpAllowlist against in-memory SQLite. This gate decides
 * whether an admin session is accepted from a given IP, so its edge cases (no
 * allowlist = allow-all, empty/invalid JSON = allow-all, strict membership) are
 * security-relevant and must not regress.
 */
class IpAllowlistTest extends TestCase
{
    private \PDO $db;
    private IpAllowlist $svc;

    protected function setUp(): void
    {
        $this->db = new \PDO('sqlite::memory:');
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, allowed_ips TEXT)");
        $this->db->exec("INSERT INTO users (id, allowed_ips) VALUES (1, NULL)");
        $this->svc = new IpAllowlist($this->db);
    }

    public function testNoAllowlistAllowsAnyIp(): void
    {
        $this->assertTrue($this->svc->isAllowed(1, '203.0.113.7'));
        $this->assertNull($this->svc->getAllowedIps(1));
    }

    public function testUnknownUserIsUnrestricted(): void
    {
        // No row → getAllowedIps returns null → allow-all (fail open by design).
        $this->assertTrue($this->svc->isAllowed(999, '203.0.113.7'));
    }

    public function testSetAndEnforceAllowlist(): void
    {
        $this->svc->setAllowedIps(1, ['10.0.0.1', '10.0.0.2']);

        $this->assertSame(['10.0.0.1', '10.0.0.2'], $this->svc->getAllowedIps(1));
        $this->assertTrue($this->svc->isAllowed(1, '10.0.0.1'));
        $this->assertFalse($this->svc->isAllowed(1, '10.0.0.9'));
        // Strict comparison: a partial/substring IP must not match.
        $this->assertFalse($this->svc->isAllowed(1, '10.0.0.10'));
    }

    public function testNeedsConfirmationIsInverseOfAllowed(): void
    {
        $this->svc->setAllowedIps(1, ['10.0.0.1']);
        $this->assertFalse($this->svc->needsConfirmation(1, '10.0.0.1'));
        $this->assertTrue($this->svc->needsConfirmation(1, '10.0.0.2'));
    }

    public function testClearingAllowlistRestoresAllowAll(): void
    {
        $this->svc->setAllowedIps(1, ['10.0.0.1']);
        $this->assertNotNull($this->svc->getAllowedIps(1));

        $this->svc->setAllowedIps(1, []);      // empty array clears
        $this->assertNull($this->svc->getAllowedIps(1));
        $this->assertTrue($this->svc->isAllowed(1, '198.51.100.4'));

        $this->svc->setAllowedIps(1, ['10.0.0.1']);
        $this->svc->setAllowedIps(1, null);    // null clears
        $this->assertNull($this->svc->getAllowedIps(1));
    }

    public function testInvalidJsonFailsOpen(): void
    {
        $this->db->exec("UPDATE users SET allowed_ips = 'not-json' WHERE id = 1");
        $this->assertNull($this->svc->getAllowedIps(1));
        $this->assertTrue($this->svc->isAllowed(1, '203.0.113.7'));
    }

    public function testNumericIpsAreCoercedToStrings(): void
    {
        // A JSON array of numbers must still compare as strings against the IP.
        $this->db->exec("UPDATE users SET allowed_ips = '[19216801]' WHERE id = 1");
        $ips = $this->svc->getAllowedIps(1);
        $this->assertSame(['19216801'], $ips);
    }
}
