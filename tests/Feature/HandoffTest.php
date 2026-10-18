<?php

namespace EduLazaro\Laracrate\Tests\Feature;

use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Support\PolicyRegistry;
use EduLazaro\Laracrate\Tests\Support\HasFilesTestModel;
use EduLazaro\Laracrate\Tests\Support\TestUser;
use EduLazaro\Laracrate\Tests\TestCase;
use EduLazaro\Laracrate\Uploads\UploadGrant;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/**
 * Handoff (HandoffController): what the page hands an editor of another site, and the
 * page the editor saves the copy into. Who may see the file may open it; only who may
 * add files next to it gets somewhere to save.
 */
class HandoffTest extends TestCase
{
    use RefreshDatabase;

    private TestUser $user;
    private HasFilesTestModel $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Relation::morphMap(['test_user' => TestUser::class]);
        config()->set('laracrate.handoff.editors.auditius', [
            'name'    => 'Auditius',
            'url'     => 'https://auditius.com/?editor&handoff=1',
            'types'   => ['audio'],
            'formats' => ['audio/mpeg'],
        ]);
        config()->set('laracrate.collections.recordings', [
            'disk'   => 'documents',
            'access' => 'signed',
            'types'  => ['audio' => ['accepted_mime_types' => ['audio/mpeg'], 'max_file_size' => 2048]],
        ]);

        $this->user = TestUser::create(['name' => 'lawyer']);
        $this->owner = HasFilesTestModel::create(['name' => 'case']);
        $this->actingAs($this->user);
    }

    private function recording(array $attrs = []): File
    {
        return File::create(array_merge([
            'slug'          => (string) Str::ulid(),
            'disk'          => 'documents',
            'path'          => 'test/' . Str::random(8),
            'name'          => 'hearing.mp3',
            'original_name' => 'Hearing.mp3',
            'mime_type'     => 'audio/mpeg',
            'size'          => 100,
            'collection'    => 'recordings',
            'type'          => FileType::AUDIO,
            'access'        => 'signed',
            'fileable_type' => 'test_owner',
            'fileable_id'   => $this->owner->id,
        ], $attrs));
    }

    /** The current user made the file: Laracrate lets its creator see and edit it (it checks the 'user' alias). */
    private function own(array $attrs = []): File
    {
        return $this->recording(array_merge(['creator_type' => 'user', 'creator_id' => $this->user->id], $attrs));
    }

    public function test_open_hands_over_the_name_a_link_and_where_to_save(): void
    {
        $file = $this->own(['title' => 'Hearing 2 March']);

        $response = $this->getJson(route('laracrate.files.handoff', [$file->slug, 'auditius']))->assertOk();

        $response->assertJson([
            'name' => 'Hearing 2 March',
            'save' => [
                'url'     => route('laracrate.files.handoff.save', [$file->slug, 'auditius']),
                'formats' => ['audio/mpeg'],
            ],
        ]);
        $this->assertStringContainsString("/files/{$file->slug}/download", $response->json('download'));
    }

    public function test_whoever_may_only_see_the_file_gets_no_place_to_save(): void
    {
        $file = $this->recording();
        app(PolicyRegistry::class)->viewable('test_owner', fn () => true);

        $this->getJson(route('laracrate.files.handoff', [$file->slug, 'auditius']))
            ->assertOk()
            ->assertJsonPath('save', null);
    }

    public function test_a_save_url_an_editor_would_refuse_is_not_offered(): void
    {
        // Editors only save to https (or localhost): any other address is left out.
        $file = $this->own();

        $this->getJson('http://inis.test' . route('laracrate.files.handoff', [$file->slug, 'auditius'], absolute: false))
            ->assertOk()
            ->assertJsonPath('save', null);
    }

    public function test_open_needs_a_known_editor_for_that_kind_of_file_and_access(): void
    {
        $this->getJson(route('laracrate.files.handoff', [$this->own()->slug, 'photoshop']))->assertNotFound();

        $image = $this->own(['type' => FileType::IMAGE, 'mime_type' => 'image/png']);
        $this->getJson(route('laracrate.files.handoff', [$image->slug, 'auditius']))->assertStatus(422);

        $this->getJson(route('laracrate.files.handoff', [$this->recording()->slug, 'auditius']))->assertForbidden();
    }

    public function test_the_save_page_uploads_next_to_the_original(): void
    {
        $folder = $this->owner->addFolder('Hearings');
        $file = $this->own(['folder_id' => $folder->id]);

        $response = $this->get(route('laracrate.files.handoff.save', [$file->slug, 'auditius']))
            ->assertOk()
            ->assertSee('Hearings');

        $grant = UploadGrant::fromToken($response->viewData('upload')['grant']);
        $this->assertTrue($grant->fileable()->is($this->owner));
        $this->assertSame('recordings', $grant->collection);
        $this->assertSame($folder->id, $grant->folderId);

        $this->assertSame('https://auditius.com', $response->viewData('editor')['origin']);
        $this->assertSame(['audio' => ['mimes' => ['audio/mpeg'], 'maxBytes' => 2048 * 1024]], $response->viewData('limits'));
    }

    public function test_the_save_page_is_only_for_who_may_add_files_there(): void
    {
        $file = $this->recording();
        app(PolicyRegistry::class)->viewable('test_owner', fn () => true);

        $this->get(route('laracrate.files.handoff.save', [$file->slug, 'auditius']))->assertForbidden();
    }
}
