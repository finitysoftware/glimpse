import { useId } from 'react'
import {
  ArrowUpRight,
  Eye,
  FileText,
  FolderOpen,
  GitBranch,
  History,
  Info,
  ListChecks,
  Pin,
  TriangleAlert,
} from 'lucide-react'
import type { BranchSummary, LinkPolicy } from './api'
import { plural } from '../../../format'
import {
  branchScope,
  describePolicy,
  effectiveChoice,
  type Summaries,
  type VisibilityChoice,
} from './branchVisibility'
import { OptionCard } from '../OptionCard'
import common from '../Repositories.module.css'
import styles from './Links.module.css'

export function LinkVisibility({
  branches,
  summaries,
  choices,
  onChange,
  existing,
  disabled,
  onConfigure,
  allowHistory = false,
}: {
  branches: string[]
  summaries: Summaries
  choices: Record<string, VisibilityChoice>
  onChange: (choices: Record<string, VisibilityChoice>) => void
  existing?: LinkPolicy[]
  disabled: boolean
  onConfigure: (branch: string) => void
  allowHistory?: boolean
}) {
  const id = useId()
  return (
    <fieldset className={styles.accessSection} disabled={disabled} aria-labelledby={`${id}-title`}>
      <div className={common.sectionHead}>
        <span className={common.sectionIcon}>
          <Eye size={17} />
        </span>
        <div>
          <h3 id={`${id}-title`}>Files visitors can see</h3>
          <p>Choose for each branch: only the files you picked, or the whole branch.</p>
        </div>
        <span className={styles.sectionCount}>{plural(branches.length, 'branch', 'branches')}</span>
      </div>
      {branches.map(branch => (
        <BranchFiles
          key={branch}
          name={`${id}-${branch}`}
          branch={branch}
          loaded={summaries[branch]}
          allowHistory={allowHistory}
          current={existing?.find(policy => policy.branch === branch)}
          choice={effectiveChoice(branch, choices, summaries, existing)}
          onChoose={value => onChange({ ...choices, [branch]: value })}
          onConfigure={() => onConfigure(branch)}
        />
      ))}
      <p className={common.sectionFoot}>
        <Info size={14} />
        The link keeps these rules. Later changes in Files don't affect it until you edit the link.
      </p>
    </fieldset>
  )
}

function BranchFiles({
  name,
  branch,
  loaded,
  current,
  choice,
  allowHistory,
  onChoose,
  onConfigure,
}: {
  name: string
  branch: string
  loaded?: { summary?: BranchSummary; error?: string }
  current?: LinkPolicy
  choice: VisibilityChoice | null
  allowHistory: boolean
  onChoose: (choice: VisibilityChoice) => void
  onConfigure: () => void
}) {
  const summary = loaded?.summary
  const selectable = summary?.mode === 'selected' && summary.selectedCount > 0
  const scope = branchScope(choice, summary, current)
  // Saved rules that already cover the whole branch are the same as Entire branch, so that card shows them.
  const wholeBranch = choice !== 'keep' && scope?.mode === 'all'
  const state = scope && (scope.mode === 'all' ? 'all files' : plural(scope.count, 'selected file'))
  const preview = summary?.preview ?? []
  const more = (summary?.selectedCount ?? 0) - preview.length
  return (
    <div
      className={common.branchFiles}
      role="radiogroup"
      aria-label={`File visibility for ${branch}`}
    >
      <div className={common.branchFilesTop}>
        <span className={common.branchChip}>
          <GitBranch size={13} />
          {branch}
        </span>
        <span className={styles.branchState}>
          {state ? (
            <>
              Visitors see <b>{state}</b>
            </>
          ) : (
            'Choose what visitors see'
          )}
        </span>
      </div>
      <div className={styles.optionCards}>
        {current && (
          <OptionCard
            name={name}
            checked={choice === 'keep'}
            onSelect={() => onChoose('keep')}
            icon={<Pin size={16} />}
            title="Keep current rules"
            description={`${describePolicy(current)}${current.profileVersion ? ` · saved version ${current.profileVersion}` : ''}. Visitors keep exactly what this link shares now.`}
          />
        )}
        {selectable ? (
          <OptionCard
            name={name}
            checked={choice === 'saved' && scope?.mode === 'selected'}
            onSelect={() => onChoose('saved')}
            icon={<ListChecks size={16} />}
            title="Selected files"
            description={
              current
                ? 'Apply the files currently picked in Files. Everything else stays hidden.'
                : 'Only the files you picked in Files. Everything else stays hidden.'
            }
          >
            {preview.length > 0 && (
              <ul className={styles.filePreview} aria-label={`Selected files in ${branch}`}>
                {preview.map(path => (
                  <li key={path}>
                    <FileText size={13} />
                    {path}
                  </li>
                ))}
                {more > 0 && <li className={styles.fileMore}>+ {more.toLocaleString()} more</li>}
              </ul>
            )}
            <div className={styles.optionFoot}>
              {allowHistory && (
                <span className={styles.optionMeta}>
                  <History size={13} />
                  History: only these files
                </span>
              )}
              <button type="button" className={common.optionLink} onClick={onConfigure}>
                Edit in Files <ArrowUpRight size={13} />
              </button>
            </div>
          </OptionCard>
        ) : (
          <OptionCard
            name={name}
            checked={false}
            disabled
            onSelect={() => onChoose('saved')}
            icon={<ListChecks size={16} />}
            title="Selected files"
            description={loaded?.error ?? noSelection(branch, summary)}
          >
            <div className={styles.optionFoot}>
              <button type="button" className={common.optionLink} onClick={onConfigure}>
                Choose files <ArrowUpRight size={13} />
              </button>
            </div>
          </OptionCard>
        )}
        <OptionCard
          name={name}
          checked={wholeBranch}
          onSelect={() => onChoose('all')}
          icon={<FolderOpen size={16} />}
          title="Entire branch"
          description={`Every file in ${branch}, including files added after the link is created.`}
        >
          <p className={common.optionWarning}>
            <TriangleAlert size={14} />
            New files become visible automatically.
          </p>
          {allowHistory && (
            <div className={styles.optionFoot}>
              <span className={styles.optionMeta}>
                <History size={13} />
                History: all commits
              </span>
            </div>
          )}
        </OptionCard>
      </div>
      {choice === null && (
        <p className={common.linkHint} role="status">
          Choose the rules for {branch} before saving.
        </p>
      )}
    </div>
  )
}

/** Why Selected files cannot be chosen for a branch yet. */
function noSelection(branch: string, summary: BranchSummary | undefined): string {
  if (!summary) return 'Loading the saved selection…'
  if (summary.mode === 'all')
    return `Files of ${branch} are set to the whole branch. Pick files in Files to share only part of it.`
  return `No files are selected for ${branch} yet. Pick them in Files to share only part of this branch.`
}
