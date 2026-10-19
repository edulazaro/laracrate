import { forwardRef, useImperativeHandle, useRef, useState } from 'react';
import Icon from './Icon.jsx';
import { rootProps, useUploadQueue } from './useUploadQueue.js';
import { fill, labelsFor } from '../core/labels.js';
import { megabytes } from '../core/format.js';
import { currentFile, singleState } from '../core/single.js';

/**
 * One file, straight to the bucket: the React side of laracrate-dropzone-single, with
 * the same markup. An upload replaces the current file, so the app issues the grant
 * with `replace: true`, and passes the new file back once it is saved:
 *
 *   <DropzoneSingle grant={grant} file={cover} kind="image" onUploaded={() => reloadCover()} onRemove={deleteCover} />
 *
 * `file` is the app's own JSON of the current one ({ url, name, size, extension?, type? });
 * `onRemove` shows the delete button. With `deferred` the file waits for the Upload
 * button, or for ref.current.startBatch() from the page.
 */
const DropzoneSingle = forwardRef(function DropzoneSingle(props, ref) {
    const {
        grant, file = null, kind = 'mixed', deferred = false, hideActions = false, accept = '*/*', maxSizeKb = null,
        extensions = [], icon = null, locale = 'en', labels: own = {},
        onUploaded, onRejected, onBatchCompleted, onRemove,
    } = props;
    const labels = labelsFor(locale, own);
    const input = useRef(null);
    const [over, setOver] = useState(false);

    const queue = useUploadQueue({
        grant, autoStart: !deferred, maxFiles: null, persistQueue: false, refusedMessage: labels.refused,
        onUploaded, onRejected, onBatchCompleted,
    });
    useImperativeHandle(ref, () => ({ startBatch: () => queue.startBatch(), clear: () => queue.clear() }), [queue]);

    const view = queue.snapshot();
    const { item, staged, failed, busy, progress } = singleState(view);
    const existing = currentFile(file, kind);
    const pick = () => input.current?.click();
    const take = (files) => queue.handleFiles(Array.from(files || []).slice(0, 1));
    const remove = () => {
        if (window.confirm(labels.delete_confirm)) onRemove(file);
    };
    const picker = (
        <input
            ref={input}
            type="file"
            className="laracrate-hidden"
            accept={accept}
            onChange={(event) => { take(event.target.files); event.target.value = ''; }}
        />
    );

    return (
        <div {...rootProps(props, 'laracrate laracrate-single')}>
            {!item && existing && (
                <div className="laracrate-card">
                    {existing.kind === 'image' && <img src={existing.url} alt={existing.name} className="laracrate-card__thumb" />}
                    {existing.kind === 'video' && <video controls className="laracrate-card__thumb is-video"><source src={existing.url} /></video>}
                    {existing.kind === 'audio' && <audio controls className="laracrate-card__audio"><source src={existing.url} /></audio>}
                    {!['image', 'video', 'audio'].includes(existing.kind) && <span className="laracrate-icon"><Icon name="document" /></span>}
                    <div className="laracrate-card__body">
                        <p className="laracrate-name">{existing.name}</p>
                        <p className="laracrate-meta">{`${(existing.size / 1024).toFixed(1)} KB · ${existing.extension}`}</p>
                    </div>
                    <button type="button" onClick={pick} className="laracrate-icon-button is-accent" title={labels.replace}><Icon name="upload" /></button>
                    <a href={existing.url} target="_blank" rel="noreferrer" className="laracrate-icon-button" title={labels.view}><Icon name="eye" /></a>
                    {onRemove && (
                        <button type="button" onClick={remove} className="laracrate-icon-button is-danger" title={labels.delete_short}><Icon name="close" /></button>
                    )}
                    {picker}
                </div>
            )}

            {!item && !existing && (
                <div
                    role="button"
                    tabIndex={0}
                    className={`laracrate-drop is-compact${over ? ' is-dragover' : ''}`}
                    onDragOver={(event) => { event.preventDefault(); setOver(true); }}
                    onDragLeave={(event) => { event.preventDefault(); setOver(false); }}
                    onDrop={(event) => { event.preventDefault(); setOver(false); take(event.dataTransfer.files); }}
                    onClick={pick}
                    onKeyDown={(event) => { if (event.key === 'Enter') { event.preventDefault(); pick(); } }}
                >
                    <span className="laracrate-icon"><Icon name={icon ?? kind} /></span>
                    <p className="laracrate-title">{labels.select}</p>
                    <p className="laracrate-subtitle">{labels.drag_or_click.toLowerCase()}</p>
                    {(maxSizeKb || extensions.length > 0) && (
                        <p className="laracrate-hint">
                            {[maxSizeKb && fill(labels.max_size_capital, { size: megabytes(maxSizeKb) }), extensions.map((extension) => extension.toUpperCase()).join(', ')]
                                .filter(Boolean).join(' · ')}
                        </p>
                    )}
                    {picker}
                </div>
            )}

            {staged && (
                <div className="laracrate-card">
                    <span className="laracrate-icon"><Icon name="file" /></span>
                    <div className="laracrate-card__body">
                        <p className="laracrate-label">{labels.pending}</p>
                        <p className="laracrate-name">{item.name}</p>
                    </div>
                    {!hideActions && (
                        <>
                            <button type="button" onClick={() => queue.startBatch()} className="laracrate-button is-small">{labels.submit}</button>
                            <button type="button" onClick={() => queue.clear()} className="laracrate-icon-button is-danger" title={labels.cancel}><Icon name="close" /></button>
                        </>
                    )}
                </div>
            )}

            {busy && (
                <div className="laracrate-card is-busy">
                    <span className="laracrate-spinner"><Icon name="spinner" /></span>
                    <div className="laracrate-card__body">
                        <p className="laracrate-name">{item.name}</p>
                        <div className="laracrate-progress"><div className="laracrate-progress__bar" style={{ width: `${progress}%` }} /></div>
                    </div>
                </div>
            )}

            {failed && (
                <div className="laracrate-card">
                    <span className="laracrate-icon"><Icon name="file" /></span>
                    <div className="laracrate-card__body">
                        <p className="laracrate-name">{item.name}</p>
                        <p className="laracrate-meta"><span className="is-error">{item.error || labels.failed}</span></p>
                    </div>
                    {item.retryable !== false && (
                        <button type="button" onClick={() => queue.retry(item.key)} className="laracrate-icon-button" title={labels.retry}><Icon name="retry" /></button>
                    )}
                    <button type="button" onClick={() => queue.clear()} className="laracrate-icon-button is-danger" title={labels.cancel}><Icon name="close" /></button>
                </div>
            )}
        </div>
    );
});

export default DropzoneSingle;
