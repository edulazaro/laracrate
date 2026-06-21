<?php

namespace EduLazaro\Laracrate\Extractors;

use EduLazaro\Laracrate\Contracts\TextExtractor;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Support\ExtractedContent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * OCR for PDFs using an API (Anthropic Claude or OpenAI) selectable via config.
 * Useful as a fallback for PdfTextExtractor (smalot/pdfparser) when the PDF is
 * scanned and has no extractable text.
 *
 * Both providers accept the base64 PDF directly: no Imagick, no shell_exec, no
 * pdftoppm. Zero system dependencies, just PHP + HTTP.
 *
 * Config:
 *   LARACRATE_OCR_PROVIDER       = 'anthropic' | 'openai' (default: anthropic)
 *   LARACRATE_OCR_MODEL          = (optional, default depends on provider)
 *   LARACRATE_ANTHROPIC_API_KEY  = (fallback: ANTHROPIC_API_KEY)
 *   LARACRATE_OPENAI_API_KEY     = (fallback: OPENAI_API_KEY)
 *
 * Estimated cost per 10-page PDF:
 *   - Anthropic Claude Haiku 4.5:  ~$0.004
 *   - OpenAI gpt-4o-mini:          ~$0.005
 */
class OcrPdfTextExtractor implements TextExtractor
{
    /**
     * Create a new PDF OCR extractor.
     */
    public function __construct(
        protected ?string $provider = null,
        protected ?string $model = null,
        protected ?string $apiKey = null,
        protected int $timeout = 180,
    ) {
        $this->provider ??= config('laracrate.ocr.provider', 'anthropic');
    }

    /**
     * Determine whether this extractor can handle the given file.
     */
    public function supports(File $file): bool
    {
        return $file->mime_type === 'application/pdf'
            || strtolower($file->extension ?? '') === 'pdf';
    }

    /**
     * Run OCR on the PDF and return the extracted content.
     */
    public function extract(File $file): ExtractedContent
    {
        $bytes = Storage::disk($file->disk)->get($file->path);
        if ($bytes === null || $bytes === false) {
            throw new RuntimeException("OCR: could not read file {$file->path}");
        }

        $base64 = base64_encode($bytes);

        $raw = match ($this->provider) {
            'openai'    => $this->extractWithOpenAi($base64, $file),
            'anthropic' => $this->extractWithAnthropic($base64),
            default     => throw new RuntimeException("Unknown OCR provider: {$this->provider}"),
        };

        // Try to parse the output as JSON with pages. If the API returned plain
        // (unstructured) text, fall back to single-page.
        $pages = $this->parsePagesFromResponse($raw);

        if (! empty($pages)) {
            return ExtractedContent::fromPages($pages, [
                'extractor' => static::class,
                'provider'  => $this->provider,
            ]);
        }

        return ExtractedContent::singlePage($raw, [
            'extractor' => static::class,
            'provider'  => $this->provider,
        ]);
    }

    /**
     * If the model followed the instructions, the response is JSON with
     * `{pages: [{page_number, text}]}`. If it returned plain text, parse
     * `### Page N` markers as a fallback. If nothing matches, returns [].
     */
    protected function parsePagesFromResponse(string $raw): array
    {
        $trimmed = trim($raw);

        // 1) Pure JSON or inside a code fence.
        $jsonCandidate = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $trimmed);
        $decoded = json_decode($jsonCandidate, true);
        if (is_array($decoded) && isset($decoded['pages']) && is_array($decoded['pages'])) {
            return array_values(array_filter(array_map(function ($p) {
                if (! is_array($p)) return null;
                return [
                    'page_number' => (int) ($p['page_number'] ?? 0),
                    'text'        => (string) ($p['text'] ?? ''),
                ];
            }, $decoded['pages'])));
        }

        // 2) Markdown markers `### Page N` (simple heuristic).
        if (preg_match_all('/^(?:#{1,3}\s*)?Page\s+(\d+)\s*$/mi', $trimmed, $matches, PREG_OFFSET_CAPTURE)) {
            $pages = [];
            for ($i = 0; $i < count($matches[0]); $i++) {
                $pageNum = (int) $matches[1][$i][0];
                $start = $matches[0][$i][1] + strlen($matches[0][$i][0]);
                $end = $matches[0][$i + 1][1] ?? strlen($trimmed);
                $pages[] = [
                    'page_number' => $pageNum,
                    'text'        => trim(substr($trimmed, $start, $end - $start)),
                ];
            }
            return $pages;
        }

        return [];
    }

    /**
     * Run OCR through the Anthropic API and return the raw response text.
     */
    protected function extractWithAnthropic(string $base64): string
    {
        $apiKey = $this->apiKey
            ?? config('laracrate.ocr.anthropic.api_key')
            ?: env('LARACRATE_ANTHROPIC_API_KEY')
            ?: env('ANTHROPIC_API_KEY');

        if (! $apiKey) {
            throw new RuntimeException(
                'Anthropic API key not configured (LARACRATE_ANTHROPIC_API_KEY or ANTHROPIC_API_KEY).'
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
                                . 'Output ONLY a valid JSON object with this exact shape: '
                                . '{"pages":[{"page_number":1,"text":"..."},{"page_number":2,"text":"..."}]}. '
                                . 'No markdown code fences. No commentary. JSON only.',
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

    /**
     * Run OCR through the OpenAI API and return the raw response text.
     */
    protected function extractWithOpenAi(string $base64, File $file): string
    {
        $apiKey = $this->apiKey
            ?? config('laracrate.ocr.openai.api_key')
            ?: env('LARACRATE_OPENAI_API_KEY')
            ?: env('OPENAI_API_KEY');

        if (! $apiKey) {
            throw new RuntimeException(
                'OpenAI API key not configured (LARACRATE_OPENAI_API_KEY or OPENAI_API_KEY).'
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
                                . 'Output ONLY a valid JSON object with this exact shape: '
                                . '{"pages":[{"page_number":1,"text":"..."},{"page_number":2,"text":"..."}]}. '
                                . 'No markdown code fences. No commentary. JSON only.',
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

        // Fallback: some models expose `output_text` directly.
        if ($text === '' && is_string($response->json('output_text'))) {
            $text = $response->json('output_text');
        }

        return trim($text);
    }
}
