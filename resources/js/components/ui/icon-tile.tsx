import type { LucideProps } from "lucide-react"
import type * as React from "react"

import { cn } from "@/lib/utils"

const SIZES = {
  sm: { tile: "size-6 rounded-sm", icon: "size-4" },
  md: { tile: "size-9 rounded-md", icon: "size-5" },
  lg: { tile: "size-12 rounded-lg", icon: "size-6" },
} as const

/**
 * A thing's identity in a list, one icon on a square
 * (docs/architecture/design-system.md, section 4.48). Decorative: the name
 * beside it is the label.
 */
function IconTile({
  icon: Icon,
  size = "md",
  className,
  ...props
}: Omit<React.ComponentProps<"span">, "children"> & {
  icon: React.ComponentType<LucideProps>
  size?: keyof typeof SIZES
}) {
  return (
    <span
      data-slot="icon-tile"
      aria-hidden="true"
      className={cn(
        "inline-grid shrink-0 place-items-center bg-surface-3 text-ink-2",
        SIZES[size].tile,
        className
      )}
      {...props}
    >
      <Icon className={SIZES[size].icon} />
    </span>
  )
}

export { IconTile }
