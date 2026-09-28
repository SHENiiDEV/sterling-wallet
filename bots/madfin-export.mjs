/**
 * madfin-export — placeholder until the Madfin portal login and export
 * steps are known. Fails with a clear message so the run journal shows why.
 */
import { runConnector } from './lib/runtime.mjs';

await runConnector(async () => {
    throw new Error(
        'Madfin export is not implemented yet: portal URL and export steps are still needed.',
    );
});
