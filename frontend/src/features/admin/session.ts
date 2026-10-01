import { createContext, useContext } from 'react'
import type { AdminSession } from './api'

export interface AdminSessionValue {
  session: AdminSession
  /** The server rejected the admin session: show the sign-in form again. */
  expire: () => void
  /** Takes over a renewed session (after a password change). */
  replace: (session: AdminSession) => void
  signOut: () => void
}

export const AdminSessionContext = createContext<AdminSessionValue | null>(null)

export function useAdminSession(): AdminSessionValue {
  const value = useContext(AdminSessionContext)
  if (value === null) throw new Error('Admin views need a signed-in admin session.')
  return value
}

/** Only admin panel paths are accepted after sign-in. */
export function safeAdminReturnTo(value: string | null): string {
  if (value === null || !/^\/admin(?:[/?#]|$)/.test(value) || value.startsWith('/admin/login'))
    return '/admin'
  return value
}
