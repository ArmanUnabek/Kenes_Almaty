<?php

namespace Tests;

use App\Repositories\UserRepository;
use Tests\Support\RepositoryTestCase;

class UserRepositoryTest extends RepositoryTestCase
{
    private function repo(): UserRepository
    {
        return new UserRepository($this->db);
    }

    public function testCreateHashesPasswordAndJoinsRegion(): void
    {
        $this->seedRegion(1, 'Алматы');
        $repo = $this->repo();
        $id = $repo->create([
            'username' => 'admin', 'email' => 'a@example.com', 'password' => 'secret123',
            'full_name' => 'Админ', 'role' => 'admin', 'region_id' => 1, 'is_active' => 1,
        ]);

        $row = $repo->getById($id);
        $this->assertSame('admin', $row['username']);
        $this->assertSame('Алматы', $row['region_name'], 'region name must be joined');
        $this->assertArrayNotHasKey('password', $row);
        // Password is stored hashed, never in plaintext.
        $hash = $this->db->query('SELECT password_hash FROM users WHERE id = ' . (int)$id)->fetchColumn();
        $this->assertNotSame('secret123', $hash);
        $this->assertTrue(password_verify('secret123', $hash));
    }

    public function testGetAllFiltersByRegion(): void
    {
        $repo = $this->repo();
        $repo->create(['username' => 'u1', 'email' => 'u1@e.com', 'password' => 'x', 'full_name' => 'A', 'region_id' => 1, 'is_active' => 1]);
        $repo->create(['username' => 'u2', 'email' => 'u2@e.com', 'password' => 'x', 'full_name' => 'B', 'region_id' => 2, 'is_active' => 1]);

        $this->assertSame(2, $repo->getAll(null)['total']);
        $this->assertSame(1, $repo->getAll(1)['total']);
        $this->assertSame('u1', $repo->getAll(1)['items'][0]['username']);
    }

    public function testGetAllSearchMatchesUsernameEmailAndName(): void
    {
        $repo = $this->repo();
        $repo->create(['username' => 'ivanov', 'email' => 'ivanov@gov.kz', 'password' => 'x', 'full_name' => 'Иван Иванов', 'region_id' => 1, 'is_active' => 1]);
        $repo->create(['username' => 'petrov', 'email' => 'petrov@gov.kz', 'password' => 'x', 'full_name' => 'Пётр Петров', 'region_id' => 1, 'is_active' => 1]);

        $this->assertSame(1, $repo->getAll(null, 1, 50, 'ivanov')['total']);
        $this->assertSame(1, $repo->getAll(null, 1, 50, 'petrov@gov')['total']);
        $this->assertSame(1, $repo->getAll(null, 1, 50, 'Пётр')['total']);
        $this->assertSame(2, $repo->getAll(null, 1, 50, 'gov.kz')['total']);
        $this->assertSame(0, $repo->getAll(null, 1, 50, 'отсутствует')['total']);
    }

    public function testGetAllRoleAndStatusFilters(): void
    {
        $repo = $this->repo();
        $repo->create(['username' => 'a', 'email' => 'a@e.com', 'password' => 'x', 'full_name' => 'A', 'region_id' => 1, 'role' => 'admin', 'is_active' => 1]);
        $repo->create(['username' => 'm', 'email' => 'm@e.com', 'password' => 'x', 'full_name' => 'M', 'region_id' => 1, 'role' => 'moderator', 'is_active' => 0]);

        $this->assertSame(1, $repo->getAll(null, 1, 50, null, 'admin')['total']);
        $this->assertSame(1, $repo->getAll(null, 1, 50, null, null, 'active')['total']);
        $this->assertSame(1, $repo->getAll(null, 1, 50, null, null, 'inactive')['total']);
        $this->assertSame('m', $repo->getAll(null, 1, 50, null, null, 'inactive')['items'][0]['username']);
    }

    public function testUpdateChangesSelectedFields(): void
    {
        $repo = $this->repo();
        $id = $repo->create(['username' => 'u', 'email' => 'old@e.com', 'password' => 'x', 'full_name' => 'Old', 'region_id' => 1, 'is_active' => 1]);

        $this->assertTrue($repo->update($id, ['email' => 'new@e.com', 'full_name' => 'New', 'role' => 'moderator']));
        $row = $repo->getById($id);
        $this->assertSame('new@e.com', $row['email']);
        $this->assertSame('New', $row['full_name']);
        $this->assertSame('moderator', $row['role']);
    }

    public function testUpdateWithNoKnownFieldsReturnsFalse(): void
    {
        $repo = $this->repo();
        $id = $repo->create(['username' => 'u', 'email' => 'e@e.com', 'password' => 'x', 'full_name' => 'N', 'region_id' => 1, 'is_active' => 1]);
        $this->assertFalse($repo->update($id, ['unrelated' => 'value']));
    }

    public function testDeactivate(): void
    {
        $repo = $this->repo();
        $id = $repo->create(['username' => 'u', 'email' => 'e@e.com', 'password' => 'x', 'full_name' => 'N', 'region_id' => 1, 'is_active' => 1]);
        $this->assertTrue($repo->deactivate($id));
        $this->assertSame(0, (int)$repo->getById($id)['is_active']);
    }

    public function testUsernameExists(): void
    {
        $repo = $this->repo();
        $id = $repo->create(['username' => 'taken', 'email' => 'e@e.com', 'password' => 'x', 'full_name' => 'N', 'region_id' => 1, 'is_active' => 1]);

        $this->assertTrue($repo->usernameExists('taken'));
        $this->assertFalse($repo->usernameExists('free'));
        // Excluding the owning id lets a user keep their own username on update.
        $this->assertFalse($repo->usernameExists('taken', $id));
    }
}
