# Changelog

All notable changes to Laracrate are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-06-22

First stable release.

### Added

- Polymorphic file storage through the `HasFiles` trait, with three orthogonal morphs per file: `fileable`, `creator` / `owner`, and `tenant`.
- Direct uploads to Cloudflare R2 and AWS S3: presigned single-part PUT and multipart for large files, with a local-driver fallback for development.
- Asynchronous processing pipeline (`ProcessFileJob` running `ProcessFileAction`) with steps ordered by priority, extensible globally, per collection, and per model.
- Image variants and optimization, per-variant watermarking, video transcoding and preview frames, and PDF preview rasterization (`pdftoppm` or Imagick).
- Three access modes per collection (`public`, `signed`, `stream`) with audit and viewer binding, plus at-rest encryption for sensitive collections.
- Text extraction, chunking, and vector embeddings, with a pluggable `ChunkStore` (MySQL or Meilisearch) for keyword, semantic, and hybrid search.
- Bundled extractors: native PDF, OCR for scanned PDFs and images, audio and video transcription, and plain text.
- Folders (`HasFolders`), file slots, multi-tenant buckets, and storage usage accounting (`UsageReporter`).
- Optional Livewire upload components (6) across 11 themes and 2 layouts, fully publishable.
- Artisan commands: `laracrate:abort-stale-multipart`, `laracrate:purge-expired`, and `laracrate:recompute-usage`.

[1.0.0]: https://github.com/edulazaro/laracrate/releases/tag/1.0.0
