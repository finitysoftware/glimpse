import { useId } from 'react'
import {
  CircleAlert,
  CircleHelp,
  Eye,
  FolderOpen,
  GitBranch,
  ListChecks,
  Pencil,
  Plus,
  Save,
  Trash2,
  Undo2,
  X,
} from 'lucide-react'
import { clsx } from 'clsx'
import { OptionCard } from '../OptionCard'
import type { Visibility } from './useVisibility'
import { plural } from '../../../format'
import common from '../Repositories.module.css'
import styles from './Visibility.module.css'

/** Mode, progress and warnings of one branch's visibility. Checkboxes live in the Tree; the save bar below the files saves or discards the draft. */
export function VisibilityPanel({
  visibility,
  onClose,
}: {
  visibility: Visibility
  onClose: () => void
}) {
  const { profile } = visibility
  const modeName = useId()
  const status = statusOf(visibility)
  const total = profile?.fileCount ?? 0
  // The striped part of the bar shows files that become visible only after saving.
  const pending =
    visibility.mode === 'selected' && !visibility.changes.modeChanged
      ? Math.min(visibility.visible, visibility.changes.added.length)
      : 0
  const percent = (value: number) => `${total > 0 ? Math.min(100, (value / total) * 100) : 0}%`
  return (
    <section className={common.visibilityPanel} aria-label="File visibility">
      <div className={styles.visibilityHead}>
        <span className={common.sectionIcon}>
          <Eye size={17} />
        </span>
        <div className={common.visibilityTitle}>
          <h2>
            File visibility{' '}
            <span className={common.branchChip}>
              <GitBranch size={13} />
              {visibility.branch}
            </span>
          </h2>
          <p>What visitors of new links can open in this branch. You always see every file.</p>
        </div>
        <div className={styles.visibilityHeadActions}>
          {profile && (
            <span className={clsx(styles.statusPill, status.tone)}>
              <span className={styles.statusDot} />
              {status.text}
            </span>
          )}
          <button type="button" className={common.secondary} onClick={onClose}>
            <X size={15} /> Close
          </button>
        </div>
      </div>
      {visibility.loading && (
        <p role="status" className={styles.visibilityNote}>
          Loading file visibility…
        </p>
      )}
      {/* Errors of a pending save appear in the save bar, next to the buttons that caused them. */}
      {!visibility.dirty && <VisibilityError visibility={visibility} className={common.error} />}
      {profile && (
        <>
          <fieldset className={common.modeCards} disabled={visibility.saving}>
            <legend className={common.visuallyHidden}>Visitors can open</legend>
            <OptionCard
              name={modeName}
              checked={visibility.mode === 'selected'}
              onSelect={() => visibility.setMode('selected')}
              icon={<ListChecks size={16} />}
              title="Selected files"
              description="Only the files you tick below. New files stay hidden."
            />
            <OptionCard
              name={modeName}
              checked={visibility.mode === 'all'}
              onSelect={() => visibility.setMode('all')}
              icon={<FolderOpen size={16} />}
              title="Entire branch"
              description="Every file, including files added later."
            />
          </fieldset>
          <div className={styles.visibilityProgress}>
            <p role="status" aria-live="polite">
              <b>
                {visibility.mode === 'all'
                  ? `All ${total.toLocaleString()} files`
                  : `${visibility.visible.toLocaleString()} of ${plural(total, 'file')}`}
              </b>{' '}
              visible to visitors
            </p>
            <div className={styles.progressBar} aria-hidden="true">
              <i style={{ width: percent(visibility.visible - pending) }} />
              {pending > 0 && (
                <i className={styles.progressPending} style={{ width: percent(pending) }} />
              )}
            </div>
          </div>
          {visibility.mode === 'selected' && (
            <details className={styles.visibilityHelp}>
              <summary>
                <CircleHelp size={14} />
                How new files behave
              </summary>
              <ul>
                <li>
                  <Plus size={14} />
                  New files stay hidden until you tick them - even inside a folder you selected.
                </li>
                <li>
                  <Pencil size={14} />A renamed file gets a new path, so it stays hidden until you
                  tick it again.
                </li>
                <li>
                  <Trash2 size={14} />A deleted file that comes back under the same path is visible
                  again.
                </li>
              </ul>
            </details>
          )}
          {profile.treeChanged && (
            <p className={common.notice}>
              Files on this branch changed since these rules were saved. Check new files before
              creating links.
            </p>
          )}
          {profile.missing.length > 0 && (
            <p className={common.notice}>
              {profile.missing.length} selected{' '}
              {profile.missing.length === 1 ? 'path is' : 'paths are'} no longer on this branch.
              They stay in the rules and become visible again only if the same path returns.
            </p>
          )}
          {profile.truncated ? (
            <p className={common.notice}>
              The complete file list of this branch was not available, so individual selections
              cannot be verified. Choose Entire branch.
            </p>
          ) : (
            profile.manifest === null && (
              <p className={common.notice}>
                This branch has more than 20,000 files. Folder checkboxes are unavailable; select
                individual files or choose Entire branch.
              </p>
            )
          )}
          {visibility.message && (
            <p role="status" className={styles.visibilityMessage}>
              {visibility.message}
            </p>
          )}
        </>
      )}
    </section>
  )
}

/** Appears only while the draft differs from the saved rules; says what saving changes. */
export function VisibilitySaveBar({ visibility }: { visibility: Visibility }) {
  return (
    <div className={styles.saveBar} role="region" aria-label="Unsaved changes">
      <p className={styles.saveBarText}>
        <CircleAlert size={16} />
        <b>{unsavedLabel(visibility)}</b>
        <span>{describeChanges(visibility)} · applies to links created after saving</span>
      </p>
      <VisibilityError visibility={visibility} className={styles.saveBarError} />
      <div className={styles.saveBarActions}>
        <button
          type="button"
          className={common.secondary}
          disabled={visibility.saving}
          onClick={visibility.cancel}
        >
          <Undo2 size={15} /> Discard
        </button>
        <button
          type="button"
          className={common.primary}
          disabled={visibility.saving}
          onClick={() => {
            void visibility.save()
          }}
        >
          <Save size={15} /> {visibility.saving ? 'Saving…' : 'Save visibility'}
        </button>
      </div>
    </div>
  )
}

/** A failed load or save; a conflict (someone else saved first) offers loading the rules again. */
function VisibilityError({
  visibility,
  className,
}: {
  visibility: Visibility
  className?: string
}) {
  if (!visibility.error) return null
  return (
    <div className={className} role="alert">
      <p>{visibility.error}</p>
      {visibility.conflict && (
        <button type="button" className={common.secondary} onClick={visibility.reload}>
          Reload rules and files
        </button>
      )}
    </div>
  )
}

function statusOf(visibility: Visibility): { tone: string | undefined; text: string } {
  if (visibility.saving) return { tone: styles.statusProgress, text: 'Saving…' }
  if (visibility.dirty) return { tone: styles.statusPending, text: unsavedLabel(visibility) }
  if (visibility.profile?.mode === null)
    return { tone: styles.statusMuted, text: 'Not configured yet' }
  return { tone: styles.statusSaved, text: 'Saved' }
}

function unsavedLabel(visibility: Visibility): string {
  const count = visibility.changes.added.length + visibility.changes.removed.length
  if (visibility.changes.modeChanged || count === 0) return 'Unsaved changes'
  return plural(count, 'unsaved change')
}

function describeChanges(visibility: Visibility): string {
  const { modeChanged, added, removed } = visibility.changes
  if (modeChanged)
    return visibility.mode === 'all'
      ? `Visitors will see every file in ${visibility.branch}`
      : 'Visitors will see only the files you tick'
  const parts = []
  if (added.length === 1) parts.push(`${added[0]} becomes visible`)
  if (added.length > 1) parts.push(`${added.length.toLocaleString()} files become visible`)
  if (removed.length === 1) parts.push(`${removed[0]} becomes hidden`)
  if (removed.length > 1) parts.push(`${removed.length.toLocaleString()} files become hidden`)
  return parts.length > 0 ? parts.join(', ') : 'The selection is back to the saved rules'
}
