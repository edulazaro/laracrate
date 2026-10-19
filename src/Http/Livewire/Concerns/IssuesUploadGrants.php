<?php

namespace EduLazaro\Laracrate\Http\Livewire\Concerns;

use EduLazaro\Laracrate\Uploads\PresignedUploads;
use EduLazaro\Laracrate\Uploads\UploadGrant;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;

/**
 * For the upload components: how their uploads reach the shared upload queue.
 *
 * Two modes (`upload-mode`, default `laracrate.uploads.mode`):
 *   presign  the component signs the files when they are picked (presignUploads()), and
 *            the queue registers each one by its key. The browser gets no grant.
 *   grant    the browser gets `uploadGrant` (the encrypted token the upload endpoints
 *            require) and the queue signs each file with it.
 * Either way the uploads live in the page's queue, so they keep going after a
 * wire:navigate. A component that uploads through PHP (an encrypted collection, the
 * single file uploader) always uses 'grant': presign only puts bytes in the bucket.
 *
 * `uploadScope` is a stable name of the target, to find this component's uploads
 * again after a navigation. All three are locked: only the server rewrites them, when
 * the target changes (another slot picked).
 *
 * A component says whether an upload replaces the collection's file and how the
 * bytes travel by default; the collection config can still force the transport.
 */
trait IssuesUploadGrants
{
    #[Locked]
    public string $uploadGrant = '';

    #[Locked]
    public string $uploadScope = '';

    #[Locked]
    public ?string $uploadMode = null;

    protected function refreshUploadGrant(): void
    {
        $grant = $this->buildUploadGrant();

        $mode = $this->uploadMode ?? config('laracrate.uploads.mode', 'presign');
        $this->uploadMode = $mode === 'presign' && $grant->transport === 'direct' ? 'presign' : 'grant';

        $this->uploadGrant = $this->uploadMode === 'grant' ? $grant->token() : '';
        $this->uploadScope = $grant->scope();
    }

    /**
     * 'presign' mode: signs the files the user just picked, while this component is still
     * on the page. Returns, in the same order, the signed upload of each one or why the
     * target refuses it.
     *
     * @param  array<int, mixed>  $files  [{ name, mime, size }]
     */
    #[Renderless]
    public function presignUploads(array $files): array
    {
        if ($this->uploadMode !== 'presign') {
            return [];
        }

        $files = array_slice(array_values($files), 0, 500);

        return PresignedUploads::sign($this->buildUploadGrant(count($files)), $files, $this->uploadRoom());
    }

    /**
     * How many files of one pick the target takes: one when an upload replaces the
     * current file, the component's maxFiles when it has one, otherwise all of them.
     */
    protected function uploadRoom(): ?int
    {
        if ($this->uploadReplaces()) {
            return 1;
        }

        return property_exists($this, 'maxFiles') ? $this->maxFiles : null;
    }

    protected function buildUploadGrant(?int $maxFiles = null): UploadGrant
    {
        return UploadGrant::for(
            $this->model,
            $this->collection,
            slots: property_exists($this, 'slots') ? $this->slots : [],
            creator: property_exists($this, 'creatorType') && $this->creatorType && $this->creatorId
                ? [$this->creatorType, $this->creatorId]
                : null,
            owner: $this->ownerType && $this->ownerId ? [$this->ownerType, $this->ownerId] : null,
            folderId: property_exists($this, 'folderId') ? $this->folderId : null,
            contextKey: property_exists($this, 'contextKey') ? $this->contextKey : null,
            replace: $this->uploadReplaces(),
            transport: $this->uploadTransport(),
            maxFiles: $maxFiles,
        );
    }

    /** Whether an upload replaces the collection's current file (setFile) instead of adding one. */
    protected function uploadReplaces(): bool
    {
        return false;
    }

    /** 'direct' (to the bucket) or 'server' (through PHP), unless the collection decides. */
    protected function uploadTransport(): string
    {
        return 'direct';
    }
}
