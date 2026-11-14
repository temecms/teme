import { UserIcon } from "lucide-react"
import type * as React from "react"

import { useInitials } from "@/hooks/use-initials"
import { __ } from "@/lib/i18n"
import { cn } from "@/lib/utils"

const SIZES = {
  24: "size-6 text-caption",
  40: "size-10 text-small",
  96: "size-24 text-title",
} as const

/**
 * A person's picture on the screens about people
 * (docs/architecture/design-system.md, section 4.49): the picture they gave,
 * or their initials; a dashed ring and a person's outline for someone
 * invited who has not joined. Its words are the person's name, or nothing
 * when the name is written beside it.
 */
function PersonPicture({
  name,
  src,
  size = 40,
  invited = false,
  nameBeside = false,
  className,
  ...props
}: Omit<React.ComponentProps<"span">, "children"> & {
  name: string
  src?: string | null
  size?: keyof typeof SIZES
  invited?: boolean
  /** The name is written beside the picture, so the picture says nothing. */
  nameBeside?: boolean
}) {
  const getInitials = useInitials()
  const label = invited ? __(":name, invited", { name }) : name
  // A picture carries its own words; initials and the invited mark are named on the circle.
  const picture = !invited && src ? src : null
  const named = !nameBeside && picture === null

  return (
    <span
      data-slot="person-picture"
      role={named ? "img" : undefined}
      aria-label={named ? label : undefined}
      aria-hidden={nameBeside && picture === null ? true : undefined}
      className={cn(
        "inline-grid shrink-0 place-items-center overflow-hidden rounded-full font-semibold",
        invited
          ? "border border-dashed border-edge bg-transparent text-ink-3"
          : "bg-surface-3 text-ink-2",
        SIZES[size],
        className
      )}
      {...props}
    >
      {invited ? (
        <UserIcon aria-hidden="true" className="size-4.5" />
      ) : picture !== null ? (
        <img src={picture} alt={nameBeside ? "" : name} className="size-full object-cover" />
      ) : (
        <span aria-hidden="true">{getInitials(name)}</span>
      )}
    </span>
  )
}

export { PersonPicture }
