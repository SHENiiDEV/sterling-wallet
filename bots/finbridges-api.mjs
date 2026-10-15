/**
 * finbridges-api — polls the FinBridges public API for one client wallet.
 * No browser: plain signed HTTPS requests.
 *
 * Input (from Laravel): username = apiKey, password = apiSecret,
 * settings client_id / wallet_id, from / to (report period), download_dir.
 * Optional account settings:
 *   base_url   default https://finbridges.eu
 *   list_path  REQUIRED, the transactions-list path from the FinBridges docs;
 *              {clientId} {walletId} {from} {to} are replaced, e.g.
 *              /api-public/transactions/{clientId}/{walletId}?from={from}&to={to}
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
    const base = (settings.base_url || 'https://finbridges.eu').replace(/\/$/, '');
    const ts = String(
        settings.ts_unit === 'ms' ? Date.now() : Math.floor(Date.now() / 1000),
    );
    const sig = crypto
        .createHmac('sha256', input.password)
        .update(ts + 'GET' + pathWithQuery)
        .digest('hex');

    log(`GET ${pathWithQuery}`);
    const response = await fetch(base + pathWithQuery, {
        headers: {
            accept: 'application/json',
            'x-app-access-ts': ts,
            'x-app-access-sig': sig,
            'x-app-access-token': input.username,
        },
        signal: AbortSignal.timeout(30000),
    });
    const text = await response.text();
    if (!response.ok) {
        log(`HTTP ${response.status}: ${text.slice(0, 500)}`);
        throw new Error(
            `FinBridges answered HTTP ${response.status}: ${text.slice(0, 300)}`,
        );
    }
    return JSON.parse(text);
}

try {
    const input = await readInput();
    const settings = input.settings || {};
    const clientId = settings.client_id;
    const walletId = settings.wallet_id;

    if (!input.username || !input.password) {
        throw new Error('Login (apiKey) and Password (apiSecret) are required.');
    }
    if (!clientId || !walletId) {
        throw new Error('Account settings need client_id and wallet_id.');
    }
    if (!settings.list_path) {
        throw new Error(
            'Account settings need list_path: the transactions-list path from the FinBridges docs, e.g. /api-public/transactions/{clientId}/{walletId}?from={from}&to={to}',
        );
    }

    const pathWithQuery = settings.list_path
        .replaceAll('{clientId}', encodeURIComponent(clientId))
        .replaceAll('{walletId}', encodeURIComponent(walletId))
        .replaceAll('{from}', input.from)
        .replaceAll('{to}', input.to);

    const body = await signedGet(input, pathWithQuery);
    const list = Array.isArray(body) ? body : (body.list ?? []);
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
