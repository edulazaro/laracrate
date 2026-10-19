# Upgrading from 1.x to 2.0

Each item says what changed and what to do. Run the new migrations first:

```bash
php artisan migrate
```

`0000_00_00_000041` rewrites `access` to `public` or `private`, and `0000_00_00_000042` adds the `mode` column. Until 041 runs, rows read fine, but new rows fail to insert, because the `access` column does not accept `private` yet.

## Access and URLs

- **`access` is `public` or `private`.** `signed` and `stream` are gone as values. The migration rewrites them to `private`, and marks the `stream` rows `sensitive` so they keep being served only through PHP. In your config, write `'access' => 'private'` (add `'sensitive' => true` where you had `stream`). The 1.x values are still read, `stream` as private plus sensitive, so nothing breaks while you change them.
- **How a private file is served is chosen per use, not per collection.**
  - `$file->url()` is the direct link. Private: a link signed straight to the disk, which expires after `laracrate.urls.signed_ttl`, or when you say with `url(expires: now()->addHours(3))` (S3/R2 refuse more than 7 days). Encrypted or sensitive: the stream route.
  - `$file->streamUrl()` and `$file->downloadUrl()` serve the file through PHP. They carry no signature and never expire: the routes sit behind the session and check `canView` on every request. Keep `auth` in `laracrate.stream.middleware`.
- **`previewUrl()` and the `laracrate.files.preview` route are removed.** Use `streamUrl()`: it shows the file inline and, like `preview` did, does not count a download. Only `download` counts now.
- **The viewer endpoint is `{slug}/viewer`, named `laracrate.files.viewer`** (it was `view`). Update any `route('laracrate.files.view', …)`.
- **Removed:**
  - the config keys `urls.route_signed_ttl`, `urls.bind_to_user` and `urls.sensitive_redirect_ttl`;
  - the `u` query parameter;
  - `GenerateSensitiveStreamUrlAction`;
  - `StreamFileController::link()`.

  Delete the keys from your published config.

## Variants, previews and video

- **Video and PDF variants are declared in `variants`, with a `type`, under the names your app chooses:**
  - `video`: an MP4 copy, or a teaser with `clips`/`seconds`/`audio`/`generator`;
  - `image`: a frame (`frame_at`) or a PDF page (`page`, 1-indexed);
  - `frames`: a strip of frames.

  Nested `variants` are made from their variant. See "Video and PDF variants" in the README.
- **`preview` names a variant now.** A type's `'preview' => [...]` block (`frame_at`, `page`, `variants`) is refused with an `InvalidArgumentException` that says what to write. Move it into `variants` and point `preview` at it. To keep the rows already generated under `preview`, keep that name:

  ```php
  // 1.x
  'video' => ['preview' => ['frame_at' => '00:00:01', 'variants' => ['thumbnail' => ['width' => 300]]]],

  // 2.0
  'video' => [
      'variants' => ['preview' => ['type' => 'image', 'frame_at' => '00:00:01', 'variants' => ['thumbnail' => ['width' => 300]]]],
      'preview'  => 'preview.thumbnail',
  ],
  ```

  For a PDF, the same with `'type' => 'image', 'page' => 1`.
- **`$file->preview()` returns the variant the type's `preview` names, or `null`.** `preview_link` reads it instead of looking up `preview.thumbnail`. Without a `preview` in the config, a video or a PDF gets the placeholder and an image gets itself.
- **`File::playback()` and `'transcode' => 'variant'` are removed.**
  - Declare the copy in `variants` and read it by name: `'variants' => ['playback']` keeps the rows already called `playback`, and `$file->variant('playback')` reads it.
  - `'transcode' => 'variant'` is refused with a message.
  - `'transcode' => true` still replaces the original.
  - `TranscodeVideoAction` no longer takes `keepOriginal`.
- **The viewer plays the video variant in `laracrate.viewer.video_variant`** (none by default), as it shows the image one in `image_variant`. Set it to your copy's name, for example `'playback'`, to keep the 1.x behaviour.
- **Removed classes:** `ExtractVideoPreviewAction`, `ExtractVideoPreviewStep` and `ExtractPdfPreviewStep`. `ExtractPdfPreviewAction` is now `RenderPdfPageAction`, which takes the variant `name`. If you registered or extended them, use `GenerateVideoVariantsAction`/`GenerateVideoVariantsStep`, `GeneratePdfVariantsStep` and `RenderPdfPageAction`.

## Types

- **Images have a `mode`: `default`, `sphere` (a 360° photo) or `frames` (a strip of a video's frames).** Their `type` stays `image`, so existing checks and queries on `type` keep working.
- **360° photos are told apart only where the collection's image type takes that mode** (`'image' => ['modes' => ['default', 'sphere']]`); otherwise they stay `default`. They are recognised by their XMP (`GPano:ProjectionType="equirectangular"`), not by their proportions. An app that marked them with `category` can move to `mode = 'sphere'`.

## Livewire components and themes

- **Three components, with `deferred` as a prop.** The tags `laracrate-uploader-deferred`, `laracrate-dropzone-deferred` and `laracrate-dropzone-single-deferred` are removed. Write `<livewire:laracrate-uploader :deferred="true" />`, and likewise for the two dropzones.
- **The look is one CSS file.** Import `vendor/edulazaro/laracrate/resources/css/laracrate.css` once in your app's CSS. There is one view per component and no view per theme:
  - the views are no longer published (the `laracrate-views` tag is gone);
  - delete any copies in `resources/views/vendor/laracrate`, since they would override the new views.
- **Themes:**
  - pick one per page with `data-wire-theme` on an ancestor, or per component with `theme="…"`;
  - customise with the `rounded`, `bordered`, `shadow`, `size`, `class` and `style` props, and the `--wire-laracrate-*` variables;
  - `ui.default_theme` and `LARACRATE_THEME` are removed.
- **CSS classes use `laracrate-`.** The `lc-` classes of the tray, the viewer and the handoff page are now `laracrate-…`. Update any CSS that targets them.

## Uploads

- **The components upload in `presign` mode by default** (`laracrate.uploads.mode`, optionally `LARACRATE_UPLOAD_MODE`, or `upload-mode` per component). The component signs the picked files itself and the browser never names a target. `grant` mode keeps the 1.x flow.
- **An `UploadGrant` belongs to the user and the browser session it was issued in,** and can cap its files with `maxFiles`:
  - issue it from the user's request, for example a Livewire method;
  - a token used from another session, or by another user, is refused;
  - `UploadGrant::for(..., maxFiles: $count)` limits how many files it signs.
- **Presign only signs into `temp/`.** The plain `presign` endpoint ignores model parameters. Register the object with `addFile(FileUpload::fromArray([...]))`, which moves it to its final key. `cancel` only deletes a `temp/` key this browser signed.
- **Multipart runs under a grant.**
  - `init` takes the grant, and `parts`, `complete` and `abort` only answer to the grant that started the session.
  - The routes take `laracrate.multipart.middleware`, or `laracrate.uploads.middleware` when it is null. In 1.x a null multipart middleware left them open: still on 1.x, set `'middleware' => ['web', 'auth']` there.
- **`addFile()` measures the size.** With a `FileUpload`, the object's real size is read from the bucket and checked against the collection. The declared `size` is ignored, and an object that is too big or missing is refused (`UploadRejected`, reason `size` or `missing`) and deleted.
- **Removed:**
  - `registerUploaded()` from the components (presign mode registers through `laracrate/uploads/register`);
  - `setSlots()` from the dropzone;
  - `folder()` from `UploaderHasFolderTarget` (the components keep a locked `folderId`).
