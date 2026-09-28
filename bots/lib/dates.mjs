/**
 * Finds a date or a date range in e-mail subjects / text:
 * "Processing date(s) 2026.08.07-2026.08.09", "07.08.2026-09.08.2026",
 * "Payout period 11.08.2026", "2026.07.29".
 * Returns { startDate, endDate, isRange, formatted } with ISO dates, or null.
 */
export function parseReportDates(text) {
    if (!text) return null;

    const ymdRange =
        /(\d{4})[.-](\d{2})[.-](\d{2})\s*(?:-|–|—|to)\s*(\d{4})[.-](\d{2})[.-](\d{2})/i;
    const dmyRange =
        /(\d{2})[.-](\d{2})[.-](\d{4})\s*(?:-|–|—|to)\s*(\d{2})[.-](\d{2})[.-](\d{4})/i;

    let m = text.match(ymdRange);
    if (m) {
        const [, y1, m1, d1, y2, m2, d2] = m;
        return {
            startDate: `${y1}-${m1}-${d1}`,
            endDate: `${y2}-${m2}-${d2}`,
            isRange: true,
            formatted: m[0],
        };
    }
    m = text.match(dmyRange);
    if (m) {
        const [, d1, m1, y1, d2, m2, y2] = m;
        return {
            startDate: `${y1}-${m1}-${d1}`,
            endDate: `${y2}-${m2}-${d2}`,
            isRange: true,
            formatted: m[0],
        };
    }
    m = text.match(/(\d{2})[.-](\d{2})[.-](\d{4})/);
    if (m) {
        const [, d, mo, y] = m;
        return {
            startDate: `${y}-${mo}-${d}`,
            endDate: `${y}-${mo}-${d}`,
            isRange: false,
            formatted: m[0],
        };
    }
    m = text.match(/(\d{4})[.-](\d{2})[.-](\d{2})/);
    if (m) {
        const [, y, mo, d] = m;
        return {
            startDate: `${y}-${mo}-${d}`,
            endDate: `${y}-${mo}-${d}`,
            isRange: false,
            formatted: m[0],
        };
    }
    return null;
}
