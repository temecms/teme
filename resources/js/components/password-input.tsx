import { Eye, EyeOff } from 'lucide-react';
import type { ComponentProps, Ref } from 'react';
import { useId, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { __ } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * A password input with a labeled show and hide button at its end
 * (docs/architecture/design-system.md, section 4.44), reached by Tab like
 * any other control. It changes the input's type and nothing else.
 */
export default function PasswordInput({
    className,
    ref,
    id,
    ...props
}: Omit<ComponentProps<'input'>, 'type'> & { ref?: Ref<HTMLInputElement> }) {
    const [showPassword, setShowPassword] = useState(false);
    const ownId = useId();
    const inputId = id ?? ownId;

    return (
        <div className="relative">
            <Input
                id={inputId}
                type={showPassword ? 'text' : 'password'}
                className={cn('pe-12', className)}
                ref={ref}
                {...props}
            />
            <Button
                type="button"
                variant="ghost"
                size="icon-sm"
                onClick={() => setShowPassword((shown) => !shown)}
                className="absolute inset-y-0 end-1 my-auto"
                aria-label={
                    showPassword ? __('Hide password') : __('Show password')
                }
                aria-pressed={showPassword}
                aria-controls={inputId}
            >
                {showPassword ? (
                    <EyeOff aria-hidden="true" />
                ) : (
                    <Eye aria-hidden="true" />
                )}
            </Button>
        </div>
    );
}
