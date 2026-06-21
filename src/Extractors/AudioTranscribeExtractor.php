<?php

namespace EduLazaro\Laracrate\Extractors;

use EduLazaro\Laracrate\Contracts\TextExtractor;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Support\ExtractedContent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Transcribes audio using OpenAI Whisper. There is no Anthropic alternative
 * (Claude does not transcribe audio natively), so this extractor is OpenAI-only.
 *
 * Cost: ~$0.006/minute (whisper-1).
 * Limit: 25MB per file in the API.
 *
 * Config:
 *   LARACRATE_OPENAI_API_KEY      = (fallback: OPENAI_API_KEY)
 *   LARACRATE_AUDIO_MODEL         = (optional, default: whisper-1)
 */
class AudioTranscribeExtractor implements TextExtractor
{
    /**
     * Create a new audio transcribe extractor.
     */
    public function __construct(
        protected ?string $apiKey = null,
        protected ?string $model = null,
        protected int $timeout = 300,
    ) {}

    /**
     * Determine whether this extractor can handle the given file.
     */
    public function supports(File $file): bool
    {
        if (! str_starts_with((string) $file->mime_type, 'audio/')) {
            return false;
        }
        // Without an OpenAI API key, skip silently (no throw).
        return ! empty(
            $this->apiKey
            ?? config('laracrate.openai.api_key')
            ?? env('LARACRATE_OPENAI_API_KEY')
            ?? env('OPENAI_API_KEY')
        );
    }

    /**
     * Transcribe the audio file and return the extracted text.
     */
    public function extract(File $file): ExtractedContent
    {
        $apiKey = $this->apiKey
            ?? config('laracrate.openai.api_key')
            ?: env('LARACRATE_OPENAI_API_KEY')
            ?: env('OPENAI_API_KEY');

        if (! $apiKey) {
            throw new RuntimeException('OpenAI API key not configured for AudioTranscribeExtractor.');
        }

        $model = $this->model
            ?? config('laracrate.audio.model')
            ?: env('LARACRATE_AUDIO_MODEL', 'whisper-1');

        // Whisper requires the file as multipart. Download it to a tmp file so
        // it can be sent with `attach()`.
        $bytes = Storage::disk($file->disk)->get($file->path);
        if ($bytes === null || $bytes === false) {
            throw new RuntimeException("AudioTranscribe: could not read {$file->path}");
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'laracrate_audio_');
        $ext = strtolower($file->extension ?? 'mp3');
        $tmpPathWithExt = $tmpPath . '.' . $ext;
        rename($tmpPath, $tmpPathWithExt);
        file_put_contents($tmpPathWithExt, $bytes);

        try {
            $response = Http::withToken($apiKey)
                ->timeout($this->timeout)
                ->retry(2, 2000)
                ->attach('file', file_get_contents($tmpPathWithExt), basename($tmpPathWithExt))
                ->post('https://api.openai.com/v1/audio/transcriptions', [
                    'model'           => $model,
                    'response_format' => 'json',
                ]);

            if (! $response->successful()) {
                throw new RuntimeException(
                    'OpenAI Whisper API error: ' . $response->status() . ' ' . $response->body()
                );
            }

            $text = (string) $response->json('text', '');

            return ExtractedContent::singlePage(trim($text), [
                'extractor' => static::class,
                'provider'  => 'openai',
                'model'     => $model,
            ]);
        } finally {
            if (file_exists($tmpPathWithExt)) {
                @unlink($tmpPathWithExt);
            }
        }
    }
}
