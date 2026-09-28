import { usePage } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';

/**
 * Shows validation errors that are not tied to a form field
 * (e.g. "cannot delete, still in use").
 */
export function PageErrors({ keys }: { keys: string[] }) {
    const { errors } = usePage().props as { errors: Record<string, string> };
    const messages = keys.map((key) => errors[key]).filter(Boolean);

    if (messages.length === 0) {
        return null;
    }

    return (
        <div className="flex items-start gap-3 rounded-lg border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm text-destructive-foreground">
            <AlertTriangle className="mt-0.5 size-4 shrink-0" />
            <div className="grid gap-1">
                {messages.map((message) => (
                    <p key={message}>{message}</p>
                ))}
            </div>
        </div>
    );
}
