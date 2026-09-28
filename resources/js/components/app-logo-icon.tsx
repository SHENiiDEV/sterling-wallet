import type { SVGAttributes } from 'react';

export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth={2.4}
            strokeLinecap="round"
            xmlns="http://www.w3.org/2000/svg"
            {...props}
        >
            <path d="M17 7.2C15.9 5.8 14.1 5 12 5 9 5 7 6.6 7 8.7c0 4.8 10 2.6 10 7.3 0 2-2.1 3.5-5 3.5-2.2 0-4-.8-5.2-2.3" />
            <path d="M12 2.6V5M12 19.5v1.9" />
        </svg>
    );
}
