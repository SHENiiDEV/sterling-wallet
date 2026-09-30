/**
 * cardaq-export — Cardaq sends clearing reports by e-mail.
 *
 * Logs into Hostinger webmail, searches for `search_query`, opens the
 * message whose subject date (or date range, e.g. a weekend
 * 2026.08.07-2026.08.09) covers the report date and downloads its
 * attachments. No message yet → `skipped` (the scheduler tries again).
 *
 * Input (besides the common fields): username, password, search_query,
 * mail_base_url.
 */
import path from 'node:path';
import {
    humanClick,
    humanType,
    log,
    randomDelay,
    runConnector,
} from './lib/runtime.mjs';
import { parseReportDates } from './lib/dates.mjs';

async function login(page, input) {
    log('Opening webmail login…');
    await page.goto(input.login_url, { waitUntil: 'domcontentloaded' });
    await randomDelay(1500, 3000);

    const email = page
        .locator(
            'input[type="email"], input[name="email"], input[name="username"], input[autocomplete="username"], input[data-qa="login-email-input"]',
        )
        .first();
    await email.waitFor({ state: 'visible', timeout: 20000 });
    await humanType(email, input.username);

    const password = page
        .locator(
            'input[type="password"], input[name="password"], input[data-qa="login-password-input"]',
        )
        .first();
    await password.waitFor({ state: 'visible', timeout: 10000 });
    await humanType(password, input.password);

    await humanClick(
        page
            .locator(
                'button[data-qa="login-submit-button"], button[type="submit"], button:has-text("Login"), button:has-text("Sign in")',
            )
            .first(),
    );

    try {
        await page.waitForURL((url) => !url.href.includes('/auth/login'), {
            timeout: 25000,
        });
    } catch {
        const search = page
            .locator(
                'input[data-qa="mailbox-search-input"], input[name="search"], input[placeholder="Search mail"]',
            )
            .first();
        if (!((await search.count()) > 0 && (await search.isVisible()))) {
            throw new Error(
                'Webmail login failed — check the integration account credentials.',
            );
        }
    }
    await page.waitForLoadState('domcontentloaded').catch(() => {});
    await randomDelay(1500, 2500);
    log('Logged in:', page.url());
}

/** Closes Hostinger announcement modals ("Try conversation view" → Later). */
async function dismissPopups(page) {
    const later = page
        .locator(
            'button[data-qa="conversation-mode-announcement-cancel"], button:has-text("Later"), button:has-text("Dismiss"), button:has-text("Not now")',
        )
        .first();
    if (await later.isVisible().catch(() => false)) {
        log('Closing webmail popup…');
        await later.click({ force: true }).catch(() => {});
        await page.waitForTimeout(1000);
    }
}

function covers(dates, input) {
    // The e-mail covers our report if its range contains the report date,
    // or it ends on the report date (weekend / holiday bundles).
    return (
        (input.report_date >= dates.startDate &&
            input.report_date <= dates.endDate) ||
        dates.endDate === input.report_date
    );
}

await runConnector(
    async (input, page) => {
        if (!input.username || !input.password)
            throw new Error(
                'Webmail login or password is not set on the integration account.',
            );

        await login(page, input);
        await dismissPopups(page);

        log(
            `Searching "${input.search_query}" for ${input.report_date} (${input.from} — ${input.to})`,
        );
        const search = page
            .locator(
                'input[data-qa="mailbox-search-input"], input[name="search"], input[placeholder="Search mail"]',
            )
            .first();
        await search.waitFor({ state: 'visible', timeout: 20000 });
        await search.click({ force: true });
        await search.fill(input.search_query);
        await search.dispatchEvent('input').catch(() => {});
        await randomDelay(300, 600);
        await page.keyboard.press('Enter');
        await randomDelay(2500, 4000);
        await dismissPopups(page);

        const rows = page.locator(
            'div[data-qa="message-row"], a[data-qa="message-row-link"]',
        );
        await rows
            .first()
            .waitFor({ state: 'attached', timeout: 20000 })
            .catch(() => {});
        const count = await rows.count();
        log(`${count} message(s) found`);

        let selected = null;
        let dates = null;
        for (let i = 0; i < count; i++) {
            const row = rows.nth(i);
            const found = parseReportDates(
                (await row.innerText().catch(() => '')).trim(),
            );
            if (found && covers(found, input)) {
                selected = row;
                dates = found;
                log(`Message ${i + 1} matches: ${found.formatted}`);
                break;
            }
        }

        if (!selected) {
            return {
                status: 'skipped',
                error: `No Cardaq e-mail for ${input.report_date} yet.`,
            };
        }

        const href = await selected.getAttribute('href').catch(() => null);
        if (href && href.startsWith('/')) {
            await page.goto(
                `${String(input.mail_base_url).replace(/\/+$/, '')}${href}`,
                { waitUntil: 'domcontentloaded' },
            );
        } else {
            await humanClick(selected);
        }
        await randomDelay(2000, 3500);
        await dismissPopups(page);

        await page
            .locator(
                'button[data-qa="message-attachment-download"], div[data-qa="message-attachment-item"], span.hyperlink-text:has-text("Download all")',
            )
            .first()
            .waitFor({ state: 'visible', timeout: 25000 });

        const files = [];
        const buttons = page.locator(
            'button[data-qa="message-attachment-download"]',
        );
        const buttonCount = await buttons.count();
        for (let i = 0; i < buttonCount; i++) {
            const [download] = await Promise.all([
                page.waitForEvent('download', { timeout: 30000 }),
                buttons.nth(i).click({ force: true }),
            ]);
            const name = download.suggestedFilename();
            if (!/\.(xlsx|xls|csv)$/i.test(name)) {
                log(`Skipping attachment ${name}`);
                continue;
            }
            const file = path.join(
                input.download_dir,
                `cardaq_${dates.endDate}_${name.replace(/[^\w.-]/g, '_')}`,
            );
            await download.saveAs(file);
            files.push(file);
            log('Saved', file);
            await randomDelay(1000, 2000);
        }

        if (buttonCount === 0) {
            // Older layout: one "Download all" link.
            const all = page
                .locator('span.hyperlink-text:has-text("Download all")')
                .first();
            if (await all.isVisible().catch(() => false)) {
                const [download] = await Promise.all([
                    page.waitForEvent('download', { timeout: 30000 }),
                    all.click({ force: true }),
                ]);
                const name = download.suggestedFilename();
                if (/\.(xlsx|xls|csv)$/i.test(name)) {
                    const file = path.join(
                        input.download_dir,
                        `cardaq_${dates.endDate}_${name.replace(/[^\w.-]/g, '_')}`,
                    );
                    await download.saveAs(file);
                    files.push(file);
                    log('Saved', file);
                } else {
                    log(`"Download all" gave ${name}, not a report file.`);
                }
            }
        }

        if (files.length === 0)
            throw new Error('The Cardaq e-mail has no XLSX/CSV attachment.');

        // Clearing rows are in the CSV when both formats are attached; the XLSX is kept as well.
        files.sort((a, b) =>
            a.endsWith('.csv') === b.endsWith('.csv')
                ? 0
                : a.endsWith('.csv')
                  ? -1
                  : 1,
        );

        return { status: 'succeeded', files, report_date: dates.endDate };
    },
    { stealth: true, timezoneId: 'Europe/Warsaw' },
);
