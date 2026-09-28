import AppLogoIcon from '@/components/app-logo-icon';
import { cn } from '@/lib/utils';

/**
 * "sterling PAY" lockup: the mark plus the wordmark in the current text colour.
 */
export default function AppWordmark({
    className,
    markVariant = 'color',
    size = 'md',
}: {
    className?: string;
    markVariant?: 'color' | 'light' | 'current';
    size?: 'sm' | 'md' | 'lg';
}) {
    const sizes = {
        sm: { mark: 'size-6', text: 'text-lg', pay: 'text-[0.5rem]' },
        md: { mark: 'size-8', text: 'text-2xl', pay: 'text-[0.6rem]' },
        lg: { mark: 'size-11', text: 'text-4xl', pay: 'text-xs' },
    }[size];

    return (
        <span className={cn('inline-flex items-center gap-2', className)}>
            <AppLogoIcon
                variant={markVariant}
                className={cn('shrink-0', sizes.mark)}
            />
            <span
                className={cn(
                    'relative leading-none font-extrabold tracking-tight',
                    sizes.text,
                )}
            >
                sterling
                <span
                    className={cn(
                        'absolute -top-1 -right-0.5 translate-x-full font-medium tracking-wide',
                        sizes.pay,
                    )}
                >
                    PAY
                </span>
            </span>
        </span>
    );
}
