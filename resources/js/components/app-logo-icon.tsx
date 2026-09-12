import type { SVGAttributes } from 'react';

export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">
            {/* Soft background tile with rounded corners */}
            <rect width="100" height="100" rx="26" fill="#00a5b5" />

            {/* Styled "U" character */}
            <path
                d="M26 24V48C26 61.2548 36.7452 72 50 72C63.2548 72 74 61.2548 74 48V24H60V48C60 53.5228 55.5228 58 50 58C44.4772 58 40 53.5228 40 48V24H26Z"
                fill="white"
            />

            {/* Plus "+" emblem on upper right */}
            <path
                d="M66 22H78V34H66V22Z"
                fill="#ea580c"
                opacity="0"
            />
            {/* Distinctive Orange Plus badge */}
            <rect x="68" y="16" width="16" height="5" rx="2.5" fill="#ea580c" />
            <rect x="73.5" y="10.5" width="5" height="16" rx="2.5" fill="#ea580c" />
        </svg>
    );
}

