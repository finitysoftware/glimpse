import { fireEvent, render, screen, within } from '../render'
import { expect, it, vi } from 'vitest'
import { LinkVisibility } from '../../src/features/repositories/links/LinkVisibility'
import type { Summaries } from '../../src/features/repositories/links/branchVisibility'

const summaries: Summaries = {
  main: {
    summary: {
      branch: 'main',
      mode: 'selected',
      version: 5,
      selectedCount: 7,
      preview: ['README.md', 'docs/čitaj me.txt', 'src/app.ts', 'src/main.ts', 'src/util.ts'],
    },
  },
  develop: {
    summary: { branch: 'develop', mode: null, version: 0, selectedCount: 0, preview: [] },
  },
  release: {
    summary: { branch: 'release', mode: 'all', version: 2, selectedCount: 0, preview: [] },
  },
}
const group = (branch: string) =>
  within(screen.getByRole('radiogroup', { name: `File visibility for ${branch}` }))

it('shows the saved selection as a card with its files and lets the owner pick the whole branch instead', () => {
  const onChange = vi.fn()
  const onConfigure = vi.fn()
  render(
    <LinkVisibility
      branches={['main', 'develop', 'release']}
      summaries={summaries}
      choices={{}}
      onChange={onChange}
      disabled={false}
      onConfigure={onConfigure}
    />,
  )
  expect(screen.getByRole('group', { name: 'Files visitors can see' })).toHaveTextContent(
    '3 branches',
  )

  const main = group('main')
  expect(main.getByRole('radio', { name: 'Selected files' })).toBeChecked()
  expect(main.getByRole('radio', { name: 'Selected files' })).toHaveAccessibleDescription(
    'Only the files you picked in Files. Everything else stays hidden.',
  )
  expect(main.getByText('Visitors see', { exact: false })).toHaveTextContent(
    'Visitors see 7 selected files',
  )
  expect(main.getAllByRole('listitem').map(item => item.textContent)).toEqual([
    'README.md',
    'docs/čitaj me.txt',
    'src/app.ts',
    'src/main.ts',
    'src/util.ts',
    '+ 2 more',
  ])
  expect(main.queryByText('History: only these files')).not.toBeInTheDocument()
  fireEvent.click(main.getByRole('button', { name: 'Edit in Files' }))
  expect(onConfigure).toHaveBeenCalledWith('main')
  fireEvent.click(main.getByRole('radio', { name: 'Entire branch' }))
  expect(onChange).toHaveBeenCalledWith({ main: 'all' })

  // Nothing selected yet: the selection card explains why it is unavailable, and the branch still needs a choice.
  const develop = group('develop')
  expect(develop.getByRole('radio', { name: 'Selected files' })).toBeDisabled()
  expect(develop.getByRole('radio', { name: 'Selected files' })).toHaveAccessibleDescription(
    'No files are selected for develop yet. Pick them in Files to share only part of this branch.',
  )
  expect(develop.getByRole('radio', { name: 'Entire branch' })).not.toBeChecked()
  expect(develop.getByRole('status')).toHaveTextContent(
    'Choose the rules for develop before saving.',
  )
  expect(develop.getByText('Choose what visitors see')).toBeInTheDocument()
  fireEvent.click(develop.getByRole('button', { name: 'Choose files' }))
  expect(onConfigure).toHaveBeenLastCalledWith('develop')

  // Saved rules that already open the whole branch are shown as Entire branch.
  expect(group('release').getByRole('radio', { name: 'Entire branch' })).toBeChecked()
  expect(group('release').getByText('Visitors see', { exact: false })).toHaveTextContent(
    'Visitors see all files',
  )
})

it('offers the current rules of an edited link and names what history shows', () => {
  const onChange = vi.fn()
  render(
    <LinkVisibility
      branches={['main']}
      summaries={summaries}
      choices={{}}
      onChange={onChange}
      disabled={false}
      onConfigure={vi.fn()}
      allowHistory
      existing={[{ branch: 'main', mode: 'selected', selectedCount: 2, profileVersion: 3 }]}
    />,
  )
  const main = group('main')
  expect(main.getByRole('radio', { name: 'Keep current rules' })).toBeChecked()
  expect(main.getByRole('radio', { name: 'Keep current rules' })).toHaveAccessibleDescription(
    /^Selected files only · 2 files · saved version 3\./,
  )
  expect(main.getByText('Visitors see', { exact: false })).toHaveTextContent(
    'Visitors see 2 selected files',
  )
  expect(main.getByRole('radio', { name: 'Selected files' })).toHaveAccessibleDescription(
    'Apply the files currently picked in Files. Everything else stays hidden.',
  )
  expect(main.getByText('History: only these files')).toBeInTheDocument()
  expect(main.getByText('History: all commits')).toBeInTheDocument()
  fireEvent.click(main.getByRole('radio', { name: 'Selected files' }))
  expect(onChange).toHaveBeenCalledWith({ main: 'saved' })
})
