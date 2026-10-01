import { useState } from 'react'
import {
  Navigate,
  Route,
  Routes,
  useLocation,
  useNavigate,
  useSearchParams,
} from 'react-router-dom'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { loadSession, sessionError } from '../auth/api'
import { QueryScope } from '../../api/QueryScope'
import { StatusPage } from '../../components/StatusPage'
import { Login } from '../auth/Login'
import common from '../../styles/app.module.css'
import { adminAuth, adminKeys, type AdminSession } from './api'
import { AdminPanel } from './AdminPanel'
import { AdminSessionContext, safeAdminReturnTo, type AdminSessionValue } from './session'

/** The admin panel under /admin: its own session (cookie glimpse_platform_admin), loaded once for all admin routes. */
export default function AdminApp() {
  const queryClient = useQueryClient()
  const { data, error, isPending } = useQuery({
    queryKey: adminKeys.session,
    queryFn: ({ signal }) => loadSession<AdminSession>(adminAuth.session, signal),
    staleTime: Infinity,
  })
  const session = data ?? null
  // An explicit sign-out goes to a plain sign-in page; only an expired session remembers where the admin was.
  const [signedOut, setSignedOut] = useState(false)
  const setSession = (next: AdminSession | null) =>
    queryClient.setQueryData(adminKeys.session, next)
  if (isPending) return <StatusPage>Connecting to Glimpse…</StatusPage>
  const value: AdminSessionValue | null = session && {
    session,
    expire: () => setSession(null),
    replace: setSession,
    signOut: () => {
      setSignedOut(true)
      setSession(null)
    },
  }
  return (
    <Routes>
      <Route
        path="login"
        element={
          <AdminLogin
            session={session}
            error={sessionError(error)}
            onSignedIn={signedIn => {
              setSignedOut(false)
              setSession(signedIn)
            }}
          />
        }
      />
      <Route
        path="*"
        element={
          value ? (
            <QueryScope key={value.session.admin.id} onUnauthorized={value.expire}>
              <AdminSessionContext.Provider value={value}>
                <AdminPanel />
              </AdminSessionContext.Provider>
            </QueryScope>
          ) : (
            <SignInRedirect signedOut={signedOut} />
          )
        }
      />
    </Routes>
  )
}

function AdminLogin({
  session,
  error,
  onSignedIn,
}: {
  session: AdminSession | null
  error: string
  onSignedIn: (session: AdminSession) => void
}) {
  const [params] = useSearchParams()
  const navigate = useNavigate()
  const returnTo = safeAdminReturnTo(params.get('returnTo'))
  if (session) return <Navigate to={returnTo} replace />
  // Accounts without the Admin role get the same generic error here; they sign in to the workspace instead.
  return (
    <Login<AdminSession>
      endpoint={adminAuth.login}
      pageTitle="Admin sign-in"
      subtitle="Sign in to the Glimpse admin panel."
      footnote="Only accounts with the Admin role"
      extra={
        <p className={common.loginFootnote}>
          Not an administrator? <a href="/login">Sign in to the workspace</a>
        </p>
      }
      initialError={error}
      onSignedIn={signedIn => {
        onSignedIn(signedIn)
        void navigate(returnTo, { replace: true })
      }}
    />
  )
}

function SignInRedirect({ signedOut }: { signedOut: boolean }) {
  const location = useLocation()
  return (
    <Navigate
      to={
        signedOut
          ? '/admin/login'
          : `/admin/login?returnTo=${encodeURIComponent(location.pathname + location.search)}`
      }
      replace
    />
  )
}
