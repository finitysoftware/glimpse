import {
  render as renderWithoutQueries,
  renderHook as renderHookWithoutQueries,
  type RenderHookOptions,
  type RenderOptions,
} from '@testing-library/react'
import type { ReactElement } from 'react'
import { QueryScope } from '../src/api/QueryScope'

export * from '@testing-library/react'

// Testing Library with a fresh query cache for every rendered tree, like a new page load.

export function render(ui: ReactElement, options?: Omit<RenderOptions, 'wrapper'>) {
  return renderWithoutQueries(ui, { wrapper: QueryScope, ...options })
}

export function renderHook<Result, Props>(
  hook: (props: Props) => Result,
  options?: Omit<RenderHookOptions<Props>, 'wrapper'>,
) {
  return renderHookWithoutQueries(hook, { wrapper: QueryScope, ...options })
}
