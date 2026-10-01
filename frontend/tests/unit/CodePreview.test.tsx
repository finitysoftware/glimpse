import { act, render, screen } from '../render'
import { afterEach, expect, it, vi } from 'vitest'
import { CodePreview } from '../../src/features/repositories/syntax/CodePreview'
import {
  HIGHLIGHT_TIMEOUT_MS,
  type HighlightResult,
} from '../../src/features/repositories/syntax/protocol'

class TestWorker {
  static instances: TestWorker[] = []
  onmessage: ((event: { data: HighlightResult }) => void) | null = null
  onerror: (() => void) | null = null
  onmessageerror: (() => void) | null = null
  terminate = vi.fn()
  postMessage = vi.fn()
  constructor() {
    TestWorker.instances.push(this)
  }
  respond(text: string) {
    this.onmessage?.({ data: { tokens: [{ text, className: 'hljs-keyword' }] } })
  }
}

afterEach(() => {
  TestWorker.instances = []
  vi.useRealTimers()
})

it('shows text immediately, colors it asynchronously and discards stale worker results', () => {
  vi.stubGlobal('Worker', TestWorker)
  const { container, rerender } = render(
    <CodePreview path="App.cs" content="using System;" bytes={13} />,
  )
  expect(container.querySelector('pre')).toHaveTextContent('using System;')
  expect(container.querySelector('span')).toBeNull()
  const previous = TestWorker.instances[0]!
  rerender(<CodePreview path="View.xaml" content="<Window />" bytes={10} />)
  expect(previous.terminate).toHaveBeenCalled()
  act(() => previous.respond('OLD FILE'))
  expect(container.querySelector('pre')?.textContent).toBe('<Window />')
  act(() => TestWorker.instances[1]!.respond('<Window />'))
  expect(container.querySelector('.hljs-keyword')?.textContent).toBe('<Window />')
  expect(container.querySelector('window')).toBeNull()
  expect(screen.getByText(/XML \/ XAML/)).toBeVisible()
})

it('keeps unknown formats as plain text without starting a worker', () => {
  vi.stubGlobal('Worker', TestWorker)
  const { container } = render(
    <CodePreview path="README.txt" content="<script>danger()</script>" bytes={25} />,
  )
  expect(container.querySelector('pre')?.textContent).toBe('<script>danger()</script>')
  expect(container.querySelector('script')).toBeNull()
  expect(TestWorker.instances).toHaveLength(0)
  expect(screen.getByText(/Plain text/)).toBeVisible()
})

it('terminates a slow worker and preserves the complete original text', () => {
  vi.useFakeTimers()
  vi.stubGlobal('Worker', TestWorker)
  const { container } = render(<CodePreview path="App.cs" content="using System;" bytes={13} />)
  act(() => vi.advanceTimersByTime(HIGHLIGHT_TIMEOUT_MS))
  expect(TestWorker.instances[0]!.terminate).toHaveBeenCalled()
  expect(container.querySelector('pre')?.textContent).toBe('using System;')
  expect(screen.getByText(/C# · Plain text/)).toBeVisible()
})

it('falls back when workers cannot start', async () => {
  vi.stubGlobal(
    'Worker',
    class {
      constructor() {
        throw new Error('Workers blocked')
      }
    },
  )
  const { container } = render(<CodePreview path="App.cs" content="using System;" bytes={13} />)
  expect(await screen.findByText(/C# · Plain text/)).toBeVisible()
  expect(container.querySelector('pre')?.textContent).toBe('using System;')
})
