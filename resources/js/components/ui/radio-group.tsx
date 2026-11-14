import * as RadioGroupPrimitive from "@radix-ui/react-radio-group"
import * as React from "react"

import { cn } from "@/lib/utils"

/** A set of choices, one at a time (docs/architecture/design-system.md, section 4.9). */
function RadioGroup({
  className,
  ...props
}: React.ComponentProps<typeof RadioGroupPrimitive.Root>) {
  return (
    <RadioGroupPrimitive.Root
      data-slot="radio-group"
      className={cn("grid gap-2", className)}
      {...props}
    />
  )
}

function RadioGroupItem({
  className,
  ...props
}: React.ComponentProps<typeof RadioGroupPrimitive.Item>) {
  return (
    <RadioGroupPrimitive.Item
      data-slot="radio-group-item"
      className={cn(
        "peer flex size-4.5 shrink-0 items-center justify-center rounded-full border-control bg-surface transition-[background-color] duration-(--dur-1) ease-(--ease) outline-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring aria-invalid:border-danger data-[state=checked]:border-transparent data-[state=checked]:bg-accent disabled:cursor-not-allowed disabled:opacity-55",
        className
      )}
      {...props}
    >
      <RadioGroupPrimitive.Indicator
        data-slot="radio-group-indicator"
        className="size-2 rounded-full bg-on-accent"
      />
    </RadioGroupPrimitive.Item>
  )
}

/** A choice with its label, and a description under the label. */
function RadioGroupChoice({
  id,
  label,
  description,
  className,
  ...props
}: React.ComponentProps<typeof RadioGroupPrimitive.Item> & {
  id: string
  label: React.ReactNode
  description?: React.ReactNode
}) {
  return (
    <div className={cn("flex items-start gap-2", className)}>
      <span className="flex h-lh shrink-0 items-center text-body">
        <RadioGroupItem
          id={id}
          aria-describedby={description ? `${id}-description` : undefined}
          {...props}
        />
      </span>
      <div className="grid">
        <label
          htmlFor={id}
          className={cn(
            "text-body text-ink",
            props.disabled && "cursor-not-allowed opacity-55"
          )}
        >
          {label}
        </label>
        {description ? (
          <p id={`${id}-description`} className="text-small text-ink-3">
            {description}
          </p>
        ) : null}
      </div>
    </div>
  )
}

export { RadioGroup, RadioGroupChoice, RadioGroupItem }
