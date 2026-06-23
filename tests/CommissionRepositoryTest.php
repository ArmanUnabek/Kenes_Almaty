<?php

namespace Tests;

use App\Repositories\CommissionRepository;
use Tests\Support\RepositoryTestCase;

class CommissionRepositoryTest extends RepositoryTestCase
{
    private function repo(): CommissionRepository
    {
        return new CommissionRepository($this->db);
    }

    public function testCreateAndGetById(): void
    {
        $repo = $this->repo();
        $id = $repo->create(['region_id' => 1, 'name' => 'Комиссия A', 'color' => '#fff', 'sort_order' => 2]);
        $this->assertGreaterThan(0, $id);

        $row = $repo->getById($id);
        $this->assertSame('Комиссия A', $row['name']);
        $this->assertSame(1, (int)$row['region_id']);
    }

    public function testCreateUsesDefaultColor(): void
    {
        $id = $this->repo()->create(['region_id' => 1, 'name' => 'Без цвета']);
        $this->assertSame('#6c757d', $this->repo()->getById($id)['color']);
    }

    public function testGetByIdRegionScopingDeniesOtherRegion(): void
    {
        $repo = $this->repo();
        $id = $repo->create(['region_id' => 1, 'name' => 'Регион 1']);

        $this->assertNotNull($repo->getById($id, 1), 'own region must see the row');
        $this->assertNull($repo->getById($id, 2), 'other region must NOT see the row');
    }

    public function testGetAllFiltersByRegion(): void
    {
        $repo = $this->repo();
        $repo->create(['region_id' => 1, 'name' => 'R1-a']);
        $repo->create(['region_id' => 1, 'name' => 'R1-b']);
        $repo->create(['region_id' => 2, 'name' => 'R2-a']);

        $this->assertSame(3, $repo->getAll(null)['total'], 'no region filter sees all');
        $region1 = $repo->getAll(1);
        $this->assertSame(2, $region1['total']);
        $this->assertCount(2, $region1['items']);
        $this->assertSame(1, $repo->getAll(2)['total']);
    }

    public function testGetAllOrdersBySortThenName(): void
    {
        $repo = $this->repo();
        $repo->create(['region_id' => 1, 'name' => 'Бета', 'sort_order' => 1]);
        $repo->create(['region_id' => 1, 'name' => 'Альфа', 'sort_order' => 1]);
        $repo->create(['region_id' => 1, 'name' => 'Раньше', 'sort_order' => 0]);

        $names = array_column($repo->getAll(1)['items'], 'name');
        $this->assertSame(['Раньше', 'Альфа', 'Бета'], $names);
    }

    public function testGetAllPagination(): void
    {
        $repo = $this->repo();
        for ($i = 1; $i <= 5; $i++) {
            $repo->create(['region_id' => 1, 'name' => 'C' . $i, 'sort_order' => $i]);
        }
        $page = $repo->getAll(1, 2, 2); // page 2, limit 2
        $this->assertSame(5, $page['total']);
        $this->assertCount(2, $page['items']);
        $this->assertSame('C3', $page['items'][0]['name']);
    }

    public function testUpdateRespectsRegionScope(): void
    {
        $repo = $this->repo();
        $id = $repo->create(['region_id' => 1, 'name' => 'Старое']);

        // Wrong region: execute() returns true but nothing changes.
        $repo->update($id, ['name' => 'Взлом', 'sort_order' => 9], 2);
        $this->assertSame('Старое', $repo->getById($id)['name'], 'cross-region update must not modify the row');

        // Correct region: changes apply.
        $repo->update($id, ['name' => 'Новое', 'sort_order' => 9], 1);
        $this->assertSame('Новое', $repo->getById($id)['name']);
    }

    public function testCountMembers(): void
    {
        $repo = $this->repo();
        $cid = $repo->create(['region_id' => 1, 'name' => 'C']);
        $this->db->prepare('INSERT INTO os_members (region_id, full_name, commission_id, status) VALUES (?,?,?,?)')
            ->execute([1, 'Иванов', $cid, 'active']);
        $this->db->prepare('INSERT INTO os_members (region_id, full_name, commission_id, status) VALUES (?,?,?,?)')
            ->execute([1, 'Петров', $cid, 'active']);

        $this->assertSame(2, $repo->countMembers($cid));
        $this->assertSame(0, $repo->countMembers(999));
    }

    public function testDeleteRespectsRegionScope(): void
    {
        $repo = $this->repo();
        $id = $repo->create(['region_id' => 1, 'name' => 'C']);

        $repo->delete($id, 2); // wrong region
        $this->assertNotNull($repo->getById($id), 'cross-region delete must not remove the row');

        $repo->delete($id, 1); // correct region
        $this->assertNull($repo->getById($id));
    }
}
