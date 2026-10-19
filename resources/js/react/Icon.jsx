import { icon } from '../core/icons.js';

/** One of the components' icons, as partials/icon.blade.php draws it. */
export default function Icon({ name }) {
    const { fill, stroke, strokeWidth, inner } = icon(name);

    return (
        <svg
            viewBox="0 0 24 24"
            fill={fill}
            stroke={stroke ?? undefined}
            strokeWidth={strokeWidth ?? undefined}
            aria-hidden="true"
            dangerouslySetInnerHTML={{ __html: inner }}
        />
    );
}
