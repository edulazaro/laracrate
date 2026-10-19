/**
 * How a component looks, for this rendering only, as HasAppearance does for the
 * Livewire ones: the theme, classes, and variables for its root. The look itself is
 * resources/css/laracrate.css, where a theme only sets variables.
 */
const RADIUS = {
    none: '0', sm: '.125rem', md: '.375rem', lg: '.5rem', xl: '.75rem', '2xl': '1rem', '3xl': '1.5rem', full: '9999px',
};
const BORDERS = ['border-width', 'drop-border-width', 'card-border', 'tile-border', 'icon-button-border'];
const SHADOWS = ['shadow', 'drop-shadow', 'drop-hover-shadow', 'dragover-shadow', 'card-shadow', 'button-shadow', 'button-hover-shadow'];

/**
 * @param {{theme?: string, rounded?: string, bordered?: boolean, shadow?: boolean, size?: string, className?: string}} props
 * @param {string} roundedVariable what `rounded` rounds: the whole component, or only the preview of an uploader
 * @returns {{theme: string|null, classes: string, vars: Record<string, string>}}
 */
export function appearance(props = {}, roundedVariable = '--wire-laracrate-radius') {
    const vars = {};

    if (props.rounded && RADIUS[props.rounded] !== undefined) {
        vars[roundedVariable] = RADIUS[props.rounded];
    }

    if (props.bordered === false) {
        BORDERS.forEach((border) => { vars[`--wire-laracrate-${border}`] = '0'; });
    }

    if (props.shadow === true) {
        vars['--wire-laracrate-shadow'] = '0 1px 3px rgb(0 0 0 / .1), 0 1px 2px rgb(0 0 0 / .06)';
    }

    if (props.shadow === false) {
        SHADOWS.forEach((shadow) => { vars[`--wire-laracrate-${shadow}`] = 'none'; });
    }

    return {
        theme: props.theme ?? null,
        classes: [['sm', 'lg'].includes(props.size) ? `is-${props.size}` : null, props.className].filter(Boolean).join(' '),
        vars,
    };
}

/** The variables plus the app's own inline style, as a style attribute string. */
export function styleString(vars, style = '') {
    const own = Object.entries(vars).map(([name, value]) => `${name}: ${value}`).join('; ');

    return [own, style].filter(Boolean).join('; ');
}
