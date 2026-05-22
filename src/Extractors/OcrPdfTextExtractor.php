<?php

namespace EduLazaro\Laracrate\Extractors;

use EduLazaro\Laracrate\Contracts\TextExtractor;
use EduLazaro\Laracrate\Models\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * OCR de PDFs usando una API (Anthropic Claude o OpenAI) seleccionable via
 * config. Útil como fallback de PdfTextExtractor (smalot/pdfparser) cuando
 * el PDF es escaneado y no tiene texto extraíble.
 *
 * Ambos providers aceptan el PDF base64 directamente → sin Imagick, sin
 * shell_exec, sin pdftoppm. Cero dependencias del sistema, solo PHP + HTTP.
 *
 * Config:
 *   LARACRATE_OCR_PROVIDER       = 'anthropic' | 'openai' (default: anthropic)
 *   LARACRATE_OCR_MODEL          = (opcional, default según provider)
 *   LARACRATE_ANTHROPIC_API_KEY  = (fallback: ANTHROPIC_API_KEY)
 *   LARACRATE_OPENAI_API_KEY     = (fallback: OPENAI_API_KEY)
 *
 * Coste estimado por PDF de 10 páginas:
 *   - Anthropic Claude Haiku 4.5:  ~$0.004
 *   - OpenAI gpt-4o-mini:          ~$0.005
 */
class OcrPdfTextExtractor implements TextExtractor
{
    public function __construct(
        protected ?string $provider = null,
        protected ?string $model = null,
        protected ?string $apiKey = null,
        protected int $timeout = 180,
    ) {
        $this->provider ??= config('laracrate.ocr.provider', 'anthropic');
    }

    public function supports(File $file): bool
    {
        return $file->mime_type === 'application/pdf'
            || strtolower($file->extension ?? '') === 'pdf';
    }

    public function extract(File $file): string
    {
        $bytes = Storage::disk($file->disk)->get($file->path);
        if ($bytes === null || $bytes === false) {
            throw new RuntimeException("OCR: no se pudo leer el archivo {$file->path}");
        }

        $base64 = base64_encode($bytes);

        return match ($this->provider) {
            'openai'    => $this->extractWithOpenAi($base64, $file),
            'anthropic' => $this->extractWithAnthropic($base64),
            default     => throw new RuntimeException("OCR provider desconocido: {$this->provider}"),
        };
    }

    protected function extractWithAnthropic(string $base64): string
    {
        $apiKey = $this->apiKey
            ?? config('laracrate.ocr.anthropic.api_key')
            ?: env('LARACRATE_ANTHROPIC_API_KEY')
            ?: env('ANTHROPIC_API_KEY');

        if (! $apiKey) {
            throw new RuntimeException(
                'Anthropic API key no configurada (LARACRATE_ANTHROPIC_API_KEY o ANTHROPIC_API_KEY).'
            );
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
                'max_tokens' => 8000,
                'messages'   => [[
                    'role'    => 'user',
                    'content' => [
                        [
                            'type'   => 'document',
                            'source' => [
                                'type'       => 'base64',
                                'media_type' => 'application/pdf',
                                'data'       => $base64,
                            ],
                        ],
                        [
                            'type' => 'text',
                            'text' => 'Extract ALL text from this PDF document verbatim. '
                                . 'Preserve the original structure (paragraphs, lists, tables as markdown). '
                                . 'Do NOT summarize, paraphrase or add commentary. '
                                . 'Output only the extracted text content.',
                        ],
                    ],
                ]],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                'Anthropic OCR API error: ' . $response->status() . ' ' . $response->body()
            );
        }

        $text = '';
        foreach (($response->json('content') ?? []) as $block) {
            if (($block['type'] ?? null) === 'text') {
                $text .= ($block['text'] ?? '') . "\n";
            }
        }

        return trim($text);
    }

    protected function extractWithOpenAi(string $base64, File $file): string
    {
        $apiKey = $this->apiKey
            ?? config('laracrate.ocr.openai.api_key')
            ?: env('LARACRATE_OPENAI_API_KEY')
            ?: env('OPENAI_API_KEY');

        if (! $apiKey) {
            throw new RuntimeException(
                'OpenAI API key no configurada (LARACRATE_OPENAI_API_KEY o OPENAI_API_KEY).'
            );
        }

        $model = $this->model
            ?? config('laracrate.ocr.openai.model')
            ?: env('LARACRATE_OCR_MODEL', 'gpt-4o-mini');

        $filename = $file->original_name ?: ($file->name ?: 'document.pdf');

        $response = Http::withToken($apiKey)
            ->timeout($this->timeout)
            ->retry(2, 1000)
            ->post('https://api.openai.com/v1/responses', [
                'model' => $model,
                'input' => [[
                    'role' => 'user',
                    'content' => [
                        [
                            'type'      => 'input_file',
                            'filename'  => $filename,
                            'file_data' => 'data:application/pdf;base64,' . $base64,
                        ],
                        [
                            'type' => 'input_text',
                            'text' => 'Extract ALL text from this PDF document verbatim. '
                                . 'Preserve the original structure (paragraphs, lists, tables as markdown). '
                                . 'Do NOT summarize, paraphrase or add commentary. '
                                . 'Output only the extracted text content.',
                        ],
                    ],
                ]],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                'OpenAI OCR API error: ' . $response->status() . ' ' . $response->body()
            );
        }

        $text = '';
        foreach (($response->json('output') ?? []) as $output) {
            foreach (($output['content'] ?? []) as $block) {
                if (in_array($block['type'] ?? null, ['output_text', 'text'], true)) {
                    $text .= ($block['text'] ?? '') . "\n";
                }
            }
        }

        // Fallback: algunos modelos exponen `output_text` directamente.
        if ($text === '' && is_string($response->json('output_text'))) {
            $text = $response->json('output_text');
        }

        return trim($text);
    }
}
