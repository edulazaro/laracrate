import { forwardRef, useImperativeHandle, useRef, useState } from 'react';
import Icon from './Icon.jsx';
import { rootProps, useUploader } from './useUploader.js';
import { fill, labelsFor } from '../core/labels.js';
import { fileSize, megabytes } from '../core/format.js';

/**
 * Several files, straight to the bucket: the React side of laracrate-dropzone, with
 * the same markup, so laracrate.css and its themes apply as they are.
 *
 *   <Dropzone target="venue.gallery" params={{ venue: venue.id }} onUploaded={file => ...} />
 *   <Dropzone target="case.documents" params={{ case: id }} deferred layout="list" theme="studio" ref={dropzone} />
 *
 * `target` is one the app declared with UploadTargets::define: the component asks it
 * for a grant and the collection's rules. Instead, `grant` takes an UploadGrant the
 * app issued ({ grant, scope }), or a function that asks the app for one for the files
 * picked. With `deferred` the files wait for the Upload button, or for
 * ref.current.startBatch() from the page.
 */
const Dropzone = forwardRef(function Dropzone(props, ref) {
    const {
        grant, target, params, deferred = false, multiple = true, maxFiles = null, layout = 'grid', hideActions = false,
        persistQueue = false, accept, maxSizeKb, icon = 'upload', locale = 'en', labels: own = {},
        onUploaded, onRejected, onBatchCompleted, onMaxFiles,
    } = props;
    const labels = labelsFor(locale, own);
    const input = useRef(null);
    const [over, setOver] = useState(false);

    const uploader = useUploader({
        grant, target, params, autoStart: !deferred, maxFiles, persistQueue, refusedMessage: labels.refused,
        onUploaded, onRejected, onBatchCompleted, onMaxFiles,
    });
    const queue = uploader.queue;
    useImperativeHandle(ref, () => ({ startBatch: () => queue.startBatch(), clear: () => queue.clear() }), [queue]);

    const view = queue.snapshot();
    const place = uploader.target.snapshot();
    const rules = place.rules ?? {};
    const maxSize = maxSizeKb ?? rules.max_size_kb;
    const pick = () => input.current?.click();
    const drop = (event) => {
        event.preventDefault();
        setOver(false);
        queue.handleFiles(event.dataTransfer.files);
    };

    return (
        <div {...rootProps(props, 'laracrate laracrate-dropzone')} data-deferred={deferred || undefined} aria-busy={!place.ready || undefined}>
            {!place.available ? null : !view.reachedMax ? (
                <div
                    role="button"
                    tabIndex={0}
                    className={`laracrate-drop${over ? ' is-dragover' : ''}`}
                    onDragOver={(event) => { event.preventDefault(); setOver(true); }}
                    onDragLeave={(event) => { event.preventDefault(); setOver(false); }}
                    onDrop={drop}
                    onClick={pick}
                    onKeyDown={(event) => { if (event.key === 'Enter') { event.preventDefault(); pick(); } }}
                >
                    <span className="laracrate-icon"><Icon name={icon} /></span>
                    <p className="laracrate-title">{deferred ? labels.select : labels.upload}</p>
                    <p className="laracrate-subtitle">{labels.drag_or_click.toLowerCase()}</p>
                    {maxSize && <p className="laracrate-hint">{fill(labels.max_size_capital, { size: megabytes(maxSize) })}</p>}
                    <input
                        ref={input}
                        type="file"
                        className="laracrate-hidden"
                        multiple={multiple && (maxFiles === null || maxFiles > 1)}
                        accept={accept ?? rules.accept ?? '*/*'}
                        onChange={(event) => { queue.handleFiles(event.target.files); event.target.value = ''; }}
                    />
                </div>
            ) : (
                <div className="laracrate-drop is-full">
                    <span className="laracrate-icon"><Icon name="check" /></span>
                    <p className="laracrate-title">{labels.max_reached_title}</p>
                    <p className="laracrate-subtitle">{`${maxFiles ?? '∞'} ${labels.max_reached_unit}`}</p>
                </div>
            )}

            {view.queue.length > 0 && (
                <div className="laracrate-queue">
                    <div className="laracrate-status">
                        <p>
                            {!view.uploading && view.pendingCount > 0 && <span>{`${view.pendingCount} ${labels.in_queue}`}</span>}
                            {view.uploading && <span>{labels.uploading.toLowerCase()}</span>}
                            {!view.uploading && view.pendingCount === 0 && view.doneCount > 0 && (
                                <span>{`${view.doneCount} OK${view.errorCount > 0 ? ` / ${view.errorCount} err` : ''}`}</span>
                            )}
                        </p>
                        {view.uploading && <span>{`${view.batchProgress}%`}</span>}
                    </div>

                    {view.uploading && (
                        <div className="laracrate-progress"><div className="laracrate-progress__bar" style={{ width: `${view.batchProgress}%` }} /></div>
                    )}

                    {layout === 'list' ? (
                        <div className="laracrate-list">
                            {view.queue.map((item) => (
                                <div key={item.key} className="laracrate-row" data-state={item.status}>
                                    {item.preview
                                        ? <img src={item.preview} alt="" className="laracrate-row__thumb" />
                                        : <span className="laracrate-row__icon"><Icon name="file" /></span>}
                                    <p className="laracrate-row__name">{item.name}</p>
                                    <p className="laracrate-row__size">{fileSize(item.size)}</p>
                                    {item.status === 'uploading' && <span className="laracrate-spinner"><Icon name="spinner" /></span>}
                                    {item.status === 'done' && <span className="laracrate-row__done"><Icon name="check" /></span>}
                                    {item.status === 'error' && (
                                        <button type="button" title={item.error || labels.retry} className="laracrate-row__retry"
                                            onClick={(event) => { event.stopPropagation(); queue.retry(item.key); }}><Icon name="retry" /></button>
                                    )}
                                    {item.status === 'pending' && (
                                        <button type="button" title={labels.delete_short} className="laracrate-row__remove"
                                            onClick={(event) => { event.stopPropagation(); queue.remove(item.key); }}><Icon name="close" /></button>
                                    )}
                                </div>
                            ))}
                        </div>
                    ) : (
                        <div className="laracrate-grid">
                            {view.queue.map((item) => (
                                <div key={item.key} className="laracrate-tile" data-state={item.status}>
                                    {item.preview
                                        ? <img src={item.preview} alt="" className="laracrate-tile__preview" />
                                        : <div className="laracrate-tile__placeholder"><Icon name="file" /></div>}
                                    <div className="laracrate-tile__overlay">
                                        {(item.status === 'uploading' || (!deferred && item.status === 'pending')) && (
                                            <span className="laracrate-spinner"><Icon name="spinner" /></span>
                                        )}
                                        {item.status === 'done' && <span><Icon name="check" /></span>}
                                        {item.status === 'error' && (
                                            <button type="button" title={item.error || labels.retry} className="laracrate-tile__retry"
                                                onClick={(event) => { event.stopPropagation(); queue.retry(item.key); }}><Icon name="retry" /></button>
                                        )}
                                    </div>
                                    {deferred && item.status === 'pending' && (
                                        <button type="button" title={labels.delete_short} className="laracrate-tile__remove"
                                            onClick={(event) => { event.stopPropagation(); queue.remove(item.key); }}><Icon name="close" /></button>
                                    )}
                                    <p className="laracrate-tile__name">{item.name}</p>
                                </div>
                            ))}
                        </div>
                    )}

                    {deferred && !hideActions && !view.uploading && view.pendingCount > 0 && (
                        <div className="laracrate-actions">
                            <button type="button" className="laracrate-button" onClick={() => queue.startBatch()}>
                                {labels.submit}
                                {view.pendingCount > 1 && <span className="laracrate-button__count">{` (${view.pendingCount})`}</span>}
                            </button>
                            <button type="button" className="laracrate-link" onClick={() => queue.clear()}>{labels.cancel}</button>
                        </div>
                    )}
                </div>
            )}

            {place.error && <p className="laracrate-error">{place.error}</p>}
        </div>
    );
});

export default Dropzone;
