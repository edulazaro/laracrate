<?php

namespace EduLazaro\Laracrate\Extractors;

use EduLazaro\Laracrate\Contracts\TextExtractor;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Support\CollectionConfig;
use EduLazaro\Laracrate\Support\ExtractedContent;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Transcribe vídeo: extrae audio con ffmpeg → Whisper. Opcionalmente
 * añade descripción visual de frames si la collection declara
 * `extract` incluyendo `video.visual` (pseudo-type).
 *
 * Requiere ffmpeg instalado en el sistema. Si no está, `supports()` devuelve
 * false y el step lo omite limpiamente.
 *
 * Coste:
 *   Audio (Whisper): ~$0.006/minuto
 *   Visual (opcional): ~$0.001/frame × ~2 frames/minuto = ~$0.002/min extra
 *
 * Config:
 *   LARACRATE_OPENAI_API_KEY = (fallback: OPENAI_API_KEY)
 *   LARACRATE_VIDEO_FRAME_INTERVAL = (default: 30) segundos entre frames visuales
 */
class VideoTranscribeExtractor implements TextExtractor
{
    public function __construct(
        protected ?AudioTranscribeExtractor $audioExtractor = null,
        protected ?OcrImageTextExtractor $imageExtractor = null,
    ) {
        $this->audioExtractor ??= new AudioTranscribeExtractor();
        $this->imageExtractor ??= new OcrImageTextExtractor();
    }

    public function supports(File $file): bool
    {
        if (! str_starts_with((string) $file->mime_type, 'video/')) {
            return false;
        }
        // Necesita ffmpeg + OpenAI API key (Whisper).
        $hasKey = ! empty(
            config('laracrate.openai.api_key')
            ?? env('LARACRATE_OPENAI_API_KEY')
            ?? env('OPENAI_API_KEY')
        );
        return $this->ffmpegAvailable() && $hasKey;
    }

    public function extract(File $file): ExtractedContent
    {
        $bytes = Storage::disk($file->disk)->get($file->path);
        if ($bytes === null || $bytes === false) {
            throw new RuntimeException("VideoTranscribe: no se pudo leer {$file->path}");
        }

        $ext = strtolower($file->extension ?? 'mp4');
        $tmpVideo = tempnam(sys_get_temp_dir(), 'laracrate_video_') . '.' . $ext;
        file_put_contents($tmpVideo, $bytes);
        $tmpAudio = $tmpVideo . '.mp3';

        $framesText = '';
        $audioText  = '';

        try {
            // 1. Extraer audio.
            $this->ffmpegExtractAudio($tmpVideo, $tmpAudio);

            // 2. Transcribir audio via Whisper (reusamos AudioTranscribeExtractor
            //    creando un File "virtual" en local con el path tmp).
            //    Como Whisper acepta multipart directo, hacemos la llamada inline.
            $audioText = $this->transcribeLocalFile($tmpAudio);

            // 3. Visual frames opcional — si la collection lo activó.
            if ($this->wantsVisualFrames($file)) {
                $framesText = $this->describeFrames($file, $tmpVideo);
            }
        } finally {
            if (file_exists($tmpVideo)) @unlink($tmpVideo);
            if (file_exists($tmpAudio)) @unlink($tmpAudio);
        }

        // Cada modalidad se emite como página separada con su `context` para
        // que el chunker produzca filas independientes en `laracrate_file_chunks`
        // (un embedding por modalidad: la búsqueda semántica matchea contra
        // transcripción literal Y descripción visual por separado).
        $audioText  = trim($audioText);
        $framesText = trim($framesText);

        $pages = [];
        if ($audioText !== '') {
            $pages[] = ['page_number' => 1, 'text' => $audioText, 'context' => 'text'];
        }
        if ($framesText !== '') {
            $pages[] = ['page_number' => count($pages) + 1, 'text' => $framesText, 'context' => 'description'];
        }

        // Defensa: si no hay nada, devolvemos página vacía para que el pipeline
        // marque el File como procesado sin texto.
        if (empty($pages)) {
            $pages[] = ['page_number' => 1, 'text' => ''];
        }

        return ExtractedContent::fromPages($pages, [
            'extractor'  => static::class,
            'provider'   => 'openai',
            'has_audio'  => $audioText !== '',
            'has_visual' => $framesText !== '',
        ]);
    }

    protected function wantsVisualFrames(File $file): bool
    {
        $config = CollectionConfig::resolve($file->collection, $file->fileable_type);
        $extract = $config['extract'] ?? null;

        // Solo soportamos visual frames si la collection declara `video.visual`
        // (pseudo-type opt-in explícito).
        if (! is_array($extract)) {
            return false;
        }

        return in_array('video.visual', $extract, true);
    }

    protected function ffmpegAvailable(): bool
    {
        $cmd = PHP_OS_FAMILY === 'Windows' ? 'where ffmpeg' : 'which ffmpeg';
        exec($cmd . ' 2>/dev/null', $out, $code);
        return $code === 0;
    }

    protected function ffmpegExtractAudio(string $videoPath, string $outAudioPath): void
    {
        $cmd = sprintf(
            'ffmpeg -y -i %s -vn -ac 1 -ar 16000 -b:a 64k %s 2>&1',
            escapeshellarg($videoPath),
            escapeshellarg($outAudioPath)
        );
        exec($cmd, $out, $code);
        if ($code !== 0 || ! file_exists($outAudioPath)) {
            throw new RuntimeException('ffmpeg fallo extrayendo audio: ' . implode("\n", $out));
        }
    }

    protected function transcribeLocalFile(string $audioPath): string
    {
        $apiKey = config('laracrate.openai.api_key')
            ?: env('LARACRATE_OPENAI_API_KEY')
            ?: env('OPENAI_API_KEY');

        if (! $apiKey) {
            return '';
        }

        $model = env('LARACRATE_AUDIO_MODEL', 'whisper-1');

        $response = \Illuminate\Support\Facades\Http::withToken($apiKey)
            ->timeout(300)
            ->retry(2, 2000)
            ->attach('file', file_get_contents($audioPath), basename($audioPath))
            ->post('https://api.openai.com/v1/audio/transcriptions', [
                'model'           => $model,
                'response_format' => 'json',
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                'OpenAI Whisper error (video audio): ' . $response->status() . ' ' . $response->body()
            );
        }

        return (string) $response->json('text', '');
    }

    /**
     * Extrae frames cada N segundos con ffmpeg y los pasa por Vision.
     * Devuelve descripciones concatenadas con timestamps.
     */
    protected function describeFrames(File $file, string $videoPath): string
    {
        $interval = (int) env('LARACRATE_VIDEO_FRAME_INTERVAL', 30);
        if ($interval < 5) $interval = 5;

        $duration = $this->ffprobeDuration($videoPath);
        if ($duration <= 0) return '';

        $descriptions = [];
        for ($t = 0; $t < $duration; $t += $interval) {
            $framePath = $videoPath . '.frame.' . $t . '.jpg';
            try {
                $this->ffmpegExtractFrame($videoPath, $t, $framePath);
                if (! file_exists($framePath)) continue;

                $virtualFile = new \stdClass();
                $virtualFile->disk = null;
                $virtualFile->path = $framePath;
                $virtualFile->mime_type = 'image/jpeg';
                $virtualFile->extension = 'jpg';

                // Reusamos el OCR de imagen para describir el frame.
                $description = $this->describeFrameInline($framePath);
                if ($description !== '') {
                    $descriptions[] = "[{$this->formatTimestamp($t)}] {$description}";
                }
            } finally {
                if (file_exists($framePath)) @unlink($framePath);
            }
        }

        return implode("\n", $descriptions);
    }

    protected function ffprobeDuration(string $path): float
    {
        $cmd = sprintf(
            'ffprobe -v error -show_entries format=duration -of csv=p=0 %s 2>/dev/null',
            escapeshellarg($path)
        );
        $out = trim((string) shell_exec($cmd));
        return (float) $out;
    }

    protected function ffmpegExtractFrame(string $videoPath, int $second, string $outPath): void
    {
        $cmd = sprintf(
            'ffmpeg -y -ss %d -i %s -frames:v 1 -q:v 3 %s 2>&1',
            $second,
            escapeshellarg($videoPath),
            escapeshellarg($outPath)
        );
        exec($cmd, $out, $code);
    }

    protected function describeFrameInline(string $framePath): string
    {
        $bytes = file_get_contents($framePath);
        if ($bytes === false) return '';

        $base64 = base64_encode($bytes);
        $apiKey = config('laracrate.openai.api_key')
            ?: env('LARACRATE_OPENAI_API_KEY')
            ?: env('OPENAI_API_KEY');

        if (! $apiKey) return '';

        $response = \Illuminate\Support\Facades\Http::withToken($apiKey)
            ->timeout(60)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model'    => env('LARACRATE_OCR_MODEL', 'gpt-4o-mini'),
                'messages' => [[
                    'role'    => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => 'Describe brevemente esta escena (1 frase). Sé objetivo. Si hay texto visible, transcríbelo entre comillas.'],
                        ['type' => 'image_url', 'image_url' => ['url' => "data:image/jpeg;base64,{$base64}"]],
                    ],
                ]],
                'max_tokens' => 200,
            ]);

        if (! $response->successful()) return '';
        return trim((string) $response->json('choices.0.message.content', ''));
    }

    protected function formatTimestamp(int $seconds): string
    {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        return $h > 0
            ? sprintf('%02d:%02d:%02d', $h, $m, $s)
            : sprintf('%02d:%02d', $m, $s);
    }
}
