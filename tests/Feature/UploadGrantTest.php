<?php

namespace EduLazaro\Laracrate\Tests\Feature;

use EduLazaro\Laracrate\Exceptions\InvalidUploadGrant;
use EduLazaro\Laracrate\Tests\Support\HasFilesTestModel;
use EduLazaro\Laracrate\Tests\Support\TestUser;
use EduLazaro\Laracrate\Tests\TestCase;
use EduLazaro\Laracrate\Uploads\UploadGrant;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;

class UploadGrantTest extends TestCase
{
    use RefreshDatabase;

    private TestUser $user;
    private HasFilesTestModel $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Relation::morphMap(['test_user' => TestUser::class]);
        $this->user = TestUser::create(['name' => 'uploader']);
        $this->owner = HasFilesTestModel::create(['name' => 'owner']);
        $this->actingAs($this->user);
    }

    public function test_the_token_carries_the_whole_target_back(): void
    {
        $folder = $this->owner->addFolder('Contracts');

        $token = UploadGrant::for(
            $this->owner,
            'gallery',
            slots: [3, '4'],
            owner: $this->user,
            folderId: $folder->id,
            contextKey: 'field-7',
            replace: true,
        )->token();

        $grant = UploadGrant::fromToken($token);

        $this->assertSame('test_owner', $grant->fileableType);
        $this->assertSame((string) $this->owner->id, $grant->fileableId);
        $this->assertSame('gallery', $grant->collection);
        $this->assertSame([3, 4], $grant->slots);
        $this->assertSame(['test_user', (string) $this->user->id], $grant->owner);
        $this->assertSame($folder->id, $grant->folderId);
        $this->assertSame('field-7', $grant->contextKey);
        $this->assertTrue($grant->replace);
        $this->assertTrue($grant->fileable()->is($this->owner));
        $this->assertSame('media', $grant->disk());
    }

    public function test_the_browser_cannot_read_or_alter_the_token(): void
    {
        $token = UploadGrant::for($this->owner, 'gallery')->token();

        $this->assertStringNotContainsString('gallery', $token);

        $this->expectException(InvalidUploadGrant::class);
        UploadGrant::fromToken(substr($token, 0, -4) . 'AAAA');
    }

    public function test_a_token_only_works_for_the_user_it_was_issued_to(): void
    {
        $token = UploadGrant::for($this->owner, 'gallery')->token();

        $this->actingAs(TestUser::create(['name' => 'someone else']));

        $this->expectException(InvalidUploadGrant::class);
        UploadGrant::fromToken($token);
    }

    public function test_the_same_id_of_another_user_class_is_another_user(): void
    {
        $token = UploadGrant::for($this->owner, 'gallery')->token();

        // A client #N of another guard is not the user #N the grant was issued to.
        $this->actingAs(new GenericUser(['id' => $this->user->id]));

        $this->expectException(InvalidUploadGrant::class);
        UploadGrant::fromToken($token);
    }

    public function test_a_token_only_works_in_the_browser_session_it_was_issued_in(): void
    {
        $session = $this->app['session']->driver();
        $session->setId(str_repeat('a', 40));
        $this->app['request']->setLaravelSession($session);

        $token = UploadGrant::for($this->owner, 'gallery')->token();
        $this->assertNotNull(UploadGrant::fromToken($token)->session);

        // Same account, another browser: the token was copied out of the page.
        $session->setId(str_repeat('b', 40));

        $this->expectException(InvalidUploadGrant::class);
        UploadGrant::fromToken($token);
    }

    public function test_the_token_carries_its_file_limit(): void
    {
        $this->assertSame(3, UploadGrant::fromToken(UploadGrant::for($this->owner, 'gallery', maxFiles: 3)->token())->maxFiles);
        $this->assertNull(UploadGrant::fromToken(UploadGrant::for($this->owner, 'gallery')->token())->maxFiles);
    }

    public function test_a_token_expires(): void
    {
        $token = UploadGrant::for($this->owner, 'gallery')->token();

        $this->travel(25)->hours();

        $this->expectException(InvalidUploadGrant::class);
        UploadGrant::fromToken($token);
    }

    public function test_an_encrypted_collection_always_goes_through_the_server(): void
    {
        $this->assertSame('server', UploadGrant::for($this->owner, 'identity', transport: 'direct')->transport);
        $this->assertSame('direct', UploadGrant::for($this->owner, 'gallery', transport: 'direct')->transport);
        $this->assertSame('server', UploadGrant::for($this->owner, 'gallery', transport: 'server')->transport);
    }

    public function test_the_collection_upload_setting_wins_over_the_component_default(): void
    {
        config()->set('laracrate.collections.gallery.upload', 'server');

        $this->assertSame('server', UploadGrant::for($this->owner, 'gallery', transport: 'direct')->transport);
    }

    public function test_the_scope_names_the_target_and_nothing_else(): void
    {
        $a = UploadGrant::for($this->owner, 'gallery', folderId: 1);
        $b = UploadGrant::for($this->owner, 'gallery', folderId: 1);
        $c = UploadGrant::for($this->owner, 'gallery', folderId: 2);

        $this->assertNotSame($a->id, $b->id);
        $this->assertSame($a->scope(), $b->scope());
        $this->assertNotSame($a->scope(), $c->scope());
    }
}
