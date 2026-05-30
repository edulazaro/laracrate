@once
@script
<script>
    /**
     * Factory Alpine compartida por todos los temas de
     * laracrate-dropzone y laracrate-dropzone-deferred.
     *
     * El comportamiento depende de cfg.autoStart:
     *   - true  → cada `handleFiles()` arranca el lote inmediatamente (instant)
     *   - false → los archivos quedan en cola hasta que el usuario llama startBatch() (deferred)
     */
    window.laracrateDropzone = (cfg) => ({
        queue: [],
        uploading: false,
        batchProgress: 0,
        dragOver: false,
        cfg: cfg,
        nextId: 0,
        uploadedTotal: 0,

        /**
         * Disparo externo del lote (deferred): un caller fuera del componente
         * (ej. el botón de un footer de modal) hace
         *   dispatch('laracrate-start-batch', { fileableType, fileableId, collection })
         * y el dropzone que matchee arranca su batch. Centralizado aquí para que
         * TODOS los temas y variantes lo hereden (antes vivía como atributo
         * @laracrate-start-batch.window pegado solo en el tema studio del multi).
         *
         * No afecta a las variantes instant (autoStart=true): suben al
         * soltar/seleccionar y aquí no hay nada pendiente que arrancar.
         */
        init() {
            // Match por fileable + collection. `cfg.collection` puede ser null en
            // algunos temas (p.ej. el multi studio lo deriva del route param); en
            // ese caso no exigimos collection y matcheamos solo por fileable.
            const matches = (d) => {
                if ((d.fileableType ?? null) !== this.cfg.fileableType) return false;
                if (String(d.fileableId ?? '') !== String(this.cfg.fileableId)) return false;
                if (this.cfg.collection != null && (d.collection ?? null) !== this.cfg.collection) return false;
                return true;
            };
            this._onStartBatch = (e) => {
                if (matches(e.detail || {})) this.startBatch();
            };
            this._onDeferredConfig = (e) => {
                const d = e.detail || {};
                if (!matches(d)) return;
                const m = d.maxFiles;
                this.cfg.maxFiles = (m === null || m === undefined) ? null : parseInt(m, 10);
                if (this.cfg.maxFiles !== null && this.queue.length > this.cfg.maxFiles) {
                    this.queue = this.queue.slice(0, this.cfg.maxFiles);
                }
            };
            window.addEventListener('laracrate-start-batch', this._onStartBatch);
            window.addEventListener('laracrate-deferred-config', this._onDeferredConfig);
        },

        destroy() {
            window.removeEventListener('laracrate-start-batch', this._onStartBatch);
            window.removeEventListener('laracrate-deferred-config', this._onDeferredConfig);
        },

        get pendingCount() { return this.queue.filter(i => i.status === 'pending').length; },
        get doneCount()    { return this.queue.filter(i => i.status === 'done').length; },
        get errorCount()   { return this.queue.filter(i => i.status === 'error').length; },
        get activeCount()  { return this.queue.filter(i => ['pending', 'uploading', 'done'].includes(i.status)).length; },
        get reachedMax()   {
            // cfg.maxFiles: null = sin límite, 0 = cero más archivos aceptados.
            // Truthy check trataría 0 como "sin límite", así que comparamos contra null explícitamente.
            if (this.cfg.maxFiles === null || this.cfg.maxFiles === undefined) return false;
            return (this.uploadedTotal + this.queue.filter(i => ['pending', 'uploading'].includes(i.status)).length) >= this.cfg.maxFiles;
        },

        getCsrf() {
            return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
        },

        handleFiles(fileList) {
            let files = Array.from(fileList);
            if (!files.length) return;

            // Respeta maxFiles: cuenta uploadedTotal (persistente) + cola activa.
            // null = sin límite. 0 o más = cap explícito (incluido 0 = nada más).
            const max = this.cfg.maxFiles;
            if (max !== null && max !== undefined) {
                const activeInQueue = this.queue.filter(i => ['pending', 'uploading'].includes(i.status)).length;
                const used = this.uploadedTotal + activeInQueue;
                const free = Math.max(0, max - used);
                if (free === 0) {
                    window.dispatchEvent(new CustomEvent('laracrate-max-files', { detail: { max } }));
                    return;
                }
                if (files.length > free) {
                    window.dispatchEvent(new CustomEvent('laracrate-max-files', { detail: { max, rejected: files.length - free } }));
                    files = files.slice(0, free);
                }
            }

            const wasIdle = this.queue.length === 0
                || this.queue.every(i => i.status !== 'pending' && i.status !== 'uploading');

            for (const f of files) {
                this.queue.push({
                    id: ++this.nextId,
                    file: f,
                    name: f.name,
                    mime: f.type || 'application/octet-stream',
                    size: f.size,
                    preview: f.type.startsWith('image/') ? URL.createObjectURL(f) : null,
                    status: 'pending',
                    key: null,
                    error: null,
                });
            }

            if (this.cfg.autoStart && wasIdle) {
                this.startBatch();
            }
        },

        async startBatch() {
            const pending = this.queue.filter(i => i.status === 'pending');
            if (!pending.length) return;

            this.uploading = true;
            this.batchProgress = 0;

            const total = pending.length;
            let completed = 0;
            let okCount   = 0;
            let errCount  = 0;

            for (const item of pending) {
                if (item.status !== 'pending') continue;

                const ok = await this.uploadOne(item);
                if (ok) okCount++; else errCount++;
                completed++;
                this.batchProgress = Math.round((completed / total) * 100);
            }

            this.uploading = false;
            this.batchProgress = 0;

            try { await this.$wire.batchCompleted(okCount, errCount); } catch (e) {}

            if (!this.cfg.persistQueue) {
                setTimeout(() => {
                    for (const item of this.queue) {
                        if (item.status === 'done') item.status = 'fade';
                    }
                    setTimeout(() => {
                        this.queue = this.queue.filter(i => i.status !== 'fade');
                    }, 300);
                }, 1200);
            }
        },

        async uploadOne(item) {
            try {
                item.status = 'uploading';
                item.error  = null;

                const presignRes = await fetch(this.cfg.presignUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': this.getCsrf(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        disk: this.cfg.disk,
                        mime: item.mime,
                        file_name: item.name,
                        max_size_kb: this.cfg.maxSizeKb,
                        fileable_type: this.cfg.fileableType,
                        fileable_id: this.cfg.fileableId,
                        collection: this.cfg.collection,
                    }),
                });

                if (!presignRes.ok) throw new Error(`presign ${presignRes.status}`);
                const presign = await presignRes.json();

                const putRes = await fetch(presign.url, {
                    method: presign.method || 'PUT',
                    headers: presign.headers || { 'Content-Type': item.mime },
                    body: item.file,
                });
                if (!putRes.ok) throw new Error(`put ${putRes.status}`);

                item.key = presign.key;
                const fileId = await this.$wire.registerUploaded(item.key, item.name, item.mime, item.size);
                if (!fileId) throw new Error('register');

                item.status = 'done';
                this.uploadedTotal++;
                return true;
            } catch (e) {
                console.error('Laracrate dropzone:', e, item);
                item.status = 'error';
                item.error  = (e && e.message) ? e.message : 'error';
                return false;
            }
        },

        async retryItem(i) {
            const item = this.queue[i];
            if (!item) return;
            item.status = 'pending';
            await this.uploadOne(item);
        },

        removeItem(i) {
            const item = this.queue[i];
            if (!item) return;
            if (item.preview) URL.revokeObjectURL(item.preview);
            this.queue.splice(i, 1);
        },

        clearQueue() {
            for (const item of this.queue) {
                if (item.preview) URL.revokeObjectURL(item.preview);
            }
            this.queue = [];
        },
    });
</script>
@endscript
@endonce
