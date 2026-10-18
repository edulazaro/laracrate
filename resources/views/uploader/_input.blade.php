{{--
    The file input of the single file uploader themes. The file goes to the shared
    upload queue (window.Laracrate.uploads) with this component's grant, through PHP
    by default, so it keeps uploading after a wire:navigate. The bridge refreshes the
    component when it is saved and puts a refusal under the field (@error('upload')).
--}}
<input type="file" x-ref="input" accept="{{ $acceptAttr }}" class="hidden"
       x-on:change="if ($event.target.files.length) window.Laracrate.uploads.add([$event.target.files[0]], { grant: $wire.uploadGrant, scope: $wire.uploadScope, origin: $el }); $event.target.value = ''" />
<span hidden x-data="laracrateUploaderBridge($wire)"></span>
@once
@script
<script>
    {!! \EduLazaro\Laracrate\Support\UploaderScript::source() !!}
</script>
@endscript
@endonce
