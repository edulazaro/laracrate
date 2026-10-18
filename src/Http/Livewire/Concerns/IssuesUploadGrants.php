<?php

namespace EduLazaro\Laracrate\Http\Livewire\Concerns;

use EduLazaro\Laracrate\Uploads\UploadGrant;
use Livewire\Attributes\Locked;

/**
 * For the upload components: turns their locked props into an UploadGrant.
 *
 * The browser gets `uploadGrant` (the encrypted token the upload endpoints require)
 * and `uploadScope` (a stable name of the target, to find this component's uploads
 * again after a navigation). Both are locked: only the server rewrites them, when
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

    protected function refreshUploadGrant(): void
    {
        $grant = UploadGrant::for(
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
        );

        $this->uploadGrant = $grant->token();
        $this->uploadScope = $grant->scope();
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
