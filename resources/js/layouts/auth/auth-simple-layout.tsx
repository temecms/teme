import { SignInBrand } from '@/components/brand';
import type { AuthLayoutProps } from '@/types';

/**
 * The sign-in screens' frame: the site's mark, then the screen's title and
 * line, then the form, and at the foot a quiet credit with Teme's own icon,
 * as WordPress and Craft credit themselves. A `main`, so the page is one
 * landmark with one heading.
 */
export default function AuthSimpleLayout({
    children,
    title,
    description,
    brandName,
}: AuthLayoutProps) {
    return (
        <main className="bg-background flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <div className="w-full max-w-sm">
                <div className="flex flex-col gap-8">
                    <div className="flex flex-col items-center gap-6">
                        <SignInBrand name={brandName} />
                        <div className="space-y-1 text-center">
                            <h1 className="text-[22px] leading-tight font-semibold tracking-[-0.015em]">
                                {title}
                            </h1>
                            <p className="text-muted-foreground text-[13px]">
                                {description}
                            </p>
                        </div>
                    </div>
                    {children}
                </div>
            </div>
            <p
                data-test="powered-by"
                className="text-muted-foreground flex items-center gap-1.5 text-[11px]"
            >
                <img
                    src="/teme/icon.svg"
                    alt=""
                    aria-hidden="true"
                    width={14}
                    height={14}
                />
                Powered by Teme
            </p>
        </main>
    );
}
