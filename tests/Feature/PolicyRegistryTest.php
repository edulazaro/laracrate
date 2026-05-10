<?php

namespace EduLazaro\Laracrate\Tests\Feature;

use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Support\PolicyRegistry;
use EduLazaro\Laracrate\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PolicyRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_creator_human_can_view_edit_delete(): void
    {
        $user = $this->makeUser(7);
        $file = new File([
            'creator_type'  => 'user',
            'creator_id'    => 7,
            'fileable_type' => 'test_owner',
            'access'        => 'signed',
        ]);

        $registry = app(PolicyRegistry::class);

        $this->assertTrue($registry->canView($file, $user));
        $this->assertTrue($registry->canEdit($file, $user));
        $this->assertTrue($registry->canDelete($file, $user));
    }

    public function test_public_files_are_viewable_by_anyone(): void
    {
        $file = new File(['fileable_type' => 'test_owner', 'access' => 'public']);
        $registry = app(PolicyRegistry::class);

        $this->assertTrue($registry->canView($file, null));
    }

    public function test_callback_decides_view(): void
    {
        $registry = app(PolicyRegistry::class);
        $registry->viewable('test_owner', fn ($file, $user) => $user?->id === 99);

        $file = new File(['fileable_type' => 'test_owner', 'access' => 'signed']);

        $this->assertTrue($registry->canView($file, $this->makeUser(99)));
        $this->assertFalse($registry->canView($file, $this->makeUser(7)));
        $this->assertFalse($registry->canView($file, null));
    }

    public function test_default_deny_when_no_policy_registered(): void
    {
        $file = new File(['fileable_type' => 'unknown_type', 'access' => 'signed']);
        $registry = app(PolicyRegistry::class);

        $this->assertFalse($registry->canView($file, $this->makeUser(1)));
        $this->assertFalse($registry->canEdit($file, $this->makeUser(1)));
    }

    protected function makeUser(int $id): Model
    {
        $user = new class extends Model {
            public function getKey() { return $this->getAttribute('id'); }
        };

        $user->setRawAttributes(['id' => $id]);
        $user->exists = true;

        return $user;
    }
}
