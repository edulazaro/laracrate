@once
@script
<script>
    {!! \EduLazaro\Laracrate\Support\UploaderScript::source() !!}

    // Each staged file gets its own key: x-for needs one before the upload queue numbers it.
    let laracrateStaged = 0;

    /**
     * Factory Alpine compartida por laracrate-dropzone (inmediato y deferred) y los
     * dropzones de un archivo (todos los temas: el aspecto es CSS).
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
        uploadedTotal: 0,
        batch: [],

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
                    this.queue.slice(this.cfg.maxFiles).forEach((item) => this.release(item));
                    this.queue = this.queue.slice(0, this.cfg.maxFiles);
                }
            };
            window.addEventListener('laracrate-start-batch', this._onStartBatch);
            window.addEventListener('laracrate-deferred-config', this._onDeferredConfig);

            // Every change of an upload this dropzone handed over: the batch bar, and
            // the count that maxFiles is checked against.
            this._offUploads = window.Laracrate.uploads.on(null, (type, item) => {
                if (!item || !this.queue.some(q => q.id === item.id)) return;
                if (type === 'done') this.uploadedTotal++;
                this.updateBatchProgress();
            });

            // Mounted again for a target whose uploads are still going (or failed)
            // after a navigation: show them again and finish the batch here.
            const scope = this.$wire.uploadScope;
            const carried = window.Laracrate.uploads.itemsFor(scope)
                .filter(i => ['pending', 'uploading', 'error'].includes(i.status));
            if (carried.length) {
                this.queue.push(...carried);
                const running = carried.filter(i => ['pending', 'uploading'].includes(i.status));
                if (running.length) this.watchBatch(running, this.$wire.collection);
            }
        },

        destroy() {
            window.removeEventListener('laracrate-start-batch', this._onStartBatch);
            window.removeEventListener('laracrate-deferred-config', this._onDeferredConfig);
            this._offUploads?.();
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

        updateBatchProgress() {
            const total = this.batch.reduce((sum, i) => sum + (i.size || 0), 0);
            if (!total) return;
            const sent = this.batch.reduce((sum, i) => sum + (['done', 'error', 'canceled'].includes(i.status) ? i.size : (i.loaded || 0)), 0);
            this.batchProgress = Math.round((sent / total) * 100);
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

            const wasIdle = this.queue.every(i => i.status !== 'pending' && i.status !== 'uploading');

            // Staged here; they reach the shared upload queue when the batch starts.
            for (const f of files) {
                this.queue.push({
                    id: null,
                    uid: 'staged-' + (++laracrateStaged),
                    file: f,
                    name: f.name,
                    mime: f.type || 'application/octet-stream',
                    size: f.size,
                    preview: f.type.startsWith('image/') ? URL.createObjectURL(f) : null,
                    status: 'pending',
                    loaded: 0,
                    progress: 0,
                    error: null,
                    retryable: true,
                    queued: false,
                });
            }

            if (!this.cfg.autoStart) return;

            if (wasIdle) {
                this.startBatch();
            } else {
                // Dropped while a batch runs: it joins that batch.
                this.handOver().then((items) => this.batch.push(...items));
            }
        },

        /**
         * Gives the staged files to the shared upload queue (window.Laracrate.uploads),
         * in this component's mode: with its grant, or signed by it now ('presign').
         * From here they no longer depend on the page: a wire:navigate does not stop them.
         */
        async handOver() {
            const staged = this.queue.filter(item => item.status === 'pending' && !item.queued);
            if (!staged.length) return [];

            // Taken now, so a second call while this one waits for the server does not
            // hand them over twice.
            staged.forEach(item => { item.queued = true; });

            const tracked = await window.Laracrate.uploads.forComponent(this.$wire, staged, {
                origin: this.$el,
                collection: this.$wire.collection,
            });

            staged.forEach((item, i) => {
                const at = this.queue.indexOf(item);
                if (at !== -1) this.queue[at] = tracked[i];
            });

            return tracked;
        },

        async startBatch() {
            if (!this.queue.some(i => i.status === 'pending' && !i.queued)) return;
            await this.watchBatch(await this.handOver(), this.$wire.collection);
        },

        /** Waits for a batch, then does what finishing it always did. */
        async watchBatch(items, collection) {
            this.uploading = true;
            this.batch = items;
            this.batchProgress = 0;
            this.updateBatchProgress();

            await window.Laracrate.uploads.waitFor(items);

            // Left the page meanwhile: the upload queue and the tray finished the job,
            // and this dropzone has nothing left to show or announce.
            if (!this.$el.isConnected) return;

            const okCount  = this.batch.filter(i => i.status === 'done').length;
            const errCount = this.batch.filter(i => i.status === 'error').length;
            this.batch = [];

            this.uploading = false;
            this.batchProgress = 0;

            // Announced in the browser now (it used to go through the server).
            if (window.Livewire) {
                window.Livewire.dispatch('laracrate-batch-completed', { collection, ok: okCount, error: errCount });
            }

            if (!this.cfg.persistQueue) {
                setTimeout(() => {
                    // The fade-out works on local copies: the items themselves belong to
                    // the shared upload queue, where they stay 'done' for the tray.
                    this.queue = this.queue.map(i => i.status === 'done'
                        ? { ...Alpine.raw(i), status: 'fade', queued: false }
                        : i);
                    setTimeout(() => {
                        this.queue = this.queue.filter(i => i.status !== 'fade');
                    }, 300);
                }, 1200);
            }
        },

        retryItem(i) {
            const item = this.queue[i];
            if (item && item.queued) window.Laracrate.uploads.retry(item);
        },

        /** Out of this dropzone, and out of the shared queue if it was handed over. */
        release(item) {
            if (item.preview) URL.revokeObjectURL(item.preview);
            if (item.queued) window.Laracrate.uploads.dismiss(item);
        },

        removeItem(i) {
            const item = this.queue[i];
            if (!item) return;
            this.release(item);
            this.queue.splice(i, 1);
        },

        clearQueue() {
            this.queue.forEach(item => this.release(item));
            this.queue = [];
        },
    });
</script>
@endscript
@endonce
