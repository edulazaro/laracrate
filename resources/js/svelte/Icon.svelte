<!-- One of the components' icons, as partials/icon.blade.php draws it. -->
<script>
    import { icon } from '../core/icons.js';

    let { name } = $props();
    const svg = $derived(icon(name));
</script>

<svg viewBox="0 0 24 24" fill={svg.fill} stroke={svg.stroke} stroke-width={svg.strokeWidth} aria-hidden="true">{@html svg.inner}</svg>
