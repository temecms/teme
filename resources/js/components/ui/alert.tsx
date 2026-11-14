import { cva, type VariantProps } from "class-variance-authority"
import * as React from "react"

import { cn } from "@/lib/utils"

// The Notice look (docs/architecture/design-system.md, section 4.22): the
// icon sits at the start, the words in a column beside it.
const alertVariants = cva(
  "relative grid w-full gap-1 rounded-md px-5 py-4 text-body text-ink soft-edge has-[>svg]:ps-13 [&>svg]:absolute [&>svg]:start-5 [&>svg]:top-4 [&>svg]:size-5",
  {
    variants: {
      variant: {
        default: "bg-info-soft [&>svg]:text-info-ink",
        destructive: "bg-danger-soft [&>svg]:text-danger-ink",
      },
    },
    defaultVariants: {
      variant: "default",
    },
  }
)

function Alert({
  className,
  variant,
  ...props
}: React.ComponentProps<"div"> & VariantProps<typeof alertVariants>) {
  return (
    <div
      data-slot="alert"
      role="alert"
      className={cn(alertVariants({ variant }), className)}
      {...props}
    />
  )
}

function AlertTitle({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="alert-title"
      className={cn(
        "line-clamp-1 font-semibold",
        className
      )}
      {...props}
    />
  )
}

function AlertDescription({
  className,
  ...props
}: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="alert-description"
      className={cn(
        "grid justify-items-start gap-1 text-body text-ink",
        className
      )}
      {...props}
    />
  )
}

export { Alert, AlertTitle, AlertDescription }
