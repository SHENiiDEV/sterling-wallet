import { useEffect, useRef, useState } from 'react';
import { formatDate } from '@/lib/format';

type Point = { date: string; value: number };

const HEIGHT = 180;
const PAD = { top: 12, right: 8, bottom: 22, left: 56 };

/**
 * Single-series daily bars from a zero baseline (negative days go below
 * it). One hue, thin bars with 4px rounded data ends, a recessive grid and
 * a per-bar hover tooltip. The title of the surrounding card names the
 * series, so there is no legend.
 */
export function BarChart({
    points,
    format,
    label,
    formatLabel = formatDate,
}: {
    points: Point[];
    format: (value: number) => string;
    label: string;
    /** How a bar's date is written (axis and tooltip); days by default. */
    formatLabel?: (date: string) => string;
}) {
    const wrap = useRef<HTMLDivElement>(null);
    const [width, setWidth] = useState(640);
    const [hover, setHover] = useState<number | null>(null);

    useEffect(() => {
        const element = wrap.current;

        if (!element) {
            return;
        }

        const observer = new ResizeObserver(([entry]) =>
            setWidth(Math.max(240, entry.contentRect.width)),
        );
        observer.observe(element);

        return () => observer.disconnect();
    }, []);

    const values = points.map((p) => p.value);
    const max = Math.max(0, ...values);
    const min = Math.min(0, ...values);
    const span = max - min || 1;
    const plotW = width - PAD.left - PAD.right;
    const plotH = HEIGHT - PAD.top - PAD.bottom;
    const y = (v: number) => PAD.top + ((max - v) / span) * plotH;
    const zero = y(0);
    const slot = plotW / Math.max(points.length, 1);
    const barW = Math.max(2, Math.min(18, slot - 2));
    const ticks = [max, (max + min) / 2, min].filter(
        (v, i, a) => a.indexOf(v) === i,
    );
    const xLabels = [
        0,
        Math.floor((points.length - 1) / 2),
        points.length - 1,
    ].filter((i, idx, a) => i >= 0 && a.indexOf(i) === idx);
    const active = hover !== null ? points[hover] : null;

    const barPath = (x: number, v: number) => {
        const top = y(Math.max(v, 0));
        const bottom = y(Math.min(v, 0));
        const h = Math.max(bottom - top, v === 0 ? 0 : 1);
        const r = Math.min(4, barW / 2, h);

        // Round only the data end (away from the baseline).
        return v >= 0
            ? `M${x},${top + h} V${top + r} Q${x},${top} ${x + r},${top} H${x + barW - r} Q${x + barW},${top} ${x + barW},${top + r} V${top + h} Z`
            : `M${x},${top} V${top + h - r} Q${x},${top + h} ${x + r},${top + h} H${x + barW - r} Q${x + barW},${top + h} ${x + barW},${top + h - r} V${top} Z`;
    };

    return (
        <div ref={wrap} className="relative w-full">
            <svg
                width={width}
                height={HEIGHT}
                role="img"
                aria-label={label}
                className="block"
                onMouseLeave={() => setHover(null)}
            >
                {ticks.map((t) => (
                    <g key={t}>
                        <line
                            x1={PAD.left}
                            x2={width - PAD.right}
                            y1={y(t)}
                            y2={y(t)}
                            className="stroke-border"
                            strokeDasharray={t === 0 ? undefined : '2 4'}
                        />
                        <text
                            x={PAD.left - 8}
                            y={y(t)}
                            dy="0.32em"
                            textAnchor="end"
                            className="fill-muted-foreground text-[10px] tabular-nums"
                        >
                            {format(t)}
                        </text>
                    </g>
                ))}
                <line
                    x1={PAD.left}
                    x2={width - PAD.right}
                    y1={zero}
                    y2={zero}
                    className="stroke-muted-foreground/40"
                />
                {points.map((p, i) => {
                    const x = PAD.left + i * slot + (slot - barW) / 2;

                    return (
                        <g key={p.date}>
                            <path
                                d={barPath(x, p.value)}
                                className="fill-chart-1"
                                opacity={
                                    hover === null || hover === i ? 1 : 0.45
                                }
                            />
                            <rect
                                x={PAD.left + i * slot}
                                y={PAD.top}
                                width={slot}
                                height={plotH}
                                fill="transparent"
                                onMouseEnter={() => setHover(i)}
                            />
                        </g>
                    );
                })}
                {xLabels.map((i) => (
                    <text
                        key={i}
                        x={PAD.left + i * slot + slot / 2}
                        y={HEIGHT - 6}
                        textAnchor={
                            i === 0
                                ? 'start'
                                : i === points.length - 1
                                  ? 'end'
                                  : 'middle'
                        }
                        className="fill-muted-foreground text-[10px]"
                    >
                        {formatLabel(points[i].date)}
                    </text>
                ))}
            </svg>
            {active && hover !== null && (
                <div
                    className="pointer-events-none absolute z-10 rounded-md border bg-popover px-2.5 py-1.5 text-xs shadow-md"
                    style={{
                        left: Math.min(
                            Math.max(
                                PAD.left + hover * slot + slot / 2 - 60,
                                0,
                            ),
                            width - 140,
                        ),
                        top: 0,
                    }}
                >
                    <div className="text-muted-foreground">
                        {formatLabel(active.date)}
                    </div>
                    <div className="font-semibold tabular-nums">
                        {format(active.value)}
                    </div>
                </div>
            )}
        </div>
    );
}
