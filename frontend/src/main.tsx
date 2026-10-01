import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { createBrowserRouter, RouterProvider } from 'react-router-dom'
import { QueryClientProvider } from '@tanstack/react-query'
import { App } from './app/App'
import { createQueryClient } from './api/queries'
import { PageFailure } from './components/ErrorBoundary'
import './styles/global.css'

// Loads the workspace and admin sessions; each signed-in session then gets its own QueryScope.
const queryClient = createQueryClient()
// A data router, so a page can hold back navigation (useBlocker) while it has unsaved work; App keeps its own <Routes>.
// A page that fails to render shows a message with Reload instead of the router's developer page.
const router = createBrowserRouter([{ path: '*', element: <App />, errorElement: <PageFailure /> }])

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <QueryClientProvider client={queryClient}>
      <RouterProvider router={router} />
    </QueryClientProvider>
  </StrictMode>,
)
