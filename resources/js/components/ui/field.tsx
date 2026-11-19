import { OctagonAlertIcon } from "lucide-react"
import * as React from "react"

import { Label } from "@/components/ui/label"
import { __ } from "@/lib/i18n"
import { cn } from "@/lib/utils"

type FieldProps = {
  /** The control's id: the label points at it. */
  htmlFor: string
  label: React.ReactNode
  help?: React.ReactNode
  error?: string
  required?: boolean
  /** The error line's `data-test`, for a test that waits for it. */
  errorTestId?: string
  /** At the label's end, on its line: a small link such as "Forgotten your password?". */
  labelEnd?: React.ReactNode
  className?: string
  /** The control: it receives `aria-describedby` and `aria-invalid`. */
  children: React.ReactElement<{
    "aria-describedby"?: string
    "aria-invalid"?: boolean
  }>
}

/** The help and error lines' ids, joined for `aria-describedby`. */
function describedBy(id: string, help: React.ReactNode, error?: string) {
  const ids = [help ? `${id}-help` : null, error ? `${id}-error` : null]

  return ids.filter(Boolean).join(" ") || undefined
}

function tie(
  children: FieldProps["children"],
  id: string,
  help: React.ReactNode,
  error?: string
) {
  return React.cloneElement(children, {
    "aria-describedby": describedBy(id, help, error),
    "aria-invalid": error ? true : undefined,
  })
}

function FieldHelp({ id, help }: { id: string; help: React.ReactNode }) {
  return help ? (
    <p id={`${id}-help`} className="text-caption text-ink-3">
      {help}
    </p>
  ) : null
}

function FieldError({
  id,
  error,
  testId,
}: {
  id: string
  error?: string
  testId?: string
}) {
  return error ? (
    <p
      id={`${id}-error`}
      data-test={testId}
      className="flex items-start gap-1 text-small text-danger-ink"
    >
      <span className="flex h-lh shrink-0 items-center">
        <OctagonAlertIcon aria-hidden="true" className="size-4" />
      </span>
      {error}
    </p>
  ) : null
}

function RequiredMark({ required }: { required?: boolean }) {
  return required ? (
    <>
      {" "}
      <span className="font-normal text-ink-3">{__("Required")}</span>
    </>
  ) : null
}

/**
 * A form field (docs/architecture/design-system.md, section 4.11): label
 * above, the control, help, then the error, tied to the control.
 */
function Field({
  htmlFor: id,
  label,
  help,
  error,
  required,
  errorTestId,
  labelEnd,
  className,
  children,
}: FieldProps) {
  const labelled = (
    <Label htmlFor={id} className="gap-1">
      {label}
      <RequiredMark required={required} />
    </Label>
  )

  return (
    <div data-slot="field" className={cn("grid gap-1", className)}>
      {labelEnd ? (
        <div className="flex flex-wrap items-baseline justify-between gap-x-4">
          {labelled}
          <span className="text-small">{labelEnd}</span>
        </div>
      ) : (
        labelled
      )}
      {tie(children, id, help, error)}
      <FieldHelp id={id} help={help} />
      <FieldError id={id} error={error} testId={errorTestId} />
    </div>
  )
}

/** A checkbox or switch beside its label, with the help under them. */
function FieldInline({
  htmlFor: id,
  label,
  help,
  error,
  required,
  className,
  children,
}: FieldProps) {
  return (
    <div data-slot="field" className={cn("grid gap-1", className)}>
      <div className="flex items-center gap-2">
        {tie(children, id, help, error)}
        <Label htmlFor={id} className="gap-1 text-body text-ink">
          {label}
          <RequiredMark required={required} />
        </Label>
      </div>
      <FieldHelp id={id} help={help} />
      <FieldError id={id} error={error} />
    </div>
  )
}

export { Field, FieldInline }
