<?php

namespace EduLazaro\Laracrate\Extractors;

use EduLazaro\Laracrate\Contracts\TextExtractor;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Support\ExtractedContent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Transcribe audio usando OpenAI Whisper. No hay alternativa de Anthropic
 * (Claude no transcribe audio nativo), así que este extractor es OpenAI-only.
 *
 * Coste: ~$0.006/minuto (whisper-1).
 * Límite: 25MB por archivo en la API.
 *
 * Config:
 *   LARACRATE_OPENAI_API_KEY      = (fallback: OPENAI_API_KEY)
 *   LARACRATE_AUDIO_MODEL         = (opcional, default: whisper-1)
 */
class AudioTranscribeExtractor implements TextExtractor
{
    public function __construct(
        protected ?string $apiKey = null,
        protected ?string $model = null,
        protected int $timeout = 300,
    ) {}

    public function supports(File $file): bool
    {
        if (! str_starts_with((string) $file->mime_type, 'audio/')) {
            return false;
        }
        // Sin OpenAI API key → no aplicamos (silent skip, no throw).
        return ! empty(
            $this->apiKey
            ?? config('laracrate.openai.api_key')
            ?? env('LARACRATE_OPENAI_API_KEY')
            ?? env('OPENAI_API_KEY')
        );
    }

    public function extract(File $file): ExtractedContent
    {
        $apiKey = $this->apiKey
            ?? config('laracrate.openai.api_key')
            ?: env('LARACRATE_OPENAI_API_KEY')
            ?: env('OPENAI_API_KEY');

        if (! $apiKey) {
            throw new RuntimeException('OpenAI API key no configurada para AudioTranscribeExtractor.');
        }

        $model = $this->model
            ?? config('laracrate.audio.model')
            ?: env('LARACRATE_AUDIO_MODEL', 'whisper-1');

        // Whisper requiere el archivo en multipart. Lo bajamos a tmp para
        // poder enviarlo con `attach()`.
        $bytes = Storage::disk($file->disk)->get($file->path);
        if ($bytes === null || $bytes === false) {
            throw new RuntimeException("AudioTranscribe: no se pudo leer {$file->path}");
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
