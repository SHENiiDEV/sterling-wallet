import { FileUp, X } from 'lucide-react';
import { useRef, useState } from 'react';
import { formatBytes } from '@/lib/format';
import { cn } from '@/lib/utils';

export const ACCEPTED_FILES =
    '.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.png,.jpg,.jpeg,.zip';

export function FileDrop({
    files,
    onChange,
    compact = false,
}: {
    files: File[];
    onChange: (files: File[]) => void;
    compact?: boolean;
}) {
    const input = useRef<HTMLInputElement>(null);
    const [dragging, setDragging] = useState(false);

    const add = (list: FileList | null) => {
        if (list) {
            onChange([...files, ...Array.from(list)].slice(0, 10));
        }
    };

    return (
        <div className="grid gap-2">
            <button
                type="button"
                onClick={() => input.current?.click()}
                onDragOver={(e) => {
                    e.preventDefault();
                    setDragging(true);
                }}
                onDragLeave={() => setDragging(false)}
                onDrop={(e) => {
                    e.preventDefault();
                    setDragging(false);
                    add(e.dataTransfer.files);
                }}
                className={cn(
                    'flex w-full flex-col items-center justify-center gap-1 rounded-lg border border-dashed text-sm text-muted-foreground transition-colors hover:border-brand/60 hover:bg-brand/5',
                    compact ? 'px-4 py-4' : 'px-4 py-7',
                    dragging && 'border-brand bg-brand/5',
                )}
            >
                <FileUp className="size-5 text-brand" />
                <span>
                    <span className="font-medium text-foreground">
                        Click to upload
                    </span>{' '}
                    or drag files here
                </span>
                <span className="text-xs">
                    PDF, Office, images, ZIP · up to 20 MB each
                </span>
            </button>
            <input
                ref={input}
                type="file"
                multiple
                accept={ACCEPTED_FILES}
                className="hidden"
                onChange={(e) => {
                    add(e.target.files);
                    e.target.value = '';
                }}
            />
            {files.length > 0 && (
                <ul className="grid gap-1.5">
                    {files.map((file, index) => (
                        <li
                            key={`${file.name}-${index}`}
                            className="flex items-center justify-between gap-3 rounded-md bg-muted/60 px-3 py-1.5 text-sm"
                        >
                            <span className="truncate">{file.name}</span>
                            <span className="flex shrink-0 items-center gap-2 text-xs text-muted-foreground">
                                {formatBytes(file.size)}
                                <button
                                    type="button"
                                    onClick={() =>
                                        onChange(
                                            files.filter((_, i) => i !== index),
                                        )
                                    }
                                    className="rounded p-0.5 hover:bg-background hover:text-foreground"
                                    aria-label={`Remove ${file.name}`}
                                >
                                    <X className="size-3.5" />
                                </button>
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
