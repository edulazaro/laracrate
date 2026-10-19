import { forwardRef, useImperativeHandle, useRef, useState } from 'react';
import Icon from './Icon.jsx';
import { rootProps, useUploadQueue } from './useUploadQueue.js';
import { fill, labelsFor } from '../core/labels.js';
import { megabytes } from '../core/format.js';
import { currentFile, singleState } from '../core/single.js';

/**
 * One file shown big, an avatar or a logo: the React side of laracrate-uploader, with
 * the same markup and its two layouts, `row` and `portrait`. An upload replaces the
 * current file, so the app issues the grant with `replace: true`, and passes the new
 * file back once it is saved:
 *
 *   <Uploader grant={grant} file={avatar} layout="portrait" rounded="full" onUploaded={reloadAvatar} onRemove={deleteAvatar} />
 *
 * `file` is the app's own JSON of the current one ({ url, preview?, name, size,
 * processing_status? }); while it is pending or processing the app passes it again
 * when it changes. `rounded` rounds the preview, not the card. With `deferred` the
 * file waits for the Upload button, or for ref.current.startBatch() from the page.
 */
const Uploader = forwardRef(function Uploader(props, ref) {
    const {
        grant, file = null, kind = 'image', layout = 'row', deferred = false, accept = '*/*', maxSizeKb = null,
        locale = 'en', labels: own = {}, onUploaded, onRejected, onRemove,
    } = props;
    const labels = labelsFor(locale, own);
    const input = useRef(null);
    const [over, setOver] = useState(false);

    const queue = useUploadQueue({
        grant, autoStart: !deferred, maxFiles: null, persistQueue: false, refusedMessage: labels.refused,
        onUploaded, onRejected,
    });
    useImperativeHandle(ref, () => ({ startBatch: () => queue.startBatch(), clear: () => queue.clear() }), [queue]);

    const view = queue.snapshot();
    const { item, staged, failed, busy, progress } = singleState(view);
    const existing = currentFile(file, kind);
    const pick = () => input.current?.click();
    const take = (files) => {
        if (failed) queue.clear();
        queue.handleFiles(Array.from(files || []).slice(0, 1));
    };
    const drop = (event) => {
        event.preventDefault();
        setOver(false);
        take(event.dataTransfer.files);
    };
    const dragging = {
        onDragOver: (event) => { event.preventDefault(); setOver(true); },
        onDragLeave: (event) => { event.preventDefault(); setOver(false); },
        onDrop: drop,
    };
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
    const media = (src) => (
        <div className="laracrate-uploader__media">
            {src
                ? <img src={src} alt="" className="laracrate-uploader__image" />
                : <span className="laracrate-uploader__placeholder"><Icon name="file" /></span>}
            {!deferred && over && <div className="laracrate-uploader__drop-hint">{labels.drop_to_replace.toLowerCase()}</div>}
        </div>
    );
    const base = `laracrate laracrate-uploader is-${layout === 'portrait' ? 'portrait' : 'row'}`;

    return (
        <div {...rootProps(props, base, '--wire-laracrate-preview-radius')}>
            {staged && (
                <div className="laracrate-uploader__card is-staged">
                    {media(item.preview)}
                    <div className="laracrate-uploader__body">
                        <p className="laracrate-label">{labels.pending}</p>
                        <p className="laracrate-name">{item.name}</p>
                        <p className="laracrate-meta">{`${Math.round(item.size / 1024)} KB`}</p>
                    </div>
                    <div className="laracrate-uploader__actions">
                        <button type="button" onClick={() => queue.startBatch()} className="laracrate-button">{labels.submit}</button>
                        <button type="button" onClick={() => queue.clear()} title={labels.cancel} className="laracrate-icon-button"><Icon name="close" /></button>
                    </div>
                </div>
            )}

            {busy && (
                <div className="laracrate-uploader__card is-busy">
                    {media(item.preview)}
                    <div className="laracrate-uploader__body">
                        <p className="laracrate-label">{labels.file_label}</p>
                        <p className="laracrate-name">{item.name}</p>
                        <p className="laracrate-meta">
                            {`${Math.round(item.size / 1024)} KB · `}
                            <span className="laracrate-pulse">{`${labels.uploading.toLowerCase()} ${progress}%`}</span>
                        </p>
                    </div>
                </div>
            )}

            {!staged && !busy && existing && (
                <div className="laracrate-uploader__card" {...(deferred ? {} : dragging)}>
                    {media(existing.preview)}
                    <div className="laracrate-uploader__body">
                        <p className="laracrate-label">{labels.file_label}</p>
                        <p className="laracrate-name">{existing.name}</p>
                        <p className="laracrate-meta">
                            {`${Math.round(existing.size / 1024)} KB`}
                            {['pending', 'processing'].includes(existing.status) && (
                                <>{' · '}<span className="laracrate-pulse">{labels.processing.toLowerCase()}</span></>
                            )}
                            {existing.status === 'failed' && <>{' · '}<span className="is-error">{labels.failed.toLowerCase()}</span></>}
                        </p>
                    </div>
                    <div className="laracrate-uploader__actions">
                        {!deferred && (
                            <button type="button" onClick={pick} className="laracrate-button laracrate-uploader__replace">{labels.replace}</button>
                        )}
                        {onRemove && (
                            <button type="button" onClick={remove} title={labels.delete_short} className="laracrate-icon-button is-danger"><Icon name="trash" /></button>
                        )}
                    </div>
                    {!deferred && picker}
                </div>
            )}

            {!staged && !busy && !existing && (
                <div
                    {...dragging}
                    onClick={pick}
                    onKeyDown={(event) => { if (event.key === 'Enter') { event.preventDefault(); pick(); } }}
                    role="button"
                    tabIndex={0}
                    className={`laracrate-drop laracrate-uploader__drop${over ? ' is-dragover' : ''}`}
                >
                    <span className="laracrate-icon"><Icon name="upload" /></span>
                    <div className="laracrate-uploader__drop-body">
                        <p className="laracrate-title">{deferred ? labels.select : labels.upload}</p>
                        <p className="laracrate-subtitle">{labels.drag_or_click.toLowerCase()}</p>
                        {maxSizeKb && <p className="laracrate-hint">{fill(labels.max_size_capital, { size: megabytes(maxSizeKb) })}</p>}
                    </div>
                    <span className="laracrate-button is-small laracrate-uploader__choose">{labels.choose}</span>
                    {picker}
                </div>
            )}

            {failed && <p className="laracrate-error">{item.error || labels.failed}</p>}
        </div>
    );
});

export default Uploader;
