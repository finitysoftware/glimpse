import { useId, useState } from 'react'
import { GitBranch, History, Search } from 'lucide-react'
import { clsx } from 'clsx'
import { plural } from '../../../format'
import { chosenOrDefault, useBranches } from '../useBranches'
import type { BranchScope } from './branchVisibility'
import common from '../Repositories.module.css'
import styles from './Links.module.css'

export interface Permissions {
  branches: string[] | null
  allowHistory: boolean
}

/** The API accepts at most this many branches per link. */
const MAX_BRANCHES = 50

export function BranchPermissions({
  endpoint,
  value,
  onChange,
  disabled,
  scopes = {},
}: {
  endpoint: `/api/${string}`
  value: Permissions
  onChange: (value: Permissions) => void
  disabled: boolean
  scopes?: Record<string, BranchScope | null>
}) {
  const id = useId()
  const branches = useBranches(endpoint)
  const [filter, setFilter] = useState('')
  // Until the owner chooses, a link shares the default branch.
  const selected = chosenOrDefault(value.branches, branches.data)
  const choices = [...new Set([...(branches.data?.items ?? []), ...selected])]
  // The filter narrows the branches loaded so far; Load more brings further pages into it.
  const needle = filter.trim().toLowerCase()
  const shown =
    needle === '' ? choices : choices.filter(branch => branch.toLowerCase().includes(needle))
  const toggle = (branch: string, checked: boolean) =>
    onChange({
      ...value,
      branches: checked ? [...selected, branch] : selected.filter(item => item !== branch),
    })
  return (
    <fieldset className={styles.accessSection} disabled={disabled} aria-labelledby={`${id}-title`}>
      <div className={common.sectionHead}>
        <span className={common.sectionIcon}>
          <GitBranch size={17} />
        </span>
        <div>
          <h3 id={`${id}-title`}>Branches visitors can open</h3>
          <p>
            Only the branches you tick are shared. New branches stay hidden until you add them here.
          </p>
        </div>
        <span
          className={clsx(styles.sectionCount, selected.length > 0 && styles.sectionCountActive)}
        >
          {selected.length} selected
        </span>
      </div>
      <div className={common.branchPicker}>
        <div className={styles.branchTools}>
          <label className={common.branchFilter}>
            <Search size={15} />
            <input
              type="search"
              aria-label="Filter branches"
              placeholder="Filter branches"
              value={filter}
              onChange={event => setFilter(event.target.value)}
            />
          </label>
          <span>
            {needle
              ? `${shown.length} of ${choices.length} shown`
              : `${choices.length}${branches.data?.nextPage ? '+' : ''} ${choices.length === 1 ? 'branch' : 'branches'}`}
          </span>
        </div>
        <span id={`${id}-default`} hidden>
          Default branch
        </span>
        <div className={styles.branchTiles} role="group" aria-label="Allowed branches">
          {shown.map(branch => {
            const checked = selected.includes(branch)
            const primary = branch === branches.data?.defaultBranch
            // The Default tag stays outside the label, so the checkbox is named by the branch alone.
            return (
              <div key={branch} className={clsx(styles.branchTile, checked && styles.branchTileOn)}>
                <label title={branch}>
                  <input
                    type="checkbox"
                    checked={checked}
                    disabled={!checked && selected.length >= MAX_BRANCHES}
                    aria-describedby={primary ? `${id}-default` : undefined}
                    onChange={event => toggle(branch, event.target.checked)}
                  />
                  <span className={styles.branchTileName}>{branch}</span>
                </label>
                {primary && (
                  <span className={styles.defaultTag} aria-hidden="true">
                    Default
                  </span>
                )}
              </div>
            )
          })}
        </div>
        {needle !== '' && shown.length === 0 && (
          <p className={common.branchEmpty}>
            No loaded branch matches “{filter.trim()}”.
            {branches.data?.nextPage ? ' Load more branches to search further.' : ''}
          </p>
        )}
        {branches.loading && (
          <p role="status" className={common.branchEmpty}>
            Loading branches…
          </p>
        )}
        {branches.error && (
          <>
            <p role="alert" className={common.branchEmpty}>
              {branches.error}
            </p>
            <button type="button" className={common.secondary} onClick={branches.retry}>
              Retry branches
            </button>
          </>
        )}
        {branches.data?.nextPage && (
          <div className={styles.moreBranches}>
            <button
              type="button"
              className={styles.ghostButton}
              disabled={branches.loading}
              onClick={branches.loadMore}
            >
              Load more branches
            </button>
          </div>
        )}
        {value.branches?.length === 0 && (
          <p className={common.linkHint} role="status">
            Select at least one branch.
          </p>
        )}
        {selected.length >= MAX_BRANCHES && (
          <p className={common.linkHint}>A link can open up to {MAX_BRANCHES} branches.</p>
        )}
      </div>
      <div className={common.historyBlock}>
        <label className={styles.historyToggle}>
          <span className={common.sectionIcon}>
            <History size={17} />
          </span>
          <span className={styles.historyText}>
            <strong id={`${id}-history`}>Allow history</strong>
            <span id={`${id}-history-description`}>
              Let visitors browse earlier commits and what changed in them.
            </span>
          </span>
          <input
            type="checkbox"
            role="switch"
            className={styles.switchInput}
            checked={value.allowHistory}
            aria-labelledby={`${id}-history`}
            aria-describedby={`${id}-history-description`}
            onChange={event => onChange({ ...value, allowHistory: event.target.checked })}
          />
          <span className={styles.switchTrack} aria-hidden="true" />
        </label>
        {!value.allowHistory ? (
          <p className={common.historyNote}>
            Visitors can read only the current files of the selected branches.
          </p>
        ) : (
          selected.length > 0 && (
            <ul className={common.historyScopes} aria-label="History by branch">
              {selected.map(branch => (
                <li key={branch}>
                  <span className={common.branchChip}>
                    <GitBranch size={13} />
                    {branch}
                  </span>
                  <span>{historyScope(scopes[branch])}</span>
                </li>
              ))}
            </ul>
          )
        )}
      </div>
    </fieldset>
  )
}

function historyScope(scope: BranchScope | null | undefined) {
  if (scope?.mode === 'all')
    return (
      <>
        <b>All commits</b> and changes, with commit messages.
      </>
    )
  if (scope?.mode === 'selected') {
    const files = plural(scope.count, 'selected file')
    return (
      <>
        Only changes to the <b>{files}</b>, including {scope.count === 1 ? 'its' : 'their'} earlier
        versions. Commit messages stay hidden.
      </>
    )
  }
  return 'Depends on the file visibility you choose below.'
}
