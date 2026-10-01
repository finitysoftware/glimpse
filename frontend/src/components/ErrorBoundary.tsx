import { Component, type ReactNode } from 'react'
import common from '../styles/app.module.css'
import { PageTitle } from './PageTitle'

interface Props {
  children: ReactNode
  /** Another key, usually the address, shows the children again, so the rest of the page keeps working. */
  resetKey?: string
}

interface State {
  failed: boolean
  key: string | undefined
}

/**
 * A view that fails to render, e.g. on data it did not expect, becomes a short message with Reload instead of an
 * empty page. Nothing about the error itself is shown; the browser console keeps it for developers.
 */
export class ErrorBoundary extends Component<Props, State> {
  state: State = { failed: false, key: this.props.resetKey }

  static getDerivedStateFromError(): Partial<State> {
    return { failed: true }
  }

  static getDerivedStateFromProps(props: Props, state: State): Partial<State> | null {
    return props.resetKey === state.key ? null : { failed: false, key: props.resetKey }
  }

  render() {
    return this.state.failed ? <ViewFailure /> : this.props.children
  }
}

/** The message in place of a view that could not be shown. */
export function ViewFailure() {
  return (
    <section role="alert" aria-labelledby="view-failure">
      <PageTitle>Something went wrong</PageTitle>
      <h1 id="view-failure" tabIndex={-1}>
        Something went wrong
      </h1>
      <p>This page could not be shown. Reload to try again.</p>
      <button className={common.primary} onClick={() => window.location.reload()}>
        Reload
      </button>
    </section>
  )
}

/** The whole application in place of a page that could not be shown (the root route's errorElement). */
export function PageFailure() {
  return (
    <main className={common.loginWrap}>
      <div className={common.loginCard}>
        <ViewFailure />
        <p>
          <a href="/app">Open Glimpse</a>
        </p>
      </div>
    </main>
  )
}
