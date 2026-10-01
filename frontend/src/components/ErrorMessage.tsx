import common from '../styles/app.module.css'

/**
 * A failed request or action, announced to screen readers; nothing without a message. With onRetry it
 * also offers Try again.
 */
export function ErrorMessage({
  message,
  className,
  onRetry,
}: {
  message?: string
  className?: string
  onRetry?: () => void
}) {
  if (!message) return null
  return (
    <p className={className ?? common.error} role="alert">
      {message}
      {onRetry && (
        <>
          {' '}
          <button className={common.secondary} onClick={onRetry}>
            Try again
          </button>
        </>
      )}
    </p>
  )
}
