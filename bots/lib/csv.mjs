/**
 * Small CSV helpers for splitting an export into one file per report period (RFC 4180: quotes, doubled quotes,
 * commas and line breaks inside quoted fields).
 */

export function parseCsv(text) {
    const rows = [];
    let row = [];
    let field = '';
    let quoted = false;
    const source = text.charCodeAt(0) === 0xfeff ? text.slice(1) : text;

    for (let i = 0; i < source.length; i++) {
        const ch = source[i];

        if (quoted) {
            if (ch === '"' && source[i + 1] === '"') {
                field += '"';
                i++;
            } else if (ch === '"') {
                quoted = false;
            } else {
                field += ch;
            }
        } else if (ch === '"') {
            quoted = true;
        } else if (ch === ',') {
            row.push(field);
            field = '';
        } else if (ch === '\n' || ch === '\r') {
            if (ch === '\r' && source[i + 1] === '\n') i++;
            row.push(field);
            field = '';
            if (row.length > 1 || row[0] !== '') rows.push(row);
            row = [];
        } else {
            field += ch;
        }
    }
    if (field !== '' || row.length > 0) {
        row.push(field);
        rows.push(row);
    }

    return rows;
}

export function toCsv(rows) {
    const cell = (value) =>
        /[",\r\n]/.test(value) ? `"${value.replace(/"/g, '""')}"` : value;

    return rows.map((row) => row.map(cell).join(',')).join('\n') + '\n';
}

/** The calendar day (YYYY-MM-DD) of a UTC "YYYY-MM-DD HH:mm:ss" time in the given time zone. */
export function localDay(utcText, timeZone) {
    const match =
        /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?/.exec(
            String(utcText).trim(),
        );
    if (!match) return null;

    const date = new Date(
        Date.UTC(
            +match[1],
            +match[2] - 1,
            +match[3],
            +match[4],
            +match[5],
            +(match[6] ?? 0),
        ),
    );

    return new Intl.DateTimeFormat('en-CA', {
        timeZone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(date);
}

/**
 * Splits export rows into the report periods they belong to, by the day each row's `created` time falls on in
 * `timeZone`. Returns Map(report_date -> rows) and how many rows fit no period.
 */
export function splitByPeriod(
    header,
    rows,
    periods,
    timeZone,
    column = 'created',
) {
    const index = header.findIndex((h) => h.trim().toLowerCase() === column);
    if (index === -1) throw new Error(`The export has no "${column}" column.`);

    const byPeriod = new Map(periods.map((p) => [p.report_date, []]));
    let outside = 0;

    for (const row of rows) {
        const day = localDay(row[index] ?? '', timeZone);
        const period =
            day === null
                ? undefined
                : periods.find((p) => day >= p.from && day <= p.to);
        if (period === undefined) {
            outside++;
        } else {
            byPeriod.get(period.report_date).push(row);
        }
    }

    return { byPeriod, outside };
}
