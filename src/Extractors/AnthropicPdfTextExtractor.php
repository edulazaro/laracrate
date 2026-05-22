<?php

namespace EduLazaro\Laracrate\Extractors;

use EduLazaro\Laracrate\Contracts\TextExtractor;
use EduLazaro\Laracrate\Models\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * OCR de PDFs usando Anthropic Claude (messages API con PDF nativo).
 *
 * Útil como fallback de PdfTextExtractor (smalot/pdfparser) cuando el PDF
 * es escaneado y no tiene texto extraíble. Claude acepta PDFs base64 en
 * el campo `content[].source` y devuelve el texto literal de las páginas.
 *
 * Coste estimado (claude-haiku-4-5): ~$0.004 por PDF de 10 páginas.
 * Tiempo: 5-15 segundos según tamaño.
 *
 * Config:
 *   ANTHROPIC_API_KEY        (env, required)
 *   ANTHROPIC_OCR_MODEL      (opcional, default 'claude-haiku-4-5')
 */
class AnthropicPdfTextExtractor implements TextExtractor
{
    public function __construct(
        protected ?string $apiKey = null,
        protected string $model = 'claude-haiku-4-5',
        protected string $endpoint = 'https://api.anthropic.com/v1/messages',
        protected int $timeout = 180,
        protected int $maxTokens = 8000,
    ) {
        $this->apiKey ??= config('laracrate.ocr.anthropic.api_key')
            ?: env('ANTHROPIC_API_KEY');

        $this->model = config('laracrate.ocr.anthropic.model', $this->model);
    }

    public function supports(File $file): bool
    {
        return $file->mime_type === 'application/pdf'
            || strtolower($file->extension ?? '') === 'pdf';
    }

    public function extract(File $file): string
    {
        if (! $this->apiKey) {
            throw new RuntimeException(
                'Anthropic API key no configurada (ANTHROPIC_API_KEY o laracrate.ocr.anthropic.api_key).'
            );
        }

        $bytes = Storage::disk($file->disk)->get($file->path);
        if ($bytes === null || $bytes === false) {
            throw new RuntimeException("Anthropic OCR: no se pudo leer el archivo {$file->path}");
        }

        $base64 = base64_encode($bytes);

        $response = Http::withHeaders([
            'x-api-key'         => $this->apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])
            ->timeout($this->timeout)
            ->retry(2, 1000)
            ->post($this->endpoint, [
                'model'      => $this->model,
                'max_tokens' => $this->maxTokens,
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

        $content = $response->json('content') ?? [];
        $text = '';

        foreach ($content as $block) {
            if (($block['type'] ?? null) === 'text') {
                $text .= ($block['text'] ?? '') . "\n";
            }
        }

        return trim($text);
    }
}
