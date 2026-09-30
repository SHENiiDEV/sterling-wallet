/**
 * Shared plumbing for connector scripts.
 *
 * Contract with Laravel (App\Bots\PlaywrightRunner):
 *  - input: one JSON object on stdin;
 *  - logs: stderr (kept on the bot run);
 *  - result: the last stdout line, a JSON object
 *    { status: 'succeeded'|'failed'|'skipped', files: [], rows, report_date, error, screenshot }.
 * Retries, alerts and ingestion happen in Laravel, never here.
 */
import fs from 'node:fs';
import path from 'node:path';

export function log(...args) {
    console.error(`[${new Date().toISOString()}]`, ...args);
}

export async function readInput() {
    const chunks = [];
    for await (const chunk of process.stdin) chunks.push(chunk);
    const raw = Buffer.concat(chunks).toString('utf8').trim();
    return raw ? JSON.parse(raw) : {};
}

export function randomDelay(min = 300, max = 800) {
    return new Promise((resolve) =>
        setTimeout(resolve, Math.floor(Math.random() * (max - min + 1)) + min),
    );
}

/** Types like a person: hover, click, clear, then one key at a time. */
export async function humanType(locator, text) {
    await locator.hover().catch(() => {});
    await randomDelay(150, 300);
    await locator.click({ force: true });
    await randomDelay(150, 300);
    await locator.fill('');
    for (const char of String(text)) {
        await locator.type(char, {
            delay: Math.floor(Math.random() * 90) + 40,
        });
    }
    await randomDelay(300, 500);
}

export async function humanClick(locator) {
    await locator.hover().catch(() => {});
    await randomDelay(150, 300);
    await locator.click({ force: true });
    await randomDelay(400, 700);
}

/**
 * Launches Chromium. `stealth` uses playwright-extra with the stealth
 * plugin (needed for Hostinger / Cloudflare challenges).
 */
export async function launch(input, { stealth = false, timezoneId } = {}) {
    let chromium;
    if (stealth) {
        const extra = await import('playwright-extra');
        const stealthPlugin = (await import('puppeteer-extra-plugin-stealth'))
            .default;
        chromium = extra.chromium;
        chromium.use(stealthPlugin());
    } else {
        chromium = (await import('playwright')).chromium;
    }

    const options = {
        headless: input.headless !== false,
        args: [
            '--no-sandbox',
            '--disable-setuid-sandbox',
            '--disable-blink-features=AutomationControlled',
            '--window-size=1366,768',
        ],
    };
    if (process.env.CHROMIUM_PATH)
        options.executablePath = process.env.CHROMIUM_PATH;
    if (input.proxy) {
        options.proxy = { server: input.proxy };
        log(`Proxy enabled: ${input.proxy}`);
    }

    let browser;
    try {
        browser = await chromium.launch(options);
    } catch (error) {
        if (String(error?.message).includes("Executable doesn't exist")) {
            throw new Error(
                `Chromium is not installed where the bot looks for it (${process.env.PLAYWRIGHT_BROWSERS_PATH || 'default Playwright cache'}). ` +
                    'On the server run: cd bots && npm run install-browser && sudo chown -R www-data:www-data .browsers',
            );
        }
        throw error;
    }
    const context = await browser.newContext({
        acceptDownloads: true,
        viewport: { width: 1366, height: 768 },
        locale: 'en-US',
        timezoneId: timezoneId || input.timezone || 'Europe/Riga',
        userAgent:
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
    });
    const page = await context.newPage();
    page.setDefaultTimeout(60000);

    if (!stealth) {
        await page.addInitScript(() => {
            Object.defineProperty(navigator, 'webdriver', {
                get: () => undefined,
            });
            Object.defineProperty(navigator, 'languages', {
                get: () => ['en-US', 'en'],
            });
            window.chrome = window.chrome || { runtime: {} };
        });
    }

    return { browser, context, page };
}

/**
 * Saves what the browser shows to {screenshot_dir}/live.png every few
 * seconds, so the admin can watch a running bot. Returns a stop function.
 */
function startLiveFrames(page, dir, everyMs = 4000) {
    if (!dir) return () => {};
    fs.mkdirSync(dir, { recursive: true });
    const target = path.join(dir, 'live.png');
    const temp = path.join(dir, 'live.tmp.png');
    let busy = false;

    const timer = setInterval(async () => {
        if (busy || page.isClosed()) return;
        busy = true;
        try {
            await page.screenshot({ path: temp, timeout: 3000 });
            fs.renameSync(temp, target); // never serve a half-written file
        } catch {
            // navigation in progress; next tick
        } finally {
            busy = false;
        }
    }, everyMs);

    return () => clearInterval(timer);
}

/**
 * Runs `main(input, page)` and prints the result line. Any throw becomes a
 * `failed` result with a full-page screenshot.
 */
export async function runConnector(main, launchOptions = {}) {
    const input = await readInput();
    let session = null;
    let stopFrames = () => {};
    let result;

    try {
        session = await launch(input, launchOptions);
        stopFrames = startLiveFrames(session.page, input.screenshot_dir);
        log(`Browser started${input.proxy ? ' (via proxy)' : ''}.`);
        result = await main(input, session.page);
    } catch (error) {
        log('ERROR:', error?.stack || error?.message || String(error));
        let screenshot = null;
        if (session?.page && !session.page.isClosed() && input.screenshot_dir) {
            screenshot = path.join(
                input.screenshot_dir,
                `error_${Date.now()}.png`,
            );
            try {
                fs.mkdirSync(input.screenshot_dir, { recursive: true });
                await session.page.screenshot({
                    path: screenshot,
                    fullPage: true,
                });
            } catch (shotError) {
                log('Screenshot failed:', shotError.message);
                screenshot = null;
            }
        }
        result = {
            status: 'failed',
            error: `${error?.message || String(error)}${session?.page ? ` (url: ${session.page.url()})` : ''}`,
            screenshot,
        };
    } finally {
        stopFrames();
        await session?.context?.close().catch(() => {});
        await session?.browser?.close().catch(() => {});
    }

    process.stdout.write(`${JSON.stringify({ files: [], ...result })}\n`);
    process.exit(0);
}
