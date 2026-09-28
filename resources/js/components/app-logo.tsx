import AppLogoIcon from '@/components/app-logo-icon';

export default function AppLogo() {
    return (
        <>
            <div className="flex aspect-square size-8 items-center justify-center rounded-lg bg-linear-to-br from-brand to-primary text-white shadow-sm">
                <AppLogoIcon className="size-5" />
            </div>
            <div className="ml-1 grid flex-1 text-left text-sm leading-tight">
                <span className="truncate font-semibold">Sterling</span>
                <span className="truncate text-xs text-sidebar-foreground/60">
                    Admin console
                </span>
            </div>
        </>
    );
}
