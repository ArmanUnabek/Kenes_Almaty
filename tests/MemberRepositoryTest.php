<?php

namespace Tests;

use App\Repositories\MemberRepository;
use Tests\Support\RepositoryTestCase;

class MemberRepositoryTest extends RepositoryTestCase
{
    private function repo(): MemberRepository
    {
        return new MemberRepository($this->db);
    }

    public function testCreateAndGetById(): void
    {
        $repo = $this->repo();
        $id = $repo->create(['region_id' => 1, 'full_name' => 'Иван Иванов', 'position' => 'Глава']);
        $this->assertGreaterThan(0, $id);

        $row = $repo->getById($id);
        $this->assertSame('Иван Иванов', $row['full_name']);
        $this->assertSame('Глава', $row['position']);
    }

    public function testGetByIdRegionScoping(): void
    {
        $repo = $this->repo();
        $id = $repo->create(['region_id' => 1, 'full_name' => 'Член 1']);

        $this->assertNotNull($repo->getById($id, 1));
        $this->assertNull($repo->getById($id, 2), 'other region must not access the member');
    }

    public function testGetByIdJoinsCommissionName(): void
    {
        $this->seedCommission(7, 1, 'Комиссия по этике');
        $repo = $this->repo();
        $id = $repo->create(['region_id' => 1, 'full_name' => 'Член', 'commission_id' => 7]);

        $this->assertSame('Комиссия по этике', $repo->getById($id)['commission_name']);
    }

    public function testGetAllReturnsOnlyActiveAndScopesByRegion(): void
    {
        $repo = $this->repo();
        $repo->create(['region_id' => 1, 'full_name' => 'Активный', 'status' => 'active']);
        $repo->create(['region_id' => 1, 'full_name' => 'Неактивный', 'status' => 'inactive']);
        $repo->create(['region_id' => 2, 'full_name' => 'Чужой регион', 'status' => 'active']);

        $region1 = $repo->getAll(1, 20, 1);
        $this->assertSame(1, $region1['total'], 'only the active member of region 1');
        $this->assertSame('Активный', $region1['items'][0]['full_name']);
    }

    public function testGetAllFiltersByCommission(): void
    {
        $this->seedCommission(1, 1, 'A');
        $this->seedCommission(2, 1, 'B');
        $repo = $this->repo();
        $repo->create(['region_id' => 1, 'full_name' => 'В комиссии A', 'commission_id' => 1]);
        $repo->create(['region_id' => 1, 'full_name' => 'В комиссии B', 'commission_id' => 2]);

        $result = $repo->getAll(1, 20, 1, 1);
        $this->assertSame(1, $result['total']);
        $this->assertSame('В комиссии A', $result['items'][0]['full_name']);
    }

    public function testGetByCommissionRegionScoped(): void
    {
        $this->seedCommission(3, 1, 'C');
        $repo = $this->repo();
        $repo->create(['region_id' => 1, 'full_name' => 'Свой', 'commission_id' => 3]);
        $repo->create(['region_id' => 2, 'full_name' => 'Чужой', 'commission_id' => 3]);

        $this->assertCount(2, $repo->getByCommission(3));
        $own = $repo->getByCommission(3, 1);
        $this->assertCount(1, $own);
        $this->assertSame('Свой', $own[0]['full_name']);
    }

    public function testUpdateRespectsRegionScope(): void
    {
        $repo = $this->repo();
        $id = $repo->create(['region_id' => 1, 'full_name' => 'Старое имя']);

        $repo->update($id, ['full_name' => 'Взлом'], 2); // wrong region
        $this->assertSame('Старое имя', $repo->getById($id)['full_name']);

        $repo->update($id, ['full_name' => 'Новое имя'], 1); // correct region
        $this->assertSame('Новое имя', $repo->getById($id)['full_name']);
    }

    public function testDeleteIsSoftAndRegionScoped(): void
    {
        $repo = $this->repo();
        $id = $repo->create(['region_id' => 1, 'full_name' => 'Удаляемый']);

        $repo->delete($id, 2); // wrong region — no change
        $this->assertSame('active', $repo->getById($id)['status']);

        $repo->delete($id, 1); // correct region — soft delete
        $this->assertSame('inactive', $repo->getById($id)['status'], 'delete must set status=inactive, not remove the row');
        $this->assertNotNull($repo->getById($id), 'row still exists after soft delete');
    }

    public function testUpdatePhotoPathAndGetPhotoPath(): void
    {
        $repo = $this->repo();
        $id = $repo->create(['region_id' => 1, 'full_name' => 'С фото']);

        $this->assertNull($repo->getPhotoPath($id));
        $this->assertTrue($repo->updatePhotoPath($id, 'uploads/photos/member_1.jpg'));
        $this->assertSame('uploads/photos/member_1.jpg', $repo->getPhotoPath($id));
    }

    public function testPhotoApiUrlHelper(): void
    {
        $this->assertSame('/api/member_photo.php?member_id=5', MemberRepository::photoApiUrl(5));
        $this->assertSame('/api/member_photo.php?member_id=5&v=abc', MemberRepository::photoApiUrl(5, 'abc'));
    }
}
