import { Toaster as Sonner, type ToasterProps } from 'sonner';
import { useAppearance } from '@/hooks/use-appearance';
import { useFlashToast } from '@/hooks/use-flash-toast';

/**
 * Where toasts appear: bottom right, 16px in from both edges, three at once.
 * Every toast is drawn by flash-toast.tsx; Sonner supplies the live region,
 * the stacking and the timers.
 */
function Toaster({ ...props }: ToasterProps) {
    const { appearance } = useAppearance();

    useFlashToast();

    return (
        <Sonner
            theme={appearance}
            className="toaster group"
            position="bottom-right"
            offset={16}
            mobileOffset={16}
            visibleToasts={3}
            style={{ '--width': '400px' } as React.CSSProperties}
            {...props}
        />
    );
}

export { Toaster };
