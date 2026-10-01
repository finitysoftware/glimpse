import { useState } from 'react'
import { Navigate, useLocation, useNavigate, useSearchParams } from 'react-router-dom'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import {
  loadSession,
  sessionError,
  workspaceAuth,
  workspaceSessionKey,
  type Session,
} from '../auth/api'
import { QueryScope } from '../../api/QueryScope'
import { StatusPage } from '../../components/StatusPage'
import { Login } from '../auth/Login'
import { WorkspacePanel } from './WorkspacePanel'
import { safeReturnTo, WorkspaceSessionContext, type WorkspaceSession } from '../auth/session'

/** The workspace session, loaded once for /login and /app; afterwards only sign-in and sign-out change it. */
function useSession() {
  const queryClient = useQueryClient()
  const query = useQuery({
    queryKey: workspaceSessionKey,
    queryFn: ({ signal }) => loadSession<Session>(workspaceAuth.session, signal),
    staleTime: Infinity,
  })
  return {
    session: query.data ?? null,
    error: query.error,
    pending: query.isPending,
    setSession: (session: Session | null) => queryClient.setQueryData(workspaceSessionKey, session),
  }
}

export function LoginPage() {
  const { session, error, pending, setSession } = useSession()
  const [params] = useSearchParams()
  const navigate = useNavigate()
  const returnTo = safeReturnTo(params.get('returnTo'))
  if (pending) return <StatusPage>Connecting to Glimpse…</StatusPage>
  if (session) return <Navigate to={returnTo} replace />
  return (
    <Login
      initialError={sessionError(error)}
      onSignedIn={signedIn => {
        setSession(signedIn)
        void navigate(returnTo, { replace: true })
      }}
    />
  )
}

export function AppPage() {
  const { session, pending, setSession } = useSession()
  const location = useLocation()
  // An explicit sign-out goes to a plain sign-in page; only an expired session remembers where the user was.
  const [signedOut, setSignedOut] = useState(false)
  if (pending) return <StatusPage>Connecting to Glimpse…</StatusPage>
  if (!session) {
    const here = location.pathname + location.search + location.hash
    return (
      <Navigate to={signedOut ? '/login' : `/login?returnTo=${encodeURIComponent(here)}`} replace />
    )
  }
  const expire = () => setSession(null)
  const workspace: WorkspaceSession = {
    session,
    expire,
    updateConnection: (platform, summary) => setSession({ ...session, [platform]: summary }),
    replace: setSession,
  }
  // A different account never sees the previous account's views, requests, messages or cached data.
  return (
    <QueryScope key={session.user.id} onUnauthorized={expire}>
      <WorkspaceSessionContext.Provider value={workspace}>
        <WorkspacePanel
          onSignedOut={() => {
            setSignedOut(true)
            setSession(null)
          }}
        />
      </WorkspaceSessionContext.Provider>
    </QueryScope>
  )
}
