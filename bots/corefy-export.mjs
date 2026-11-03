/**
 * corefy-export — Paycore (Corefy) dashboard.
 *
 * Exports payment invoices of one commerce account for [gte, lt) and
 * downloads the CSV. A 0-row export returns { rows: 0, files: [] } so
 * Laravel marks the day as received with nothing in it.
 *
 * Input (besides the common fields): commerce_account, gte, lt (Unix s),
 * dashboard_url, username, password, totp_secret (optional). With `periods`
 * ([{report_date, from, to}]) the export covers a whole range and is split into
 * one file per report period (loading history); `file_dates` and `empty_dates`
 * tell Laravel which day each file and each empty period belongs to.
 */
import fs from 'node:fs';
import path from 'node:path';
import {
    humanClick,
    humanType,
    log,
    randomDelay,
    runConnector,
} from './lib/runtime.mjs';
import { parseCsv, splitByPeriod, toCsv } from './lib/csv.mjs';
import { freshTotp, nextTotpWindow, totp } from './lib/totp.mjs';

const MAX_STATUS_CHECKS = 60;

/** Types the code the way the Vue form expects it, then presses Verify. */
async function submitCode(page, otpInput, code) {
    await page.waitForTimeout(2500); // the field animates in
    await otpInput.waitFor({ state: 'visible', timeout: 15000 });
    await otpInput.click({ force: true });
    await otpInput.fill('');
    await randomDelay(200, 400);
    await page.keyboard.type(code, { delay: 90 });
    await otpInput.dispatchEvent('input').catch(() => {});
    await otpInput.dispatchEvent('change').catch(() => {});
    if ((await otpInput.inputValue().catch(() => '')) !== code) {
        await otpInput.fill(code);
        await otpInput.dispatchEvent('input').catch(() => {});
    }
    await randomDelay(800, 1500);

    const verify = page
        .locator('button:has-text("Verify"), button.enter-button')
        .first();
    if (await verify.isVisible().catch(() => false)) {
        await humanClick(verify);
    } else {
        await page.keyboard.press('Enter');
    }
    await randomDelay(1500, 2500);
}

async function login(page, input) {
    log('Opening login page…');
    await page.goto(input.login_url, { waitUntil: 'domcontentloaded' });
    await randomDelay(1000, 2000);

    await humanType(
        page.locator('#email, input[type="email"]').first(),
        input.username,
    );
    await humanType(
        page.locator('#password, input[type="password"]').first(),
        input.password,
    );

    const submit = page.locator(
        'button[type="submit"], button:has-text("Sign in"), button:has-text("Log in"), button:has-text("Login")',
    );
    if ((await submit.count()) > 0) {
        await humanClick(submit.first());
    } else {
        await page.keyboard.press('Enter');
    }

    // Two-step verification: /two-step-verification with an "Authentication code" field.
    await page
        .waitForURL(
            (url) =>
                url.href.includes('two-step-verification') ||
                !url.href.includes('/login'),
            { timeout: 20000 },
        )
        .catch(() => {});
    await page.waitForLoadState('domcontentloaded').catch(() => {});

    const otpInput = page
        .locator(
            'input[placeholder*="Authentication code" i], input[autocomplete="one-time-code"], input[name*="code" i], input[name*="otp" i]',
        )
        .first();
    const needsCode =
        page.url().includes('two-step-verification') ||
        (await otpInput
            .waitFor({ state: 'visible', timeout: 3000 })
            .then(() => true)
            .catch(() => false));

    if (needsCode) {
        if (!input.totp_secret)
            throw new Error(
                'Corefy asks for a 2FA code, but the integration account has no 2FA secret.',
            );
        log('Two-step verification requested.');
        await submitCode(page, otpInput, await freshTotp(input.totp_secret));

        const error = page.locator('.fail-login, .el-form-item__error').first();
        if (
            await error
                .waitFor({ state: 'visible', timeout: 2500 })
                .then(() => true)
                .catch(() => false)
        ) {
            log('Code rejected; retrying with the next one…');
            await nextTotpWindow();
            await submitCode(page, otpInput, totp(input.totp_secret));
        }
    }

    await page.waitForURL((url) => !url.href.includes('/login'), {
        timeout: 30000,
    });
    await page.waitForLoadState('networkidle').catch(() => {});
    log('Logged in:', page.url());
}

async function extractRowsCount(page) {
    const text = (
        await page
            .locator('body')
            .innerText()
            .catch(() => '')
    ).trim();
    const match = text.match(/Rows\s*[\n\r:]*\s*(\d+)/i);
    return match ? parseInt(match[1], 10) : null;
}

/**
 * Exports the payment invoices of the commerce account created in [gte, lt) and downloads the CSV.
 * Returns { rows, file } (file is null for an empty export).
 */
async function exportInvoices(page, input, base, gte, lt, fileName) {
    const url =
        `${base}/transactions/payment-invoices?sort=-created` +
        `&filter%5Bcommerce_account%5D=${encodeURIComponent(input.commerce_account)}` +
        `&filter%5Bcreated%5D%5Blt%5D=${lt}&filter%5Bcreated%5D%5Bgte%5D=${gte}`;
    await page.goto(url, { waitUntil: 'networkidle' });
    await randomDelay(2000, 3500);

    log('Starting export…');
    const exportButton = page.locator('button:has-text("Export")').first();
    await exportButton.waitFor({ state: 'visible', timeout: 15000 });
    await humanClick(exportButton);

    await page
        .locator(
            '.el-notification, div[role="alert"], .payment-invoice__export-message-link',
        )
        .first()
        .waitFor({ state: 'visible', timeout: 30000 });
    const link = page
        .locator(
            'a.payment-invoice__export-message-link, a[href*="/data-exports/list/"], .el-notification a:has-text("here")',
        )
        .first();
    await link.waitFor({ state: 'attached', timeout: 10000 });
    const exportUrl = await link.getAttribute('href');
    if (!exportUrl)
        throw new Error('Export link not found in the notification.');

    await page.goto(new URL(exportUrl, base).href, {
        waitUntil: 'networkidle',
    });

    let done = false;
    for (let attempt = 1; attempt <= MAX_STATUS_CHECKS; attempt++) {
        const status = page
            .locator(
                '.data-output:has-text("Status") .el-tag--success, .el-tag:has-text("done")',
            )
            .first();
        if ((await status.count()) > 0 && (await status.isVisible())) {
            if (
                (await status.innerText()).trim().toLowerCase().includes('done')
            ) {
                done = true;
                break;
            }
        }
        log(`Export still processing (${attempt}/${MAX_STATUS_CHECKS})…`);
        await page.waitForTimeout(10000);
        await page.reload({ waitUntil: 'networkidle' }).catch(() => {});
    }
    if (!done) throw new Error('Export did not finish in time.');

    const rows = await extractRowsCount(page);
    log(`Rows: ${rows ?? 'unknown'}`);
    if (rows === 0) {
        return { rows: 0, file: null };
    }

    const csvButton = page.locator('button:has-text("Download csv")').first();
    const [download] = await Promise.all([
        page.waitForEvent('download', { timeout: 30000 }),
        humanClick(csvButton),
    ]);
    const file = path.join(input.download_dir, fileName);
    await download.saveAs(file);
    log('Saved', file);

    return { rows, file };
}

await runConnector(async (input, page) => {
    if (!input.username || !input.password)
        throw new Error(
            'Corefy login or password is not set on the integration account.',
        );
    if (!input.commerce_account)
        throw new Error('No commerce account (gate MID) for this target.');

    await login(page, input);

    const base = String(
        input.dashboard_url || 'https://dashboard.paycore.io',
    ).replace(/\/+$/, '');
    const safeAccount = String(input.commerce_account).replace(
        /[^a-zA-Z0-9_-]/g,
        '_',
    );

    // A range of days (loading history): one export for the whole range, then one file per report period.
    if (Array.isArray(input.periods) && input.periods.length > 0) {
        log(
            `Range ${input.from} — ${input.to} (${input.periods.length} report period(s)), account ${input.commerce_account}`,
        );
        const whole = await exportInvoices(
            page,
            input,
            base,
            input.gte,
            input.lt,
            `corefy_${safeAccount}_range_${input.from}_${input.to}.csv`,
        );

        const emptyDates = input.periods.map((p) => p.report_date);
        if (whole.file === null) {
            return {
                status: 'succeeded',
                rows: 0,
                files: [],
                empty_dates: emptyDates,
                report_date: input.report_date,
            };
        }

        const [header, ...rows] = parseCsv(fs.readFileSync(whole.file, 'utf8'));
        const { byPeriod, outside } = splitByPeriod(
            header,
            rows,
            input.periods,
            input.timezone || 'UTC',
        );
        if (outside > 0)
            log(
                `${outside} row(s) fall outside the requested days and are left out.`,
            );

        const files = [];
        const fileDates = {};
        const empty = [];
        for (const period of input.periods) {
            const periodRows = byPeriod.get(period.report_date) ?? [];
            if (periodRows.length === 0) {
                empty.push(period.report_date);
                continue;
            }
            const file = path.join(
                input.download_dir,
                `corefy_${safeAccount}_${period.report_date}.csv`,
            );
            fs.writeFileSync(file, toCsv([header, ...periodRows]));
            files.push(file);
            fileDates[file] = period.report_date;
            log(`${period.report_date}: ${periodRows.length} row(s)`);
        }
        fs.rmSync(whole.file, { force: true });

        return {
            status: 'succeeded',
            rows: rows.length - outside,
            files,
            file_dates: fileDates,
            empty_dates: empty,
            report_date: input.report_date,
        };
    }

    log(
        `Report ${input.report_date} (${input.from} — ${input.to}), account ${input.commerce_account}`,
    );
    const day = await exportInvoices(
        page,
        input,
        base,
        input.gte,
        input.lt,
        `corefy_${safeAccount}_${input.report_date}.csv`,
    );
    if (day.file === null) {
        return {
            status: 'succeeded',
            rows: 0,
            files: [],
            report_date: input.report_date,
        };
    }

    return {
        status: 'succeeded',
        rows: day.rows,
        files: [day.file],
        report_date: input.report_date,
    };
});
