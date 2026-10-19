<?php

namespace EduLazaro\Laracrate\Tests\Feature;

use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Enums\ProcessingStatus;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Tests\Support\HasFilesTestModel;
use EduLazaro\Laracrate\Tests\Support\TestUser;
use EduLazaro\Laracrate\Tests\TestCase;
use EduLazaro\Laracrate\Uploads\UploadGrant;
use EduLazaro\Laracrate\Uploads\UploadTargets;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The upload targets an app declares for the React and Svelte components: a fresh
 * grant only when the target's callback gives one, the collection's rules, the current
 * file, and removing it with a replacing grant.
 */
class UploadTargetsTest extends TestCase
{
    use RefreshDatabase;

    private const PDF = "%PDF-1.4\n%%EOF\n";

    private TestUser $user;
    private HasFilesTestModel $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Relation::morphMap(['test_user' => TestUser::class]);
        Storage::fake('documents');
        Storage::fake('media');

        $this->user = TestUser::create(['name' => 'uploader']);
        $this->owner = HasFilesTestModel::create(['name' => 'mine']);
        $this->actingAs($this->user);

        UploadTargets::define('owner.gallery', fn (TestUser $user, HasFilesTestModel $owner) =>
            $owner->name === 'mine' ? UploadGrant::for($owner, 'gallery') : null);

        UploadTargets::define('owner.avatar', fn (TestUser $user, HasFilesTestModel $owner) =>
            UploadGrant::for($owner, 'avatar', replace: true));
    }

    /** What the component shows when it mounts. */
    private function target(string $name, array $params = [])
    {
        return $this->getJson("/laracrate/uploads/targets/{$name}?" . http_build_query(['params' => $params]));
    }

    /** The grant, once the user picks files. */
    private function targetGrant(string $name, array $params = [])
    {
        return $this->postJson("/laracrate/uploads/targets/{$name}", ['params' => $params]);
    }

    /** What the token says, read the way the endpoints read it. */
    private function payload(string $token): array
    {
        return json_decode(Crypt::decryptString($token), true);
    }

    private function avatar(): File
    {
        return $this->owner->files('avatar')->create([
            'disk'          => 'media',
            'path'          => 'avatars/' . Str::random(8) . '.png',
            'name'          => Str::random(8),
            'original_name' => 'me.png',
            'extension'     => 'png',
            'mime_type'     => 'image/png',
            'size'          => 2048,
            'context'       => 'media',
            'collection'    => 'avatar',
            'type'          => FileType::IMAGE,
            'access'        => 'public',
            'processing_status' => ProcessingStatus::COMPLETED,
        ]);
    }

    private function grant(string $collection, ...$options): string
    {
        $this->app->instance('request', Request::create('/'));

        return UploadGrant::for($this->owner, $collection, ...$options)->token();
    }

    public function test_a_target_answers_what_to_show_with_no_grant(): void
    {
        $response = $this->target('owner.gallery', ['owner' => $this->owner->id])->assertOk();

        $this->assertNull($response->json('grant'));
        $this->assertSame(UploadGrant::for($this->owner, 'gallery')->scope(), $response->json('scope'));
        $this->assertFalse($response->json('replace'));
        $this->assertNull($response->json('file'));

        $this->assertSame('image/jpeg,image/png,image/webp', $response->json('rules.accept'));
        $this->assertSame(5120, $response->json('rules.max_size_kb'));
        $this->assertSame('image', $response->json('rules.kind'));
        $this->assertIsArray($response->json('rules.extensions'));
    }

    public function test_a_target_gives_a_grant_for_its_target_when_asked(): void
    {
        $response = $this->targetGrant('owner.gallery', ['owner' => $this->owner->id])->assertOk();

        $grant = $this->payload($response->json('grant'));
        $this->assertSame(['test_owner', (string) $this->owner->id], $grant['f']);
        $this->assertSame('gallery', $grant['c']);
        $this->assertSame(UploadGrant::for($this->owner, 'gallery')->scope(), $response->json('scope'));
        $this->assertNull($response->json('rules'));
    }

    public function test_the_target_decides_who_may_upload_there(): void
    {
        $other = HasFilesTestModel::create(['name' => 'someone else']);

        $this->target('owner.gallery', ['owner' => $other->id])
            ->assertForbidden()
            ->assertJson(['message' => 'You cannot upload files here.']);
        $this->targetGrant('owner.gallery', ['owner' => $other->id])->assertForbidden();
    }

    public function test_an_unknown_target_is_not_found(): void
    {
        $this->target('nowhere')->assertNotFound();
        $this->targetGrant('nowhere')->assertNotFound();
    }

    public function test_a_param_that_does_not_resolve_is_refused(): void
    {
        $this->target('owner.gallery', ['owner' => 999999])->assertForbidden();
        $this->target('owner.gallery')->assertForbidden();
        $this->target('owner.gallery', ['owner' => ['id' => $this->owner->id]])->assertForbidden();
    }

    public function test_scalar_params_are_cast_to_the_callback_types(): void
    {
        $seen = null;
        UploadTargets::define('typed', function (TestUser $user, int $count, bool $draft, string $label = 'none') use (&$seen) {
            $seen = [$count, $draft, $label];

            return UploadGrant::for($this->owner, 'gallery');
        });

        $this->target('typed', ['count' => '3', 'draft' => 'true'])->assertOk();
        $this->assertSame([3, true, 'none'], $seen);

        $this->target('typed', ['count' => 'three', 'draft' => 'true'])->assertForbidden();
    }

    public function test_a_guest_is_refused_unless_the_target_takes_one(): void
    {
        auth()->logout();

        $this->target('owner.gallery', ['owner' => $this->owner->id])->assertForbidden();

        UploadTargets::define('guest.gallery', fn (?TestUser $user) => UploadGrant::for($this->owner, 'gallery'));
        $this->target('guest.gallery')->assertOk();
    }

    public function test_a_user_of_another_class_is_refused(): void
    {
        UploadTargets::define('owners.only', fn (HasFilesTestModel $user) => UploadGrant::for($this->owner, 'gallery'));

        $this->target('owners.only')->assertForbidden();
    }

    public function test_a_replacing_target_answers_its_current_file(): void
    {
        $this->target('owner.avatar', ['owner' => $this->owner->id])->assertOk()->assertJson(['replace' => true, 'file' => null]);

        $avatar = $this->avatar();
        $file = $this->target('owner.avatar', ['owner' => $this->owner->id])->assertOk()->json('file');

        $this->assertSame($avatar->id, $file['id']);
        $this->assertSame('me.png', $file['name']);
        $this->assertSame(2048, $file['size']);
        $this->assertSame('image', $file['type']);
        $this->assertSame('completed', $file['processing_status']);
        $this->assertSame($avatar->url(), $file['url']);
        $this->assertSame($avatar->preview_link, $file['preview']);
    }

    public function test_the_targets_file_is_read_when_a_target_is_first_asked_for(): void
    {
        $path = storage_path('framework/testing/uploads-' . Str::random(6) . '.php');
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, '<?php use EduLazaro\Laracrate\Uploads\UploadGrant; use EduLazaro\Laracrate\Uploads\UploadTargets; '
            . 'use EduLazaro\Laracrate\Tests\Support\HasFilesTestModel; '
            . 'UploadTargets::define("from.file", fn ($user) => UploadGrant::for(HasFilesTestModel::first(), "gallery"));');
        config()->set('laracrate.uploads.targets', $path);
        $this->app->forgetInstance(UploadTargets::class);

        try {
            $this->target('from.file')->assertOk();
        } finally {
            unlink($path);
        }
    }

    public function test_a_replacing_grant_removes_the_current_file(): void
    {
        $this->avatar();

        $this->postJson('/laracrate/uploads/remove', ['grant' => $this->grant('avatar', replace: true)])
            ->assertOk()
            ->assertJson(['deleted' => true]);

        $this->assertNull($this->owner->fresh()->file('avatar'));
    }

    public function test_a_grant_that_adds_files_cannot_remove_any(): void
    {
        $avatar = $this->avatar();

        $this->postJson('/laracrate/uploads/remove', ['grant' => $this->grant('avatar')])->assertForbidden();
        $this->postJson('/laracrate/uploads/remove', ['grant' => 'forged'])->assertForbidden();

        $this->assertTrue($this->owner->fresh()->file('avatar')->is($avatar));
    }

    public function test_an_upload_answers_the_file_as_the_components_show_it(): void
    {
        $grant = $this->grant('documents');
        $signed = $this->postJson('/laracrate/uploads/sign', [
            'grant' => $grant, 'file_name' => 'deal.pdf', 'mime' => 'application/pdf', 'size' => strlen(self::PDF),
        ])->assertOk()->json();
        Storage::disk('documents')->put($signed['key'], self::PDF);

        $response = $this->postJson('/laracrate/uploads/confirm', [
            'grant' => $grant, 'receipt' => $signed['receipt'], 'original_name' => 'deal.pdf', 'mime' => 'application/pdf',
        ])->assertOk();

        $file = File::find($response->json('id'));
        $this->assertSame($file->slug, $response->json('slug'));
        $this->assertSame('deal.pdf', $response->json('name'));
        $this->assertSame('documents', $response->json('collection'));
        $this->assertSame(strlen(self::PDF), $response->json('size'));
        $this->assertSame('document', $response->json('type'));
        $this->assertNotEmpty($response->json('url'));
        $this->assertNotEmpty($response->json('preview'));
        $this->assertContains($response->json('processing_status'), ['pending', 'processing', 'completed', 'failed']);
    }
}
