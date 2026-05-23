<?php

namespace EduLazaro\Laracrate\Extractors;

use EduLazaro\Laracrate\Contracts\TextExtractor;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Support\ExtractedContent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * OCR de imágenes usando Vision (Anthropic Claude o OpenAI). Para escaneados
 * de documentos guardados como JPG/PNG, fotos de contratos firmados,
 * capturas de pantalla, etc.
 *
 * Mismo provider switching que OcrPdfTextExtractor: la app elige via
 * `LARACRATE_OCR_PROVIDER`.
 *
 * Coste estimado por imagen:
 *   - Anthropic Claude Haiku 4.5: ~$0.0005
 *   - OpenAI gpt-4o-mini:          ~$0.001
 */
class OcrImageTextExtractor implements TextExtractor
{
    public function __construct(
        protected ?string $provider = null,
        protected ?string $model = null,
        protected ?string $apiKey = null,
        protected int $timeout = 120,
    ) {
        $this->provider ??= config('laracrate.ocr.provider', 'anthropic');
    }

    public function supports(File $file): bool
    {
        $mime = (string) $file->mime_type;
        $isSupportedImage = str_starts_with($mime, 'image/')
            && in_array($mime, [
                'image/jpeg', 'image/png', 'image/webp',
                'image/gif',  'image/heic', 'image/heif',
            ], true);

        if (! $isSupportedImage) {
            return false;
        }

        // Sin API key para el provider configurado → no aplicamos (la chain
        // intenta el siguiente extractor en lugar de fallar el proceso entero).
        return $this->hasApiKey();
    }

    protected function hasApiKey(): bool
    {
        if ($this->provider === 'openai') {
            return ! empty(
                $this->apiKey
                ?? config('laracrate.ocr.openai.api_key')
                ?? env('LARACRATE_OPENAI_API_KEY')
                ?? env('OPENAI_API_KEY')
            );
        }

        // anthropic (default)
        return ! empty(
            $this->apiKey
            ?? config('laracrate.ocr.anthropic.api_key')
            ?? env('LARACRATE_ANTHROPIC_API_KEY')
            ?? env('ANTHROPIC_API_KEY')
        );
    }

    public function extract(File $file): ExtractedContent
    {
        $bytes = Storage::disk($file->disk)->get($file->path);
        if ($bytes === null || $bytes === false) {
            throw new RuntimeException("OCR-Image: no se pudo leer {$file->path}");
        }

        $base64 = base64_encode($bytes);
        $mime   = (string) $file->mime_type;

        $raw = match ($this->provider) {
            'openai'    => $this->extractWithOpenAi($base64, $mime),
            'anthropic' => $this->extractWithAnthropic($base64, $mime),
            default     => throw new RuntimeException("OCR provider desconocido: {$this->provider}"),
        };

        // Parsea respuesta en dos secciones: [TEXT] y [DESCRIPTION]. Cada una
        // se devuelve como página separada con `context` propio para que el
        // chunker produzca filas distintas (un embedding por sección).
        [$ocrText, $description] = $this->parseSections($raw);

        $pages = [];
        if ($ocrText !== '') {
            $pages[] = ['page_number' => 1, 'text' => $ocrText, 'context' => 'text'];
        }
        if ($description !== '') {
            $pages[] = ['page_number' => count($pages) + 1, 'text' => $description, 'context' => 'description'];
        }

        // Fallback defensivo: si el modelo no respetó los headers, vuelca todo
        // como una sola página sin context (comportamiento legacy).
        if (empty($pages)) {
            $pages[] = ['page_number' => 1, 'text' => trim($raw)];
        }

        return ExtractedContent::fromPages($pages, [
            'extractor' => static::class,
            'provider'  => $this->provider,
        ]);
    }

    /**
     * Parsea la respuesta del modelo en `[TEXT]\n...\n[DESCRIPTION]\n...`.
     * Tolerante con variaciones del modelo (mayúsculas, espacios, ausencia de
     * alguna sección).
     *
     * @return array{0:string,1:string} [text, description]
     */
    protected function parseSections(string $raw): array
    {
        $raw = trim($raw);

        if (preg_match('/\[TEXT\](.*?)(?=\[DESCRIPTION\]|$)/is', $raw, $m1)) {
            $text = trim($m1[1]);
        } else {
            $text = '';
        }

        if (preg_match('/\[DESCRIPTION\](.*)$/is', $raw, $m2)) {
            $description = trim($m2[1]);
        } else {
            $description = '';
        }

        // Si no aparecen headers pero hay contenido, asumimos que todo es TEXT.
        if ($text === '' && $description === '' && $raw !== '') {
            $text = $raw;
        }

        return [$text, $description];
    }

    protected function extractWithAnthropic(string $base64, string $mime): string
    {
        $apiKey = $this->apiKey
            ?? config('laracrate.ocr.anthropic.api_key')
            ?: env('LARACRATE_ANTHROPIC_API_KEY')
            ?: env('ANTHROPIC_API_KEY');

        if (! $apiKey) {
            throw new RuntimeException('Anthropic API key no configurada.');
        }

        $model = $this->model
            ?? config('laracrate.ocr.anthropic.model')
            ?: env('LARACRATE_OCR_MODEL', 'claude-haiku-4-5');

        $response = Http::withHeaders([
            'x-api-key'         => $apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])
            ->timeout($this->timeout)
            ->retry(2, 1000)
            ->post('https://api.anthropic.com/v1/messages', [
                'model'      => $model,
                'max_tokens' => 4000,
                'messages'   => [[
                    'role'    => 'user',
                    'content' => [
                        [
                            'type'   => 'image',
                            'source' => [
                                'type'       => 'base64',
                                'media_type' => $mime,
                                'data'       => $base64,
                            ],
                        ],
                        [
                            'type' => 'text',
                            'text' => "Analyze this image and return TWO sections in plain text with these exact headers:\n\n"
                                . "[TEXT]\n"
                                . "All visible text from the image, verbatim. Preserve structure (paragraphs, lists, tables). "
                                . "Leave this section empty if the image has no text.\n\n"
                                . "[DESCRIPTION]\n"
                                . "Brief description (1-3 sentences) in the same language as any visible text, "
                                . "or Spanish if there is none. Cover what is shown: objects, people, "
                                . "screenshots, charts, scenes, document type. Omit this section only if "
                                . "the image is purely text and a description adds nothing.\n\n"
                                . "Output only these two sections with their headers. No other commentary.",
                        ],
                    ],
                ]],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                'Anthropic OCR-Image API error: ' . $response->status() . ' ' . $response->body()
            );
        }

        $text = '';
        foreach (($response->json('content') ?? []) as $block) {
            if (($block['type'] ?? null) === 'text') {
                $text .= ($block['text'] ?? '') . "\n";
            }
        }

        return $text;
    }

    protected function extractWithOpenAi(string $base64, string $mime): string
    {
        $apiKey = $this->apiKey
            ?? config('laracrate.ocr.openai.api_key')
            ?: env('LARACRATE_OPENAI_API_KEY')
            ?: env('OPENAI_API_KEY');

        if (! $apiKey) {
            throw new RuntimeException('OpenAI API key no configurada.');
        }

        $model = $this->model
            ?? config('laracrate.ocr.openai.model')
            ?: env('LARACRATE_OCR_MODEL', 'gpt-4o-mini');

        $dataUrl = "data:{$mime};base64,{$base64}";

        $response = Http::withToken($apiKey)
            ->timeout($this->timeout)
            ->retry(2, 1000)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model'    => $model,
                'messages' => [[
                    'role'    => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => "Analyze this image and return TWO sections in plain text with these exact headers:\n\n"
                            . "[TEXT]\n"
                            . "All visible text from the image, verbatim. Preserve structure (paragraphs, lists, tables). "
                            . "Leave this section empty if the image has no text.\n\n"
                            . "[DESCRIPTION]\n"
                            . "Brief description (1-3 sentences) in the same language as any visible text, "
                            . "or Spanish if there is none. Cover what is shown: objects, people, "
                            . "screenshots, charts, scenes, document type. Omit this section only if "
                            . "the image is purely text and a description adds nothing.\n\n"
                            . "Output only these two sections with their headers. No other commentary."],
                        ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]],
                    ],
                ]],
                'max_tokens' => 4000,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                'OpenAI OCR-Image API error: ' . $response->status() . ' ' . $response->body()
            );
        }

        return (string) $response->json('choices.0.message.content', '');
    }
}
