<?php

namespace EduLazaro\Laracrate\Extractors;

use EduLazaro\Laracrate\Contracts\TextExtractor;
use EduLazaro\Laracrate\Models\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * OCR de PDFs usando OpenAI Responses API con input_file (PDF nativo).
 *
 * OpenAI acepta PDFs directamente en la Responses API mediante content blocks
 * con type='input_file'. El modelo procesa el PDF y devuelve el texto en
 * texto plano. Es la forma más limpia: sin conversión a imagen, sin Imagick,
 * sin shell_exec.
 *
 * Coste estimado (gpt-4o-mini): ~$0.005 por PDF de 10 páginas.
 * Tiempo: 10-30 segundos según tamaño.
 *
 * Config:
 *   OPENAI_API_KEY            (env, required)
 *   OPENAI_OCR_MODEL          (opcional, default 'gpt-4o-mini')
 */
class OpenAiPdfTextExtractor implements TextExtractor
{
    public function __construct(
        protected ?string $apiKey = null,
        protected string $model = 'gpt-4o-mini',
        protected string $endpoint = 'https://api.openai.com/v1/responses',
        protected int $timeout = 180,
    ) {
        $this->apiKey ??= config('laracrate.ocr.openai.api_key')
            ?: env('OPENAI_API_KEY');

        $this->model = config('laracrate.ocr.openai.model', $this->model);
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
                'OpenAI API key no configurada (OPENAI_API_KEY o laracrate.ocr.openai.api_key).'
            );
        }

        $bytes = Storage::disk($file->disk)->get($file->path);
        if ($bytes === null || $bytes === false) {
            throw new RuntimeException("OpenAI OCR: no se pudo leer el archivo {$file->path}");
        }

        $base64 = base64_encode($bytes);
        $filename = $file->original_name ?: ($file->name ?: 'document.pdf');

        $response = Http::withToken($this->apiKey)
            ->timeout($this->timeout)
            ->retry(2, 1000)
            ->post($this->endpoint, [
                'model' => $this->model,
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

        // Responses API devuelve la salida en `output[].content[].text`.
        $outputs = $response->json('output') ?? [];
        $text = '';

        foreach ($outputs as $output) {
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
