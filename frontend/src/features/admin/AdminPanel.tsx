import { useRef, useState } from 'react'
import {
  ChevronLeft,
  ChevronRight,
  Eye,
  LayoutDashboard,
  Link2,
  LockKeyhole,
  LogOut,
  SquareArrowOutUpRight,
  UserRound,
  Users as UsersIcon,
} from 'lucide-react'
import { Navigate, NavLink, Route, Routes, useLocation } from 'react-router-dom'
import { clsx } from 'clsx'
import { useMutation } from '@tanstack/react-query'
import { errorMessage } from '../../api/client'
import { endSession } from '../auth/api'
import common from '../../styles/app.module.css'
import { adminAuth } from './api'
import { displayName } from './format'
import { AdminAccount } from './AdminAccount'
import { Dashboard } from './Dashboard'
import { Links } from './Links'
import { useAdminSession } from './session'
import { Users } from './users/Users'
import { ErrorBoundary } from '../../components/ErrorBoundary'
import { ErrorMessage } from '../../components/ErrorMessage'
import { useHeadingFocus } from '../../components/useHeadingFocus'

const SIDEBAR_KEY = 'admin-sidebar'

function initialCollapsed(): boolean {
  try {
    const saved = window.localStorage.getItem(SIDEBAR_KEY)
    if (saved !== null) return saved === 'collapsed'
  } catch {
    /* Storage may be unavailable; fall back to the viewport. */
  }
  return window.matchMedia('(max-width: 700px)').matches
}

export function AdminPanel() {
  const { session, signOut } = useAdminSession()
  const location = useLocation()
  const main = useRef<HTMLElement>(null)
  useHeadingFocus(main)
  const [collapsed, setCollapsed] = useState(initialCollapsed)
  const name = displayName(session.admin)

  function toggle() {
    setCollapsed(previous => {
      try {
        window.localStorage.setItem(SIDEBAR_KEY, previous ? 'open' : 'collapsed')
      } catch {
        /* The state still changes for this tab. */
      }
      return !previous
    })
  }
  // A 401 ends the session through the QueryScope; other failures stay in the sidebar.
  const leave = useMutation({
    mutationFn: () => endSession(adminAuth.logout, session.csrfToken),
    onSuccess: signOut,
  })
  const error = errorMessage(leave.error, 'Unable to sign out.')
  // A required password change comes first; only the Account page is available until then.
  if (session.passwordChangeRequired && location.pathname !== '/admin/account')
    return <Navigate to="/admin/account?required=1" replace />
  const navClass = ({ isActive }: { isActive: boolean }) =>
    clsx(common.navItem, isActive && common.active)
  return (
    <div className={common.admin}>
      <a className={common.skipLink} href="#main">
        Skip to content
      </a>
      <aside className={clsx(common.sidebar, collapsed && common.collapsed)}>
        <div className={common.sidebarTop}>
          <NavLink className={common.brand} to="/admin" aria-label="Glimpse admin dashboard">
            <span className={common.brandMark}>
              <Eye size={23} />
            </span>
            <span className={common.navLabel}>Admin panel</span>
          </NavLink>
          <button
            className={common.iconButton}
            onClick={toggle}
            aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
            aria-expanded={!collapsed}
          >
            {collapsed ? <ChevronRight size={18} /> : <ChevronLeft size={18} />}
          </button>
        </div>
        <p className={clsx(common.navCaption, common.navLabel)}>ADMINISTRATION</p>
        <nav aria-label="Admin panel">
          <NavLink end to="/admin" title="Dashboard" className={navClass}>
            <LayoutDashboard size={18} />
            <span className={common.navLabel}>Dashboard</span>
          </NavLink>
          <NavLink to="/admin/users" title="Users" className={navClass}>
            <UsersIcon size={18} />
            <span className={common.navLabel}>Users</span>
          </NavLink>
          <NavLink to="/admin/links" title="Access links" className={navClass}>
            <Link2 size={18} />
            <span className={common.navLabel}>Access links</span>
          </NavLink>
          <NavLink to="/admin/account" title="Account" className={navClass}>
            <UserRound size={18} />
            <span className={common.navLabel}>Account</span>
          </NavLink>
        </nav>
        <div className={common.sidebarBottom}>
          <a className={common.navItem} href="/app" title="Open workspace">
            <SquareArrowOutUpRight size={18} />
            <span className={common.navLabel}>Open workspace</span>
          </a>
          <button
            className={common.navItem}
            title="Sign out"
            aria-label="Sign out"
            disabled={leave.isPending}
            onClick={() => leave.mutate()}
          >
            <LogOut size={18} />
            <span className={common.navLabel}>{leave.isPending ? 'Signing out…' : 'Sign out'}</span>
          </button>
          <div
            className={common.user}
            title={
              collapsed ? `${name} - ${session.admin.email ?? session.admin.username}` : undefined
            }
          >
            <span className={common.avatar} aria-hidden="true">
              {name.charAt(0).toUpperCase()}
            </span>
            <div className={common.navLabel}>
              <strong>{name}</strong>
              <small>{session.admin.email ?? session.admin.username}</small>
            </div>
          </div>
        </div>
      </aside>
      <main id="main" ref={main} className={common.main} tabIndex={-1}>
        <div className={common.content}>
          <ErrorMessage message={error} />
          {/* A page that fails to render leaves the sidebar working; another address tries again. */}
          <ErrorBoundary resetKey={location.pathname + location.search}>
            <Routes>
              <Route index element={<Dashboard />} />
              <Route path="users" element={<Users />} />
              <Route path="links" element={<Links />} />
              <Route path="account" element={<AdminAccount />} />
              <Route path="*" element={<Navigate to="/admin" replace />} />
            </Routes>
          </ErrorBoundary>
          <footer className={common.footer}>
            <span>Glimpse admin panel</span>
            <span>
              <LockKeyhole size={12} /> Administrators only
            </span>
          </footer>
        </div>
      </main>
    </div>
  )
}
