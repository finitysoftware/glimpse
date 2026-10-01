import { useState, type RefObject } from 'react'

/**
 * Close focus for Radix dialogs that open from ordinary buttons. Radix returns focus only to its own
 * Trigger, so this remembers the element focused when the dialog opened and focuses it again on close,
 * or the fallback when that element is gone.
 */
export function useReturnFocus(fallback?: RefObject<HTMLElement | null>) {
  const [opener] = useState(() =>
    document.activeElement instanceof HTMLElement ? document.activeElement : null,
  )
  return (event: Event) => {
    event.preventDefault()
    const target = opener?.isConnected ? opener : fallback?.current
    target?.focus()
  }
}
