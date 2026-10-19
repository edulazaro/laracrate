/** A size in bytes as the components show it: B, KB or MB. */
export function fileSize(bytes) {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1048576) return `${(bytes / 1024).toFixed(1)} KB`;

    return `${(bytes / 1048576).toFixed(1)} MB`;
}

/** Megabytes with one decimal, for the "Max. :size MB" hint. */
export function megabytes(kilobytes) {
    return (kilobytes / 1024).toFixed(1);
}
