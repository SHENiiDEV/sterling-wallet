/**
 * finbridges-api — polls the FinBridges public API for one client wallet.
 * No browser: plain signed HTTPS requests.
 *
 * Input (from Laravel): username = apiKey, password = apiSecret,
 * settings client_id / wallet_id, from / to (report period), download_dir.
 * Optional account settings:
 *   base_url   REQUIRED, e.g. https://demo.finbridges.eu (test) — the live address
 *              is given by FinBridges
 *   list_path  default /api-public/transactions/wallet/{walletId}?from={from}&to={to}
 *              ({clientId} {walletId} {from} {to} are replaced); pages of `take`
 *              (default 100) are read with skip=0,1,2… until `count` is reached
 *   external_ids  list of externalTransactionId values; with a list_path that has
 *              {externalId} (the documented by-external-id endpoint) each one is
 *              fetched and the results are collected. Handy to test the signature.
 *   ts_unit    's' (default) or 'ms' for x-app-access-ts
 *
 * Signature (assumed, see docs/finbridges.md): hex HMAC-SHA256(apiSecret,
 * ts + METHOD + pathWithQuery + body), headers x-app-access-ts / -sig / -token.
 * A 401/403 is logged with the response so the scheme can be corrected.
 */
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { log, readInput } from './lib/runtime.mjs';

function emit(result) {
    process.stdout.write(`${JSON.stringify({ files: [], ...result })}\n`);
    process.exit(0);
}

async function signedGet(input, pathWithQuery) {
    const settings = input.settings || {};
    if (!settings.base_url) {
        throw new Error(
            'Account settings need base_url, e.g. "https://demo.finbridges.eu" (the live address comes from FinBridges).',
        );
    }
    const base = settings.base_url.replace(/\/$/, '');
    const ts = String(
        settings.ts_unit === 'ms' ? Date.now() : Math.floor(Date.now() / 1000),
    );
    const sig = crypto
        .createHmac('sha256', input.password)
        .update(ts + 'GET' + pathWithQuery)
        .digest('hex');

    log(`GET ${base}${pathWithQuery}`);
    const response = await fetch(base + pathWithQuery, {
        headers: {
            accept: 'application/json',
            'user-agent': 'SterlingPay-Wallet/1.0',
            'x-app-access-ts': ts,
            'x-app-access-sig': sig,
            'x-app-access-token': input.username,
        },
        signal: AbortSignal.timeout(30000),
    });
    const text = await response.text();
    if (!response.ok) {
        // An HTML answer is the marketing site, not the API: show its title, not the markup.
        const summary =
            /<title>([^<]*)<\/title>/i.exec(text)?.[1] ?? text.slice(0, 300);
        log(
            `HTTP ${response.status} ${response.url} content-type=${response.headers.get('content-type')} server=${response.headers.get('server')}`,
        );
        throw new Error(
            `FinBridges answered HTTP ${response.status}: ${summary}`,
        );
    }
    try {
        return JSON.parse(text);
    } catch {
        throw new Error(
            `FinBridges answered HTTP ${response.status} but not JSON (${text.slice(0, 120)}). Check base_url.`,
        );
    }
}

try {
    const input = await readInput();
    const settings = input.settings || {};
    const clientId = settings.client_id;
    const walletId = settings.wallet_id;

    if (!input.username || !input.password) {
        throw new Error(
            'Login (apiKey) and Password (apiSecret) are required.',
        );
    }
    if (!clientId || !walletId) {
        throw new Error('Account settings need client_id and wallet_id.');
    }
    settings.list_path ||=
        '/api-public/transactions/wallet/{walletId}?from={from}&to={to}';

    const fill = (template) =>
        template
            .replaceAll('{clientId}', encodeURIComponent(clientId))
            .replaceAll('{walletId}', encodeURIComponent(walletId))
            .replaceAll('{from}', input.from)
            .replaceAll('{to}', input.to);

    let list = [];
    if (settings.list_path.includes('{externalId}')) {
        const ids = settings.external_ids || [];
        if (ids.length === 0) {
            throw new Error(
                'list_path uses {externalId}: add "external_ids": ["…"] to the account settings.',
            );
        }
        for (const id of ids) {
            const body = await signedGet(
                input,
                fill(settings.list_path).replaceAll(
                    '{externalId}',
                    encodeURIComponent(id),
                ),
            );
            list.push(...(Array.isArray(body) ? body : [body]));
        }
    } else {
        const base = fill(settings.list_path);
        const take = Number(settings.take) || 100;
        const glue = base.includes('?') ? '&' : '?';
        for (let page = 0; page < 200; page++) {
            const body = await signedGet(
                input,
                `${base}${glue}skip=${page}&take=${take}`,
            );
            const rows = Array.isArray(body) ? body : (body.list ?? []);
            list.push(...rows);
            const total = Array.isArray(body) ? null : Number(body.count);
            if (
                rows.length < take ||
                (Number.isFinite(total) && list.length >= total)
            ) {
                break;
            }
        }
    }
    log(`Received ${list.length} transaction(s).`);

    if (list.length === 0) {
        emit({ status: 'succeeded', rows: 0, report_date: input.report_date });
    }

    fs.mkdirSync(input.download_dir, { recursive: true });
    const file = path.join(
        input.download_dir,
        `finbridges_${walletId}_${input.from}_${input.to}.json`,
    );
    fs.writeFileSync(file, JSON.stringify({ count: list.length, list }));

    log(
        `Saved ${list.length} transaction(s) to ${file}; handing over to the importer.`,
    );
    emit({
        status: 'succeeded',
        files: [file],
        rows: list.length,
        report_date: input.report_date,
    });
} catch (error) {
    log('ERROR:', error?.stack || error?.message || String(error));
    emit({ status: 'failed', error: error?.message || String(error) });
}
