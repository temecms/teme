import { Slot } from "@radix-ui/react-slot"
import { cva, type VariantProps } from "class-variance-authority"
import * as React from "react"

import { Spinner } from "@/components/ui/spinner"
import { cn } from "@/lib/utils"

// docs/architecture/design-system.md, section 4.1. Today's variant and size
// names stay as aliases, each drawn the design system's way.
const buttonVariants = cva(
  "inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md font-medium transition-[background-color,color,scale] duration-(--dur-1) ease-(--ease) active:scale-(--press) disabled:cursor-not-allowed disabled:opacity-55 disabled:active:scale-100 aria-busy:cursor-progress aria-busy:[&>svg:not(.animate-spin)]:hidden [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-4 [&_svg]:shrink-0 outline-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring aria-invalid:outline-2 aria-invalid:outline-danger aria-pressed:bg-accent-soft aria-pressed:text-on-accent-soft",
  {
    variants: {
      variant: {
        default: "bg-accent text-on-accent hover:bg-accent-hover",
        primary: "bg-accent text-on-accent hover:bg-accent-hover",
        neutral: "bg-surface-3 text-ink hover:bg-surface-4",
        secondary: "bg-surface-3 text-ink hover:bg-surface-4",
        outline: "bg-surface-3 text-ink hover:bg-surface-4",
        ghost: "bg-transparent text-ink-2 hover:bg-surface-3 hover:text-ink",
        destructive: "bg-danger text-on-danger hover:bg-danger-hover",
        danger: "bg-danger text-on-danger hover:bg-danger-hover",
        "danger-quiet":
          "bg-transparent text-danger-ink hover:bg-danger-soft",
        link: "h-auto min-h-6 bg-transparent px-0 text-accent-ink underline decoration-transparent underline-offset-3 hover:decoration-current",
      },
      size: {
        default: "h-(--ctl-h) px-4 text-body",
        md: "h-(--ctl-h) px-4 text-body",
        lg: "h-(--ctl-h) px-4 text-body",
        sm: "h-(--ctl-h-sm) px-3 text-small",
        xs: "h-(--ctl-h-xs) px-2 text-small",
        icon: "size-(--ctl-h) max-sm:min-h-11 max-sm:min-w-11",
        "icon-md": "size-(--ctl-h) max-sm:min-h-11 max-sm:min-w-11",
        "icon-sm": "size-(--ctl-h-sm) max-sm:min-h-11 max-sm:min-w-11",
        "icon-xs": "size-(--ctl-h-xs) max-sm:min-h-11 max-sm:min-w-11",
      },
    },
    compoundVariants: [
      // A link-looking button keeps the words' own height, whatever its size.
      { variant: "link", className: "h-auto px-0" },
    ],
    defaultVariants: {
      variant: "default",
      size: "default",
    },
  }
)

function Button({
  className,
  variant,
  size,
  asChild = false,
  loading = false,
  children,
  onClick,
  ...props
}: React.ComponentProps<"button"> &
  VariantProps<typeof buttonVariants> & {
    asChild?: boolean
    /** Keeps the button's width, shows a spinner and ignores clicks while its action runs (section 4). */
    loading?: boolean
  }) {
  const Comp = asChild ? Slot : "button"

  return (
    <Comp
      data-slot="button"
      className={cn(buttonVariants({ variant, size, className }))}
      aria-busy={loading || undefined}
      onClick={
        loading
          ? (event: React.MouseEvent<HTMLButtonElement>) => event.preventDefault()
          : onClick
      }
      {...props}
    >
      {loading && !asChild ? (
        <>
          <Spinner aria-hidden="true" role="presentation" aria-label={undefined} />
          {children}
        </>
      ) : (
        children
      )}
    </Comp>
  )
}

export { Button, buttonVariants }
