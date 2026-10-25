const DATE_OPTIONS: Intl.DateTimeFormatOptions = {
    weekday: 'short',
    day: '2-digit',
    month: 'short',
    year: 'numeric',
};

const TIME_OPTIONS: Intl.DateTimeFormatOptions = {
    hour: '2-digit',
    minute: '2-digit',
};

/** "Mon 02 Jan 2026" */
export function shortDate(iso: string | null | undefined): string {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleDateString(undefined, DATE_OPTIONS);
}

/** "14:32" */
export function shortTime(iso: string | null | undefined): string {
    if (!iso) {
        return '';
    }

    return new Date(iso).toLocaleTimeString(undefined, TIME_OPTIONS);
}

/** "Mon 02 Jan 2026, 14:32" */
export function fullDate(iso: string | null | undefined): string {
    if (!iso) {
        return '—';
    }

    return `${shortDate(iso)}, ${shortTime(iso)}`;
}

/** "42.3 km" */
export function km(value: number | null | undefined): string {
    return value !== null && value !== undefined ? value.toFixed(1) + ' km' : '—';
}

/** "199 W" */
export function watts(value: number | null | undefined): string {
    return value !== null && value !== undefined ? Math.round(value) + ' W' : '—';
}

/** "126 bpm" */
export function bpm(value: number | null | undefined): string {
    return value !== null && value !== undefined ? Math.round(value) + ' bpm' : '—';
}

/** "4.1 W/kg" */
export function wkg(value: number | null | undefined): string {
    return value !== null && value !== undefined ? value.toFixed(1) + ' W/kg' : '—';
}
