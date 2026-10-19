<?php

namespace EduLazaro\Laracrate\Tests\Feature;

use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Models\FileSlot;
use EduLazaro\Laracrate\Tests\Support\HasFilesTestModel;
use EduLazaro\Laracrate\Tests\Support\TestUser;
use EduLazaro\Laracrate\Tests\TestCase;
use EduLazaro\Laracrate\Uploads\UploadGrant;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The grant based upload endpoints (sign, confirm, receive): the target only ever
 * comes from the grant, and every rule runs on the server.
 */
class UploadEndpointsTest extends TestCase
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
        $this->owner = HasFilesTestModel::create(['name' => 'owner']);
        $this->actingAs($this->user);
    }

    private function grant(string $collection = 'documents', ...$options): string
    {
        // Issued outside the test's earlier requests: each of those got a new session,
        // since a test request keeps no cookie the way a browser does.
        $this->app->instance('request', Request::create('/'));

        return UploadGrant::for($this->owner, $collection, ...$options)->token();
    }

    /** sign, then "the browser" puts the bytes where it was told. */
    private function signAndPut(string $grant, string $name = 'deal.pdf', string $mime = 'application/pdf', string $bytes = self::PDF, string $disk = 'documents', ?int $declared = null): array
    {
        $signed = $this->postJson('/laracrate/uploads/sign', [
            'grant' => $grant, 'file_name' => $name, 'mime' => $mime, 'size' => $declared ?? strlen($bytes),
        ])->assertOk()->json();

        Storage::disk($disk)->put($signed['key'], $bytes);

        return $signed;
    }

    private function confirm(string $grant, array $signed, string $name = 'deal.pdf', string $mime = 'application/pdf')
    {
        return $this->postJson('/laracrate/uploads/confirm', [
            'grant' => $grant, 'receipt' => $signed['receipt'], 'original_name' => $name, 'mime' => $mime,
        ]);
    }

    public function test_sign_hands_out_a_temp_key_and_a_receipt_for_a_direct_upload(): void
    {
        $signed = $this->postJson('/laracrate/uploads/sign', [
            'grant' => $this->grant(), 'file_name' => 'deal.pdf', 'mime' => 'application/pdf', 'size' => 15,
        ])->assertOk()->json();

        $this->assertSame('direct', $signed['transport']);
        $this->assertStringStartsWith('temp/', $signed['key']);
        $this->assertNotEmpty($signed['receipt']);
        $this->assertNotEmpty($signed['url']);
    }

    public function test_confirm_creates_the_file_in_the_grant_target(): void
    {
        $grant = $this->grant();
        $signed = $this->signAndPut($grant);

        $response = $this->confirm($grant, $signed)->assertOk();

        $file = File::find($response->json('id'));
        $this->assertTrue($file->fileable->is($this->owner));
        $this->assertSame('documents', $file->collection);
        $this->assertSame(strlen(self::PDF), $file->size);
        $this->assertSame('test_user', $file->creator_type);
        $this->assertStringStartsWith('test_owner/' . $this->owner->id . '/documents/', $file->key);
        Storage::disk('documents')->assertMissing($signed['key']);
        Storage::disk('documents')->assertExists($file->key);
    }

    public function test_confirm_checks_the_size_the_bucket_holds_not_the_declared_one(): void
    {
        $grant = $this->grant('gallery');
        // gallery takes images up to 5 MB: declared tiny, stored 6 MB.
        $signed = $this->signAndPut($grant, 'big.png', 'image/png', str_repeat('x', 6 * 1024 * 1024), 'media', declared: 100);

        $this->confirm($grant, $signed, 'big.png', 'image/png')->assertStatus(422);

        $this->assertSame(0, File::count());
        Storage::disk('media')->assertMissing($signed['key']);
    }

    public function test_a_receipt_only_confirms_the_grant_that_asked_for_it(): void
    {
        $signed = $this->signAndPut($this->grant());

        $this->confirm($this->grant(), $signed)->assertStatus(403);
        $this->assertSame(0, File::count());
    }

    public function test_confirm_without_the_object_in_the_bucket_is_refused(): void
    {
        $grant = $this->grant();
        $signed = $this->postJson('/laracrate/uploads/sign', [
            'grant' => $grant, 'file_name' => 'deal.pdf', 'mime' => 'application/pdf', 'size' => 15,
        ])->json();

        $this->confirm($grant, $signed)->assertStatus(422);
    }

    public function test_a_grant_only_works_in_the_browser_it_was_issued_in(): void
    {
        // The page that issues it runs in the browser session `aaa…`.
        $session = $this->app['session']->driver();
        $session->setId(str_repeat('a', 40));
        $this->app['request']->setLaravelSession($session);
        $grant = UploadGrant::for($this->owner, 'documents')->token();

        $body = ['grant' => $grant, 'file_name' => 'deal.pdf', 'mime' => 'application/pdf', 'size' => 15];

        $this->withCredentials()->withCookie(config('session.cookie'), str_repeat('a', 40))
            ->postJson('/laracrate/uploads/sign', $body)->assertOk();
        $this->withCredentials()->withCookie(config('session.cookie'), str_repeat('b', 40))
            ->postJson('/laracrate/uploads/sign', $body)->assertStatus(403);
    }

    public function test_a_grant_signs_no_more_files_than_its_limit(): void
    {
        $grant = $this->grant(maxFiles: 2);
        $sign = fn (string $upload) => $this->postJson('/laracrate/uploads/sign', [
            'grant' => $grant, 'upload' => $upload, 'file_name' => 'deal.pdf', 'mime' => 'application/pdf', 'size' => 15,
        ]);

        $sign('1')->assertOk();
        $sign('2')->assertOk();
        $sign('3')->assertStatus(422);

        // A retry of a file already counted is still that file.
        $sign('1')->assertOk();
    }

    public function test_a_file_without_its_upload_id_always_counts_as_a_new_one(): void
    {
        $grant = $this->grant(maxFiles: 1);
        $body = ['grant' => $grant, 'file_name' => 'deal.pdf', 'mime' => 'application/pdf', 'size' => 15];

        $this->postJson('/laracrate/uploads/sign', $body)->assertOk();
        $this->postJson('/laracrate/uploads/sign', $body)->assertStatus(422);
    }

    public function test_a_grant_cannot_be_used_by_another_user(): void
    {
        $grant = $this->grant();
        $this->actingAs(TestUser::create(['name' => 'someone else']));

        $this->postJson('/laracrate/uploads/sign', [
            'grant' => $grant, 'file_name' => 'deal.pdf', 'mime' => 'application/pdf', 'size' => 15,
        ])->assertStatus(403);
    }

    public function test_sign_refuses_what_the_collection_does_not_take_before_any_byte_moves(): void
    {
        $this->postJson('/laracrate/uploads/sign', [
            'grant' => $this->grant(), 'file_name' => 'photo.png', 'mime' => 'image/png', 'size' => 10,
        ])->assertStatus(422)->assertJsonStructure(['message']);

        $this->postJson('/laracrate/uploads/sign', [
            'grant' => $this->grant('gallery'), 'file_name' => 'huge.png', 'mime' => 'image/png', 'size' => 50 * 1024 * 1024,
        ])->assertStatus(422);
    }

    public function test_runnable_content_is_refused_unless_the_collection_asks_for_it(): void
    {
        config()->set('laracrate.collections.notes', ['disk' => 'documents', 'types' => ['document' => []]]);
        config()->set('laracrate.collections.logos', ['disk' => 'media', 'types' => ['image' => ['accepted_mime_types' => ['image/svg+xml']]]]);

        $this->postJson('/laracrate/uploads/sign', [
            'grant' => $this->grant('notes'), 'file_name' => 'page.html', 'mime' => 'text/html', 'size' => 10,
        ])->assertStatus(422);

        $this->postJson('/laracrate/uploads/sign', [
            'grant' => $this->grant('logos'), 'file_name' => 'logo.svg', 'mime' => 'image/svg+xml', 'size' => 10,
        ])->assertOk();
    }

    public function test_an_encrypted_collection_is_received_through_the_server_and_encrypted(): void
    {
        $grant = $this->grant('identity', replace: true);

        $this->postJson('/laracrate/uploads/sign', [
            'grant' => $grant, 'file_name' => 'dni.png', 'mime' => 'image/png', 'size' => 100,
        ])->assertOk()->assertJson(['transport' => 'server']);

        $first = $this->post('/laracrate/uploads/receive', ['grant' => $grant, 'file' => UploadedFile::fake()->image('dni.png')], ['Accept' => 'application/json'])->assertOk();
        $second = $this->post('/laracrate/uploads/receive', ['grant' => $grant, 'file' => UploadedFile::fake()->image('dni-2.png')], ['Accept' => 'application/json'])->assertOk();

        $files = File::where('collection', 'identity')->get();
        $this->assertCount(1, $files, 'a replace grant keeps a single file');
        $this->assertSame($second->json('id'), $files->first()->id);
        $this->assertTrue((bool) $files->first()->is_encrypted);
        $this->assertStringStartsNotWith("\x89PNG", Storage::disk('documents')->get($files->first()->key));
        $this->assertNull(File::find($first->json('id')));
    }

    public function test_receive_is_only_for_server_grants(): void
    {
        $this->post('/laracrate/uploads/receive', ['grant' => $this->grant('gallery'), 'file' => UploadedFile::fake()->image('a.png')], ['Accept' => 'application/json'])
            ->assertStatus(403);
    }

    public function test_the_file_lands_in_the_grant_folder(): void
    {
        $folder = $this->owner->addFolder('Contracts');
        $grant = $this->grant(folderId: $folder->id);

        $id = $this->confirm($grant, $this->signAndPut($grant))->assertOk()->json('id');

        $this->assertSame($folder->id, File::find($id)->folder_id);
    }

    public function test_the_grant_slots_are_attached_and_their_limits_apply(): void
    {
        $slot = FileSlot::create(['name' => 'Contract', 'max_files_total' => 1]);
        $grant = $this->grant(slots: [$slot->id]);

        $id = $this->confirm($grant, $this->signAndPut($grant))->assertOk()->json('id');
        $this->assertTrue(File::find($id)->slots->contains($slot));

        $this->postJson('/laracrate/uploads/sign', [
            'grant' => $grant, 'file_name' => 'again.pdf', 'mime' => 'application/pdf', 'size' => 15,
        ])->assertStatus(422);
    }
}
