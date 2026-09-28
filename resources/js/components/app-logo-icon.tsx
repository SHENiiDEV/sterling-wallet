import { useId } from 'react';
import type { SVGAttributes } from 'react';

/** The two halves of the Sterling Pay "S" (the second is the first turned 180°). */
const TOP =
    'M858 385H1295L1558 851H1295L1165 622H905C812 622 747 685 747 767C747 805 757 828 787 882L900 1080H637C567 976 525 875 525 767C525 569 690 408 858 385Z';
const BOTTOM =
    'M1075 1545H638L375 1079H638L768 1308H1028C1121 1308 1186 1245 1186 1163C1186 1125 1176 1102 1146 1048L1033 850H1296C1366 954 1408 1055 1408 1163C1408 1361 1243 1522 1075 1545Z';

type Variant = 'color' | 'light' | 'current';

const stops: Record<Exclude<Variant, 'current'>, [string, string]> = {
    color: ['#6E62C4', '#2A1E56'],
    light: ['#F2F0E4', '#CAC7C4'],
};

/**
 * Sterling Pay mark. `color` (default) is the purple gradient for light
 * surfaces, `light` the cream version for dark ones, `current` follows
 * the text colour.
 */
export default function AppLogoIcon({
    variant = 'color',
    ...props
}: SVGAttributes<SVGElement> & { variant?: Variant }) {
    const id = useId();
    const fill = variant === 'current' ? 'currentColor' : `url(#${id})`;

    return (
        <svg
            viewBox="355 365 1223 1200"
            xmlns="http://www.w3.org/2000/svg"
            aria-hidden="true"
            {...props}
        >
            {variant !== 'current' && (
                <defs>
                    <linearGradient
                        id={id}
                        gradientUnits="userSpaceOnUse"
                        x1="0"
                        y1="385"
                        x2="0"
                        y2="1545"
                    >
                        <stop offset="0" stopColor={stops[variant][0]} />
                        <stop offset="1" stopColor={stops[variant][1]} />
                    </linearGradient>
                </defs>
            )}
            <path d={TOP} fill={fill} />
            <path d={BOTTOM} fill={fill} />
        </svg>
    );
}
