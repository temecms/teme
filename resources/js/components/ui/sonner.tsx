import { Toaster as Sonner, type ToasterProps } from 'sonner';
import { useAppearance } from '@/hooks/use-appearance';
import { useFlashToast } from '@/hooks/use-flash-toast';

/**
 * Where toasts appear (docs/architecture/design-system.md, section 4.21):
 * at the foot of the screen in the middle, 24px up (16 on a phone), three at
 * once. Every toast is drawn by flash-toast.tsx; Sonner supplies the live
 * region, the stacking and the timers.
 */
function Toaster({ ...props }: ToasterProps) {
    const { appearance } = useAppearance();

    useFlashToast();

    return (
        <Sonner
            theme={appearance}
            className="toaster group"
            position="bottom-center"
            offset={24}
            mobileOffset={16}
            visibleToasts={3}
            style={
                {
                    '--width': '400px',
                    // Sonner's own toasts, should one be drawn without flash-toast.tsx.
                    '--normal-bg': 'var(--surface)',
                    '--normal-text': 'var(--ink)',
                    '--normal-border': 'var(--line)',
                } as React.CSSProperties
            }
            {...props}
        />
    );
}

export { Toaster };
