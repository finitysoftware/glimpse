import { lazy, Suspense } from 'react'
import { Navigate, Route, Routes } from 'react-router-dom'
import { AppPage, LoginPage } from '../features/workspace/WorkspaceGate'
import { SharePage } from '../features/sharing/SharePage'
import { PageTitle } from '../components/PageTitle'
import { StatusPage } from '../components/StatusPage'
import common from '../styles/app.module.css'

// The admin panel is a separate chunk, so visitors of shared links never download it.
const AdminApp = lazy(() => import('../features/admin/AdminApp'))

export function App() {
  return (
    <Routes>
      <Route path="/" element={<Navigate to="/app" replace />} />
      <Route path="/login" element={<LoginPage />} />
      <Route path="/app/*" element={<AppPage />} />
      <Route
        path="/admin/*"
        element={
          <Suspense fallback={<StatusPage>Loading the admin panel…</StatusPage>}>
            <AdminApp />
          </Suspense>
        }
      />
      <Route path="/s/:publicId/*" element={<SharePage />} />
      <Route path="*" element={<NotFound />} />
    </Routes>
  )
}

function NotFound() {
  return (
    <main className={common.loginWrap}>
      <div className={common.loginCard}>
        <PageTitle>Page not found</PageTitle>
        <h1>Page not found</h1>
        <p>This page is not available.</p>
        <a href="/app">Open Glimpse</a>
      </div>
    </main>
  )
}
