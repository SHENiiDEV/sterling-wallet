import AppLogoIcon from '@/components/app-logo-icon';

export default function AppLogo() {
    return (
        <>
            <div className="flex aspect-square size-8 items-center justify-center">
                <AppLogoIcon className="size-8" />
            </div>
            <div className="ml-1 grid flex-1 text-left leading-tight">
                <span className="truncate text-[15px] font-extrabold tracking-tight">
                    sterling
                    <span className="ml-0.5 align-super text-[8px] font-medium tracking-wide">
                        PAY
                    </span>
                </span>
                <span className="truncate text-xs text-sidebar-foreground/60">
                    Admin console
                </span>
            </div>
        </>
    );
}
