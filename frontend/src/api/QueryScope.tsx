import { QueryClientProvider } from '@tanstack/react-query'
import { useState, type ReactNode } from 'react'
import { createQueryClient } from './queries'

/**
 * One query cache per signed-in session. It disappears with the session, so another account never
 * sees this one's data, and a 401 from any request of the session calls onUnauthorized in one place.
 * onUnauthorized is read once, when the scope starts, so it has to be a stable callback.
 */
export function QueryScope({
  onUnauthorized,
  children,
}: {
  onUnauthorized?: () => void
  children: ReactNode
}) {
  const [client] = useState(() => createQueryClient(onUnauthorized))
  return <QueryClientProvider client={client}>{children}</QueryClientProvider>
}
