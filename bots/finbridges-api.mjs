/**
 * finbridges-api — placeholder until the request signature (x-app-access-sig)
 * and the wallet transaction-list endpoint are confirmed. Fails with a clear
 * message so the run journal shows why.
 */
import { runConnector } from './lib/runtime.mjs';

await runConnector(async () => {
    throw new Error(
        'FinBridges polling is not implemented yet: the signing scheme and list endpoint are still needed.',
    );
});
