import { act, render, screen } from '../render'
import userEvent from '@testing-library/user-event'
import { expect, it, vi } from 'vitest'
import { Dialog } from '../../src/components/Dialog'

it('keeps a form dialog and what was typed on a click outside, and closes on Escape', async () => {
  const onClose = vi.fn()
  // The open dialog turns off pointer events on the page behind it; a real click there still reaches the document.
  const user = userEvent.setup({ pointerEventsCheck: 0 })
  render(
    <Dialog title="Add user" onClose={onClose}>
      <label>
        Username
        <input />
      </label>
    </Dialog>,
  )
  const dialog = screen.getByRole('dialog', { name: 'Add user' })
  await user.type(screen.getByRole('textbox', { name: 'Username' }), 'ana.anic')

  // Radix starts listening for clicks outside right after opening.
  await act(() => new Promise(resolve => setTimeout(resolve, 0)))
  await user.pointer({ keys: '[MouseLeft]', target: document.body })
  expect(onClose).not.toHaveBeenCalled()
  expect(dialog).toBeInTheDocument()
  expect(screen.getByRole('textbox', { name: 'Username' })).toHaveValue('ana.anic')

  await user.keyboard('{Escape}')
  expect(onClose).toHaveBeenCalledOnce()
})
