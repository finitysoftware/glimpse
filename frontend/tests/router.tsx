import { createContext, useContext, useState, type ReactNode } from 'react'
import { createMemoryRouter, RouterProvider, type InitialEntry } from 'react-router-dom'

// MemoryRouter as the app runs it: a data router (createBrowserRouter in main.tsx), so useBlocker works in tests too.

const Content = createContext<ReactNode>(null)

function Page() {
  return <>{useContext(Content)}</>
}

/**
 * Renders its children, which may hold <Routes>, at the last of initialEntries (the earlier ones are there for Back);
 * a rerender shows new children.
 */
export function MemoryRouter({
  initialEntries,
  children,
}: {
  initialEntries?: InitialEntry[]
  children: ReactNode
}) {
  const [router] = useState(() =>
    createMemoryRouter([{ path: '*', element: <Page /> }], { initialEntries }),
  )
  return (
    <Content.Provider value={children}>
      <RouterProvider router={router} />
    </Content.Provider>
  )
}
