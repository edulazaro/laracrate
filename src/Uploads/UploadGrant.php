<?php

namespace EduLazaro\Laracrate\Uploads;

use EduLazaro\Laracrate\Exceptions\InvalidUploadGrant;
use EduLazaro\Laracrate\Models\Folder;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Where an upload lands and how, decided on the server and carried by the browser.
 *
 * An upload component builds it from its locked props when it mounts (model,
 * collection, slots, creator, owner, folder, context key, replace) and hands the
 * browser only `token()`: an encrypted, signed string it can neither read nor alter.
 * The upload endpoints accept nothing else, so the target never comes from the
 * request: no other disk, model or key can be named there.
 *
 * Being self-contained is what lets an upload outlive its page: whoever finishes it
 * (the component, the upload tray after a wire:navigate) just sends the token back.
 *
 * `transport` is how the bytes travel: 'direct' (presigned PUT to the bucket) or
 * 'server' (through PHP). A collection with `encrypt` always goes through the
 * server, the only place it can be encrypted; otherwise the collection's `upload`
 * setting wins over the component's own default.
 */
final class UploadGrant
{
    /** Long enough for a page left open all day. */
    private const TTL_SECONDS = 86400;

    private function __construct(
        public readonly string $id,
        public readonly ?string $userId,
        public readonly string $fileableType,
        public readonly string $fileableId,
        public readonly string $collection,
        public readonly array $slots,
        public readonly ?array $creator,
        public readonly ?array $owner,
        public readonly ?int $folderId,
        public readonly ?string $contextKey,
        public readonly bool $replace,
        public readonly string $transport,
        public readonly int $expiresAt,
    ) {}

    /**
     * @param  Model|array{0: string, 1: int|string}|null  $creator  model or [morph, id]
     * @param  Model|array{0: string, 1: int|string}|null  $owner    model or [morph, id]
     * @param  string  $transport  the component's default: 'direct' or 'server'
     */
    public static function for(
        Model $fileable,
        string $collection,
        array $slots = [],
        Model|array|null $creator = null,
        Model|array|null $owner = null,
        ?int $folderId = null,
        ?string $contextKey = null,
        bool $replace = false,
        string $transport = 'direct',
    ): self {
        $config = $fileable->getCollectionConfig($collection);

        $transport = match (true) {
            (bool) ($config['encrypt'] ?? false) => 'server',
            in_array($config['upload'] ?? null, ['direct', 'server'], true) => $config['upload'],
            default => $transport === 'server' ? 'server' : 'direct',
        };

        return new self(
            id: (string) Str::ulid(),
            userId: auth()->id() !== null ? (string) auth()->id() : null,
            fileableType: $fileable->getMorphClass(),
            fileableId: (string) $fileable->getKey(),
            collection: $collection,
            slots: array_values(array_filter(array_map('intval', $slots))),
            creator: self::reference($creator),
            owner: self::reference($owner),
            folderId: $folderId,
            contextKey: $contextKey,
            replace: $replace,
            transport: $transport,
            expiresAt: now()->getTimestamp() + self::TTL_SECONDS,
        );
    }

    public function token(): string
    {
        return Crypt::encryptString(json_encode([
            'v' => 1,
            'i' => $this->id,
            'u' => $this->userId,
            'f' => [$this->fileableType, $this->fileableId],
            'c' => $this->collection,
            's' => $this->slots,
            'cr' => $this->creator,
            'o' => $this->owner,
            'd' => $this->folderId,
            'k' => $this->contextKey,
            'r' => $this->replace,
            't' => $this->transport,
            'e' => $this->expiresAt,
        ]));
    }

    /**
     * Reads a token sent back by the browser.
     *
     * @throws InvalidUploadGrant  unreadable, expired, or issued to another user
     */
    public static function fromToken(string $token): self
    {
        try {
            $data = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw new InvalidUploadGrant();
        }

        if (($data['v'] ?? null) !== 1 || ($data['e'] ?? 0) < now()->getTimestamp()) {
            throw new InvalidUploadGrant('The upload grant has expired. Reload the page.');
        }

        $current = auth()->id() !== null ? (string) auth()->id() : null;
        if (($data['u'] ?? null) !== $current) {
            throw new InvalidUploadGrant();
        }

        return new self(
            id: $data['i'],
            userId: $data['u'],
            fileableType: $data['f'][0],
            fileableId: (string) $data['f'][1],
            collection: $data['c'],
            slots: $data['s'] ?? [],
            creator: $data['cr'] ?? null,
            owner: $data['o'] ?? null,
            folderId: $data['d'] ?? null,
            contextKey: $data['k'] ?? null,
            replace: (bool) ($data['r'] ?? false),
            transport: $data['t'] === 'server' ? 'server' : 'direct',
            expiresAt: (int) $data['e'],
        );
    }

    /**
     * A stable, non-secret name for the target. Uploads to the same place share it,
     * so a component mounted again after a navigation finds the ones still running.
     */
    public function scope(): string
    {
        return substr(sha1(implode('|', [
            $this->fileableType, $this->fileableId, $this->collection,
            $this->folderId ?? '', $this->contextKey ?? '', implode(',', $this->slots),
        ])), 0, 16);
    }

    public function fileable(): Model
    {
        return self::resolve([$this->fileableType, $this->fileableId])
            ?? throw new InvalidUploadGrant('The upload target no longer exists.');
    }

    public function creator(): ?Model
    {
        return $this->creator ? self::resolve($this->creator) : null;
    }

    public function owner(): ?Model
    {
        return $this->owner ? self::resolve($this->owner) : null;
    }

    public function folder(): ?Folder
    {
        return $this->folderId ? Folder::find($this->folderId) : null;
    }

    /** The disk the file is written to (the tenant's dedicated bucket when it has one). */
    public function disk(): string
    {
        return $this->fileable()->uploadDiskFor($this->collection);
    }

    private static function reference(Model|array|null $model): ?array
    {
        if ($model instanceof Model) {
            return [$model->getMorphClass(), (string) $model->getKey()];
        }

        return is_array($model) && count($model) === 2 && $model[0] && $model[1]
            ? [(string) $model[0], (string) $model[1]]
            : null;
    }

    private static function resolve(array $reference): ?Model
    {
        [$type, $id] = $reference;
        $class = Relation::getMorphedModel($type) ?? $type;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        return $class::find($id);
    }
}
