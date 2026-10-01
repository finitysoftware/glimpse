import type { ReactNode, RefObject } from 'react'
import { Dialog as Modal } from 'radix-ui'
import { clsx } from 'clsx'
import common from '../styles/app.module.css'
import styles from './Dialog.module.css'
import { useReturnFocus } from './useReturnFocus'
import { ErrorMessage } from './ErrorMessage'

/**
 * Modal dialog. Radix moves focus in, keeps Tab inside and locks page scroll; closing returns focus to
 * the element that opened it. Escape or the dialog's own buttons close it, never a click outside, so a
 * stray click does not lose what was typed into a form; nothing closes it while busy.
 */
export function Dialog({
  title,
  description,
  onClose,
  busy = false,
  alert = false,
  initialFocus,
  fallbackFocus,
  children,
}: {
  title: string
  description?: ReactNode
  onClose: () => void
  busy?: boolean
  /** A question that needs an answer: announced as an alert dialog. */
  alert?: boolean
  /** Focused on open instead of the first focusable element. */
  initialFocus?: RefObject<HTMLElement | null>
  /** Focused on close when the opener is gone, e.g. removed by the dialog's own action. */
  fallbackFocus?: RefObject<HTMLElement | null>
  children: ReactNode
}) {
  const returnFocus = useReturnFocus(fallbackFocus)
  return (
    <Modal.Root
      open
      onOpenChange={open => {
        if (!open && !busy) onClose()
      }}
    >
      <Modal.Portal>
        <Modal.Overlay className={styles.backdrop}>
          <Modal.Content
            className={styles.dialog}
            role={alert ? 'alertdialog' : 'dialog'}
            aria-modal="true"
            // Without a description, Radix wants aria-describedby set to undefined explicitly.
            {...(description === undefined && { 'aria-describedby': undefined })}
            onPointerDownOutside={event => event.preventDefault()}
            onOpenAutoFocus={event => {
              if (!initialFocus) return
              event.preventDefault()
              initialFocus.current?.focus()
            }}
            onCloseAutoFocus={returnFocus}
          >
            <Modal.Title>{title}</Modal.Title>
            {description !== undefined && <Modal.Description>{description}</Modal.Description>}
            {children}
          </Modal.Content>
        </Modal.Overlay>
      </Modal.Portal>
    </Modal.Root>
  )
}

/** Confirmation whose Cancel button has focus first. */
export function ConfirmDialog({
  title,
  message,
  confirmLabel,
  danger = false,
  busy,
  error,
  fallbackFocus,
  onConfirm,
  onCancel,
}: {
  title: string
  message: ReactNode
  confirmLabel: string
  danger?: boolean
  busy: boolean
  error?: string
  /** Focused on close when the confirmed action removed the button that opened the dialog. */
  fallbackFocus?: RefObject<HTMLElement | null>
  onConfirm: () => void
  onCancel: () => void
}) {
  return (
    <Dialog
      title={title}
      description={message}
      onClose={onCancel}
      busy={busy}
      alert
      fallbackFocus={fallbackFocus}
    >
      <ErrorMessage message={error} />
      <div className={common.buttonRow}>
        <button type="button" className={common.secondary} disabled={busy} onClick={onCancel}>
          Cancel
        </button>
        <button
          type="button"
          className={clsx(common.primary, danger && styles.danger)}
          disabled={busy}
          onClick={onConfirm}
        >
          {busy ? 'Working…' : confirmLabel}
        </button>
      </div>
    </Dialog>
  )
}
