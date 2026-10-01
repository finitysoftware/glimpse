import { useRef, useState } from 'react'
import {
  Activity,
  ChevronLeft,
  ChevronRight,
  Eye,
  GitBranch as Github,
  LockKeyhole,
  LogOut,
  UserRound,
} from 'lucide-react'
import { Navigate, NavLink, Route, Routes, useLocation } from 'react-router-dom'
import { useMutation } from '@tanstack/react-query'
import { clsx } from 'clsx'
import { errorMessage } from '../../api/client'
import { endSession, workspaceAuth } from '../auth/api'
import { Repositories } from '../repositories/Repositories'
import { GitLabProjectDetail, RepositoryDetail } from '../repositories/RepositoryPage'
import { Account } from './Account'
import { Connections } from './connections/Connections'
import { SignOutHold, SignOutHoldContext, useWorkspaceSession } from '../auth/session'
import common from '../../styles/app.module.css'
import { ErrorBoundary } from '../../components/ErrorBoundary'
import { ErrorMessage } from '../../components/ErrorMessage'
import { PageTitle } from '../../components/PageTitle'
import { useHeadingFocus } from '../../components/useHeadingFocus'

export function WorkspacePanel({ onSignedOut }: { onSignedOut: () => void }) {
  const { session } = useWorkspaceSession()
  const [collapsed, setCollapsed] = useState(() => window.matchMedia('(max-width: 700px)').matches)
  const location = useLocation()
  const main = useRef<HTMLElement>(null)
  useHeadingFocus(main)
  // Unsaved file visibility rules hold sign out until the owner decides about them.
  const [signOutHold] = useState(() => new SignOutHold())
  // Repository views remount when a saved token changes, so no response of an older token remains visible.
  const generation = `${session.user.id}:${session.github.credentialVersion}:${session.gitlab.credentialVersion}`
  // A 401 ends the session through the QueryScope; other failures stay in the sidebar.
  const leave = useMutation({
    mutationFn: () => endSession(workspaceAuth.logout, session.csrfToken),
    onSuccess: onSignedOut,
  })
  const error = errorMessage(leave.error, 'Unable to sign out.')
  const navClass = ({ isActive }: { isActive: boolean }) =>
    clsx(common.navItem, isActive && common.active)
  // An administrator can require a new password; until then only the Account page is available.
  if (session.passwordChangeRequired && location.pathname !== '/app/account')
    return <Navigate to="/app/account?required=1" replace />
  return (
    <div className={common.admin}>
      <a className={common.skipLink} href="#main">
        Skip to content
      </a>
      <aside className={clsx(common.sidebar, collapsed && common.collapsed)}>
        <div className={common.sidebarTop}>
          <NavLink className={common.brand} to="/app" aria-label="Glimpse repositories">
            <span className={common.brandMark}>
              <Eye size={23} />
            </span>
            <span className={common.navLabel}>Glimpse</span>
          </NavLink>
          <button
            className={common.iconButton}
            onClick={() => setCollapsed(!collapsed)}
            aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
            aria-expanded={!collapsed}
          >
            {collapsed ? <ChevronRight size={18} /> : <ChevronLeft size={18} />}
          </button>
        </div>
        <p className={clsx(common.navCaption, common.navLabel)}>WORKSPACE</p>
        <nav aria-label="Workspace">
          <NavLink end to="/app" title="Repositories" className={navClass}>
            <Github size={18} />
            <span className={common.navLabel}>Repositories</span>
          </NavLink>
          <NavLink to="/app/connections" title="Connections" className={navClass}>
            <Activity size={18} />
            <span className={common.navLabel}>Connections</span>
          </NavLink>
          <NavLink to="/app/account" title="Account" className={navClass}>
            <UserRound size={18} />
            <span className={common.navLabel}>Account</span>
          </NavLink>
        </nav>
        <div className={common.sidebarBottom}>
          <button
            className={common.navItem}
            title="Sign out"
            aria-label="Sign out"
            disabled={leave.isPending}
            onClick={() => signOutHold.signOut(() => leave.mutate())}
          >
            <LogOut size={18} />
            <span className={common.navLabel}>{leave.isPending ? 'Signing out…' : 'Sign out'}</span>
          </button>
          <div className={common.user}>
            <span className={common.avatar} aria-hidden="true">
              {session.user.username.charAt(0).toUpperCase()}
            </span>
            <div className={common.navLabel}>
              <strong>{session.user.username}</strong>
              <small>Private workspace</small>
            </div>
          </div>
        </div>
      </aside>
      <main id="main" ref={main} className={common.main} tabIndex={-1}>
        <div className={common.content}>
          <ErrorMessage message={error} />
          {/* A page that fails to render leaves the sidebar working; another address tries again. */}
          <SignOutHoldContext.Provider value={signOutHold}>
            <ErrorBoundary resetKey={location.pathname + location.search}>
              <Routes>
                <Route index element={<Repositories key={generation} />} />
                <Route
                  path="repositories/:owner/:repository"
                  element={<RepositoryDetail key={generation} csrfToken={session.csrfToken} />}
                />
                <Route
                  path="gitlab/:projectId"
                  element={<GitLabProjectDetail key={generation} csrfToken={session.csrfToken} />}
                />
                <Route path="connections" element={<Connections />} />
                <Route path="account" element={<Account />} />
                <Route
                  path="*"
                  element={
                    <>
                      <PageTitle>Page not found</PageTitle>
                      <h1 tabIndex={-1}>Page not found</h1>
                      <NavLink to="/app">Open repositories</NavLink>
                    </>
                  }
                />
              </Routes>
            </ErrorBoundary>
          </SignOutHoldContext.Provider>
          <footer className={common.footer}>
            <span>Glimpse</span>
            <span>
              <LockKeyhole size={12} /> Private by design
            </span>
          </footer>
        </div>
      </main>
    </div>
  )
}
