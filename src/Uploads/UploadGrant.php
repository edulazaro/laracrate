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
 * It only works for the user it was issued to (their model class and id, so a client
 * #5 and a user #5 are not the same person) and in the browser session it was issued
 * in: a token copied elsewhere is refused even with the same account. `maxFiles`
 * caps how many files it signs, counted by the upload endpoints.
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
        public readonly ?array $user,
        public readonly ?string $session,
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
        public readonly ?int $maxFiles = null,
    ) {}

    /**
     * @param  Model|array{0: string, 1: int|string}|null  $creator  model or [morph, id]
     * @param  Model|array{0: string, 1: int|string}|null  $owner    model or [morph, id]
     * @param  string  $transport  the component's default: 'direct' or 'server'
     * @param  int|null  $maxFiles  how many files it may sign; null for no limit
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
        ?int $maxFiles = null,
    ): self {
        $config = $fileable->getCollectionConfig($collection);

        $transport = match (true) {
            (bool) ($config['encrypt'] ?? false) => 'server',
            in_array($config['upload'] ?? null, ['direct', 'server'], true) => $config['upload'],
            default => $transport === 'server' ? 'server' : 'direct',
        };

        return new self(
            id: (string) Str::ulid(),
            user: self::currentUser(),
            session: self::currentSession(),
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
            maxFiles: $maxFiles !== null && $maxFiles > 0 ? $maxFiles : null,
        );
    }

    public function token(): string
    {
        return Crypt::encryptString(json_encode([
            'v' => 2,
            'i' => $this->id,
            'u' => $this->user,
            'ss' => $this->session,
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
            'm' => $this->maxFiles,
        ]));
    }

    /**
     * Reads a token sent back by the browser.
     *
     * @throws InvalidUploadGrant  unreadable, expired, or issued to another user or browser
     */
    public static function fromToken(string $token): self
    {
        try {
            $data = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw new InvalidUploadGrant();
        }

        // A version 1 token, from a page opened before the update, reads as expired.
        if (($data['v'] ?? null) !== 2 || ($data['e'] ?? 0) < now()->getTimestamp()) {
            throw new InvalidUploadGrant('The upload grant has expired. Reload the page.');
        }

        if (($data['u'] ?? null) !== self::currentUser()) {
            throw new InvalidUploadGrant();
        }

        // A grant issued without a session (a console command, a stateless API) has
        // none to compare; one issued in a browser only works in that browser.
        if (($data['ss'] ?? null) !== null && $data['ss'] !== self::currentSession()) {
            throw new InvalidUploadGrant();
        }

        return new self(
            id: $data['i'],
            user: $data['u'],
            session: $data['ss'] ?? null,
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
            maxFiles: isset($data['m']) ? (int) $data['m'] : null,
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

    /** The signed in user as [morph class, id], or null for a guest. Presign's cancel uses it too. */
    public static function currentUser(): ?array
    {
        $user = auth()->user();

        if ($user === null) {
            return null;
        }

        return [$user instanceof Model ? $user->getMorphClass() : $user::class, (string) $user->getAuthIdentifier()];
    }

    /** A fingerprint of the browser session, or null when the request has none. Presign's cancel uses it too. */
    public static function currentSession(): ?string
    {
        $request = request();

        return $request->hasSession() ? hash('sha256', $request->session()->getId()) : null;
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
