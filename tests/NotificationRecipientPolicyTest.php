<?php

namespace Tests;

use App\Repositories\MemberRepository;
use App\Services\NotificationRecipientPolicy;
use PHPUnit\Framework\TestCase;

class NotificationRecipientPolicyTest extends TestCase
{
    public function testPhotoApiUrlFormat(): void
    {
        $this->assertSame('/api/member_photo.php?member_id=42', MemberRepository::photoApiUrl(42));
    }

    public function testDomainAllowedFromEnv(): void
    {
        putenv('NOTIFY_ALLOWED_DOMAINS=gov.kz, example.org');
        $this->assertTrue(NotificationRecipientPolicy::isDomainAllowed('user@gov.kz'));
        $this->assertTrue(NotificationRecipientPolicy::isDomainAllowed('user@example.org'));
        $this->assertFalse(NotificationRecipientPolicy::isDomainAllowed('user@gmail.com'));
        putenv('NOTIFY_ALLOWED_DOMAINS');
    }

    public function testDomainNotAllowedWhenEnvEmpty(): void
    {
        putenv('NOTIFY_ALLOWED_DOMAINS');
        $this->assertFalse(NotificationRecipientPolicy::isDomainAllowed('user@gov.kz'));
    }

    public function testIsEmailInRegionFindsMember(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchColumn')->willReturnOnConsecutiveCalls(false, '1');

        $db = $this->createMock(\PDO::class);
        $db->method('prepare')->willReturn($stmt);

        $this->assertTrue(NotificationRecipientPolicy::isEmailInRegion($db, 'member@org.kz', 2));
    }
}
