{{--
    The file input of the single file uploader themes. The file goes to the shared
    upload queue (window.Laracrate.uploads) in this component's mode, through PHP by
    default, so it keeps uploading after a wire:navigate. The bridge refreshes the
    component when it is saved and puts a refusal under the field (@error('upload')).
--}}
<input type="file" x-ref="input" accept="{{ $acceptAttr }}" class="laracrate-hidden"
       x-on:change="if ($event.target.files.length) window.Laracrate.uploads.forComponent($wire, [$event.target.files[0]], { origin: $el }); $event.target.value = ''" />
<span hidden x-data="laracrateUploaderBridge($wire)"></span>
@once
@script
<script>
    {!! \EduLazaro\Laracrate\Support\UploaderScript::source() !!}
</script>
@endscript
@endonce
