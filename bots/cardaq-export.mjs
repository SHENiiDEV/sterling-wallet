/**
 * cardaq-export — Cardaq sends clearing reports by e-mail.
 *
 * Logs into the account's webmail (`mail_provider`: `hostinger` or
 * `privateemail`), finds the message whose subject date (or date range, e.g. a
 * weekend 2026.08.07-2026.08.09) covers the report date and downloads its
 * attachments. No message yet → `skipped` (the scheduler tries again, hourly).
 *
 * Input (besides the common fields): username, password, mail_provider,
 * search_query, mail_base_url.
 *   - hostinger:    searches `search_query` and picks the message by the date in its subject.
 *   - privateemail: searches "{search_query}, Processing period {dd.mm.yyyy}", then the same
 *                   phrase without the date, and opens each result to read its date.
 * `search_template` (optional) sets what is searched: {query} is `search_query`,
 * {date} the report date as dd.mm.yyyy, e.g. "{query}, Processing period {date}".
 * Chargeback attachments are not clearing data: they are skipped here.
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

const REPORT_FILE = /\.(xlsx|xls|csv)$/i;

const HOSTINGER = {
    search: 'input[data-qa="mailbox-search-input"], input[name="search"], input[placeholder="Search mail"]',
};

const PRIVATEEMAIL = {
    username:
        'input#textfield-username, input[data-zid="textfield-username"], input[name="username"]',
    password:
        'input#passwordfield-password, input[data-zid="passwordfield-password"], input[name="userPassword"]',
    submit: 'button[data-zid="login-btn"], button[type="submit"]',
    search: 'input[data-zid="headerSearchPanelInput"], input[name="headerSearchPanelInput"], input[placeholder="Search mail"]',
    rows: 'div[data-zid*="mailListItem"], div[class*="spm-mail-list__item"], div[class*="mail-list-item"], div[class*="spm-message-item"], div.gb-card, div[role="listitem"]',
    viewer: 'div.spm-email-viewer, div[class*="email-viewer"]',
    attachmentsToggle:
        'button[data-zid="emailViewerAttachmentsToggle"], button.spm-email-viewer__attachments__summary--trigger, button[title*="attachment" i]',
    download:
        'button[data-zid="emailViewerAttachmentDownload"], .spm-email-viewer__attachments__list button[data-zid="emailViewerAttachmentDownload"], button[aria-label^="Download:"]',
};

function ddmmyyyy(iso) {
    const [y, m, d] = iso.split('-');

    return `${d}.${m}.${y}`;
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

/** Saves one downloaded attachment; chargebacks and other files are left out. */
async function keep(download, input, endDate, files) {
    const name = download.suggestedFilename();
    if (!REPORT_FILE.test(name)) {
        log(`Skipping attachment ${name}`);

        return;
    }
    if (/chargeback/i.test(name)) {
        log(`Skipping chargeback file ${name} (not clearing data)`);

        return;
    }

    const file = path.join(
        input.download_dir,
        `cardaq_${endDate}_${name.replace(/[^\w.-]/g, '_')}`,
    );
    await download.saveAs(file);
    files.push(file);
    log('Saved', file);
    await randomDelay(1000, 2000);
}

/** Clearing rows are in the CSV when both formats come; the XLSX is kept as well. */
function csvFirst(files) {
    return files.sort((a, b) =>
        a.endsWith('.csv') === b.endsWith('.csv')
            ? 0
            : a.endsWith('.csv')
              ? -1
              : 1,
    );
}

// ---------------------------------------------------------------- Hostinger

async function hostingerLogin(page, input) {
    log('Opening Hostinger webmail login…');
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
        const search = page.locator(HOSTINGER.search).first();
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

/** The search text from the account's template; `withDate: false` drops the date for a wider retry. */
function searchText(input, fallbackTemplate, withDate = true) {
    const template = input.search_template || fallbackTemplate;
    const text = template
        .replaceAll('{query}', input.search_query)
        .replaceAll('{date}', withDate ? ddmmyyyy(input.report_date) : '');

    return text.replace(/\s+/g, ' ').replace(/[\s,;:-]+$/, '').trim();
}

async function fromHostinger(input, page) {
    await hostingerLogin(page, input);
    await dismissPopups(page);

    log(
        `Searching "${searchText(input, '{query}')}" for ${input.report_date} (${input.from} — ${input.to})`,
    );
    const search = page.locator(HOSTINGER.search).first();
    await search.waitFor({ state: 'visible', timeout: 20000 });
    await search.click({ force: true });
    await search.fill(searchText(input, '{query}'));
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

    if (!selected) return null;

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
        await keep(download, input, dates.endDate, files);
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
            await keep(download, input, dates.endDate, files);
        }
    }

    return { dates, files };
}

// ------------------------------------------------------------- PrivateEmail

async function privateEmailLogin(page, input) {
    log('Opening PrivateEmail login…');
    await page.goto(input.login_url, { waitUntil: 'domcontentloaded' });
    await randomDelay(1500, 3000);

    if (page.url().includes('/spm/mail')) {
        log('Already in the PrivateEmail mailbox.');

        return;
    }

    const username = page.locator(PRIVATEEMAIL.username).first();
    await username.waitFor({ state: 'visible', timeout: 30000 });
    await humanType(username, input.username);

    const password = page.locator(PRIVATEEMAIL.password).first();
    await password.waitFor({ state: 'visible', timeout: 15000 });
    await humanType(password, input.password);

    await randomDelay(500, 1000);
    await page.locator(PRIVATEEMAIL.submit).first().click({ force: true });

    log('Waiting for the mailbox (/spm/mail/)…');
    await Promise.race([
        page.waitForURL(
            (url) =>
                url.pathname.includes('/spm/mail') ||
                url.pathname.includes('/mail'),
            { timeout: 35000 },
        ),
        page
            .locator(PRIVATEEMAIL.search)
            .first()
            .waitFor({ state: 'visible', timeout: 35000 }),
    ]).catch(() => log('No redirect yet, checking the page…'));
    await randomDelay(2000, 3500);

    if (!page.url().includes('/spm/mail')) {
        await page
            .goto(
                `${String(input.mail_base_url).replace(/\/+$/, '')}/spm/mail/`,
                {
                    waitUntil: 'domcontentloaded',
                },
            )
            .catch(() => {});
        await randomDelay(2000, 3000);
    }

    const search = page.locator(PRIVATEEMAIL.search).first();
    if (!(await search.isVisible().catch(() => false))) {
        throw new Error(
            'PrivateEmail login failed — check the integration account credentials.',
        );
    }
    log('Logged in:', page.url());
}

async function privateEmailSearch(page, query) {
    const search = page.locator(PRIVATEEMAIL.search).first();
    await search.waitFor({ state: 'visible', timeout: 30000 });
    log(`Searching "${query}"…`);
    await search.click({ force: true });
    await search.fill('');
    await search.fill(query);
    await search.dispatchEvent('input').catch(() => {});
    await search.dispatchEvent('change').catch(() => {});
    await randomDelay(400, 800);
    await page.keyboard.press('Enter');
    await randomDelay(3000, 4500);

    return page.locator(PRIVATEEMAIL.rows);
}

async function fromPrivateEmail(input, page) {
    await privateEmailLogin(page, input);

    const template = '{query}, Processing period {date}';
    let rows = await privateEmailSearch(page, searchText(input, template));
    let count = await rows.count();
    if (count === 0) {
        log('Nothing for the exact date, trying without it…');
        rows = await privateEmailSearch(
            page,
            searchText(input, template, false),
        );
        count = await rows.count();
    }
    log(`${count} message(s) found`);

    let dates = null;
    for (let i = 0; i < count; i++) {
        const row = rows.nth(i);
        let found = parseReportDates(
            (await row.innerText().catch(() => '')).trim(),
        );

        // Open the message: the viewer shows the full subject and the attachments.
        await row.scrollIntoViewIfNeeded().catch(() => {});
        await row.click({ force: true });
        await randomDelay(1500, 2500);

        if (!found) {
            const viewer = await page
                .locator(PRIVATEEMAIL.viewer)
                .first()
                .innerText()
                .catch(() => '');
            found = parseReportDates(viewer);
        }

        if (found && covers(found, input)) {
            dates = found;
            log(`Message ${i + 1} matches: ${found.formatted}`);
            break;
        }
        if (found) log(`Message ${i + 1} is for ${found.formatted}, next…`);
    }

    if (!dates) return null;

    const toggle = page.locator(PRIVATEEMAIL.attachmentsToggle).first();
    if (await toggle.isVisible({ timeout: 6000 }).catch(() => false)) {
        if ((await toggle.getAttribute('aria-expanded')) !== 'true') {
            await toggle.click({ force: true });
            await randomDelay(1000, 1500);
        }
    }

    const files = [];
    const buttons = page.locator(PRIVATEEMAIL.download);
    const buttonCount = await buttons.count();
    log(`${buttonCount} attachment button(s)`);
    for (let i = 0; i < buttonCount; i++) {
        const [download] = await Promise.all([
            page.waitForEvent('download', { timeout: 35000 }),
            buttons.nth(i).click({ force: true }),
        ]);
        await keep(download, input, dates.endDate, files);
    }

    return { dates, files };
}

await runConnector(
    async (input, page) => {
        if (!input.username || !input.password)
            throw new Error(
                'Webmail login or password is not set on the integration account.',
            );

        const mailbox =
            input.mail_provider === 'privateemail'
                ? 'privateemail'
                : 'hostinger';
        log(
            `Mailbox: ${mailbox}. Looking for ${input.report_date} (${input.from} — ${input.to})`,
        );

        const found =
            mailbox === 'privateemail'
                ? await fromPrivateEmail(input, page)
                : await fromHostinger(input, page);

        if (!found) {
            return {
                status: 'skipped',
                error: `No Cardaq e-mail for ${input.report_date} yet.`,
            };
        }
        if (found.files.length === 0)
            throw new Error('The Cardaq e-mail has no XLSX/CSV attachment.');

        return {
            status: 'succeeded',
            files: csvFirst(found.files),
            report_date: found.dates.endDate,
        };
    },
    { stealth: true, timezoneId: 'Europe/Warsaw' },
);
