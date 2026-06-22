<?php

namespace EduLazaro\Laracrate\Extractors;

use EduLazaro\Laracrate\Contracts\TextExtractor;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Support\ExtractedContent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * OCR for images using Vision (Anthropic Claude or OpenAI). For scanned
 * documents saved as JPG/PNG, photos of signed contracts, screenshots, etc.
 *
 * Same provider switching as OcrPdfTextExtractor: the app chooses via
 * `LARACRATE_OCR_PROVIDER`.
 *
 * Estimated cost per image:
 *   - Anthropic Claude Haiku 4.5: ~$0.0005
 *   - OpenAI gpt-4o-mini:          ~$0.001
 */
class OcrImageTextExtractor implements TextExtractor
{
    /**
     * Create a new image OCR extractor.
     */
    public function __construct(
        protected ?string $provider = null,
        protected ?string $model = null,
        protected ?string $apiKey = null,
        protected int $timeout = 120,
    ) {
        $this->provider ??= config('laracrate.ocr.provider', 'anthropic');
    }

    /**
     * Determine whether this extractor can handle the given file.
     */
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

        // Without an API key for the configured provider, skip (the chain
        // tries the next extractor instead of failing the whole process).
        return $this->hasApiKey();
    }

    /**
     * Determine whether an API key is available for the configured provider.
     */
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

    /**
     * Fallback language name for the image description when no visible text is
     * present to infer it from. Resolves the configured locale ('en', 'es',
     * ...) to a human language name for the prompt. Unknown locales fall back
     * to the raw value so any language still works.
     */
    protected function descriptionLanguage(): string
    {
        $locale = (string) config('laracrate.ocr.locale', 'en');

        $names = [
            'en' => 'English',
            'es' => 'Spanish',
            'fr' => 'French',
            'de' => 'German',
            'it' => 'Italian',
            'pt' => 'Portuguese',
            'ca' => 'Catalan',
            'nl' => 'Dutch',
            'gl' => 'Galician',
            'eu' => 'Basque',
            'ro' => 'Romanian',
            'pl' => 'Polish',
            'cs' => 'Czech',
            'sk' => 'Slovak',
            'hu' => 'Hungarian',
            'el' => 'Greek',
            'sv' => 'Swedish',
            'da' => 'Danish',
            'fi' => 'Finnish',
            'no' => 'Norwegian',
            'is' => 'Icelandic',
            'ru' => 'Russian',
            'uk' => 'Ukrainian',
            'bg' => 'Bulgarian',
            'sr' => 'Serbian',
            'hr' => 'Croatian',
            'tr' => 'Turkish',
            'ar' => 'Arabic',
            'he' => 'Hebrew',
            'fa' => 'Persian',
            'hi' => 'Hindi',
            'bn' => 'Bengali',
            'ur' => 'Urdu',
            'th' => 'Thai',
            'vi' => 'Vietnamese',
            'id' => 'Indonesian',
            'ms' => 'Malay',
            'ja' => 'Japanese',
            'ko' => 'Korean',
            'zh' => 'Chinese',
        ];

        return $names[strtolower($locale)] ?? $locale;
    }

    /**
     * Run OCR on the image and return the extracted content.
     */
    public function extract(File $file): ExtractedContent
    {
        $bytes = Storage::disk($file->disk)->get($file->path);
        if ($bytes === null || $bytes === false) {
            throw new RuntimeException("OCR-Image: could not read {$file->path}");
        }

        $base64 = base64_encode($bytes);
        $mime   = (string) $file->mime_type;

        $raw = match ($this->provider) {
            'openai'    => $this->extractWithOpenAi($base64, $mime),
            'anthropic' => $this->extractWithAnthropic($base64, $mime),
            default     => throw new RuntimeException("Unknown OCR provider: {$this->provider}"),
        };

        // Parse the response into two sections: [TEXT] and [DESCRIPTION]. Each
        // is returned as a separate page with its own `context` so the chunker
        // produces distinct rows (one embedding per section).
        [$ocrText, $description] = $this->parseSections($raw);

        $pages = [];
        if ($ocrText !== '') {
            $pages[] = ['page_number' => 1, 'text' => $ocrText, 'context' => 'text'];
        }
        if ($description !== '') {
            $pages[] = ['page_number' => count($pages) + 1, 'text' => $description, 'context' => 'description'];
        }

        // Defensive fallback: if the model did not respect the headers, dump
        // everything as a single page with no context (legacy behavior).
        if (empty($pages)) {
            $pages[] = ['page_number' => 1, 'text' => trim($raw)];
        }

        return ExtractedContent::fromPages($pages, [
            'extractor' => static::class,
            'provider'  => $this->provider,
        ]);
    }

    /**
     * Parse the model response into `[TEXT]\n...\n[DESCRIPTION]\n...`.
     * Tolerant of model variations (casing, whitespace, a missing section).
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

        // If no headers appear but there is content, assume it is all TEXT.
        if ($text === '' && $description === '' && $raw !== '') {
            $text = $raw;
        }

        return [$text, $description];
    }

    /**
     * Run OCR through the Anthropic Vision API and return the raw response text.
     */
    protected function extractWithAnthropic(string $base64, string $mime): string
    {
        $apiKey = $this->apiKey
            ?? config('laracrate.ocr.anthropic.api_key')
            ?: env('LARACRATE_ANTHROPIC_API_KEY')
            ?: env('ANTHROPIC_API_KEY');

        if (! $apiKey) {
            throw new RuntimeException('Anthropic API key not configured.');
        }

        $model = $this->model
            ?? config('laracrate.ocr.anthropic.model')
            ?: env('LARACRATE_OCR_MODEL', 'claude-haiku-4-5');

        $lang = $this->descriptionLanguage();

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
                                . "or {$lang} if there is none. Cover what is shown: objects, people, "
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

    /**
     * Run OCR through the OpenAI Vision API and return the raw response text.
     */
    protected function extractWithOpenAi(string $base64, string $mime): string
    {
        $apiKey = $this->apiKey
            ?? config('laracrate.ocr.openai.api_key')
            ?: env('LARACRATE_OPENAI_API_KEY')
            ?: env('OPENAI_API_KEY');

        if (! $apiKey) {
            throw new RuntimeException('OpenAI API key not configured.');
        }

        $model = $this->model
            ?? config('laracrate.ocr.openai.model')
            ?: env('LARACRATE_OCR_MODEL', 'gpt-4o-mini');

        $dataUrl = "data:{$mime};base64,{$base64}";

        $lang = $this->descriptionLanguage();

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
                            . "or {$lang} if there is none. Cover what is shown: objects, people, "
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
