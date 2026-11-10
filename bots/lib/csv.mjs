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

/**
 * The calendar day (YYYY-MM-DD) of a "created" cell. Corefy's export gives the dashboard's local time, so the day is
 * simply the date part; nothing is converted.
 */
export function dayOf(text) {
    const match = /^(\d{4}-\d{2}-\d{2})/.exec(String(text).trim());

    return match ? match[1] : null;
}

/**
 * How many export rows fall on each report period, by the day of the row's `created` time. Returns
 * Map(report_date -> rows); periods without rows are the days the gateway had nothing for.
 */
export function countByPeriod(header, rows, periods, column = 'created') {
    const index = header.findIndex((h) => h.trim().toLowerCase() === column);
    if (index === -1) throw new Error(`The export has no "${column}" column.`);

    const counts = new Map(periods.map((p) => [p.report_date, 0]));
    for (const row of rows) {
        const day = dayOf(row[index] ?? '');
        const period =
            day === null
                ? undefined
                : periods.find((p) => day >= p.from && day <= p.to);
        if (period !== undefined)
            counts.set(period.report_date, counts.get(period.report_date) + 1);
    }

    return counts;
}
