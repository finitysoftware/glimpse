import { fireEvent, render, screen, within } from '../render'
import { expect, it, vi } from 'vitest'
import { BranchPermissions } from '../../src/features/repositories/links/BranchPermissions'

vi.mock('../../src/features/repositories/useBranches', async importOriginal => ({
  ...(await importOriginal<typeof import('../../src/features/repositories/useBranches')>()),
  useBranches: () => ({
    data: {
      items: ['main', 'develop', 'feature/gitlab-support'],
      defaultBranch: 'main',
      allowHistory: true,
      nextPage: null,
    },
    loading: false,
  }),
}))

const endpoint = '/api/workspace/repositories/fixture/alpha/browse' as const

it('switches history on and explains per branch what visitors see in it', () => {
  const onChange = vi.fn()
  const scopes = {
    main: { mode: 'selected' as const, count: 3 },
    develop: { mode: 'all' as const },
  }
  const view = render(
    <BranchPermissions
      endpoint={endpoint}
      value={{ branches: ['main', 'develop'], allowHistory: false }}
      onChange={onChange}
      disabled={false}
      scopes={scopes}
    />,
  )
  const history = screen.getByRole('switch', { name: 'Allow history' })
  expect(history).not.toBeChecked()
  expect(history).toHaveAccessibleDescription(
    'Let visitors browse earlier commits and what changed in them.',
  )
  expect(
    screen.getByText('Visitors can read only the current files of the selected branches.'),
  ).toBeInTheDocument()
  expect(screen.getByText('2 selected')).toBeInTheDocument()
  fireEvent.click(history)
  expect(onChange).toHaveBeenCalledWith({ branches: ['main', 'develop'], allowHistory: true })

  view.rerender(
    <BranchPermissions
      endpoint={endpoint}
      value={{ branches: ['main', 'develop'], allowHistory: true }}
      onChange={onChange}
      disabled={false}
      scopes={scopes}
    />,
  )
  expect(screen.getByRole('switch', { name: 'Allow history' })).toBeChecked()
  const rows = within(screen.getByRole('list', { name: 'History by branch' })).getAllByRole(
    'listitem',
  )
  expect(rows.map(row => row.textContent)).toEqual([
    'mainOnly changes to the 3 selected files, including their earlier versions. Commit messages stay hidden.',
    'developAll commits and changes, with commit messages.',
  ])
  view.rerender(
    <BranchPermissions
      endpoint={endpoint}
      value={{ branches: ['main'], allowHistory: true }}
      onChange={onChange}
      disabled={false}
      scopes={{ main: { mode: 'selected', count: 1 } }}
    />,
  )
  expect(screen.getByRole('list', { name: 'History by branch' })).toHaveTextContent(
    'Only changes to the 1 selected file, including its earlier versions.',
  )
})

it('filters loaded branches, marks the default branch and toggles branches', () => {
  const onChange = vi.fn()
  render(
    <BranchPermissions
      endpoint={endpoint}
      value={{ branches: ['main'], allowHistory: false }}
      onChange={onChange}
      disabled={false}
    />,
  )
  const group = screen.getByRole('group', { name: 'Allowed branches' })
  expect(
    within(group)
      .getAllByRole('checkbox')
      .map(box =>
        box.getAttribute('aria-describedby')
          ? `${box.closest('label')?.title}*`
          : box.closest('label')?.title,
      ),
  ).toEqual(['main*', 'develop', 'feature/gitlab-support'])
  expect(screen.getByRole('checkbox', { name: 'main' })).toHaveAccessibleDescription(
    'Default branch',
  )
  // The visible Default tag must not become part of the label text that tools and tests match.
  expect(screen.getByLabelText('main')).toBe(screen.getByRole('checkbox', { name: 'main' }))
  expect(screen.getByText('3 branches')).toBeInTheDocument()

  fireEvent.change(screen.getByRole('searchbox', { name: 'Filter branches' }), {
    target: { value: 'GITLAB' },
  })
  expect(within(group).getAllByRole('checkbox')).toHaveLength(1)
  expect(screen.getByText('1 of 3 shown')).toBeInTheDocument()
  fireEvent.click(screen.getByRole('checkbox', { name: 'feature/gitlab-support' }))
  expect(onChange).toHaveBeenCalledWith({
    branches: ['main', 'feature/gitlab-support'],
    allowHistory: false,
  })
  fireEvent.change(screen.getByRole('searchbox', { name: 'Filter branches' }), {
    target: { value: 'release' },
  })
  expect(screen.getByText('No loaded branch matches “release”.')).toBeInTheDocument()
})
