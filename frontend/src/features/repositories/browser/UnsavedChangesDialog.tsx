import { Dialog } from '../../../components/Dialog'
import common from '../Repositories.module.css'

export type UnsavedChoice = 'save' | 'discard' | 'stay'

/** Unsaved visibility rules are never dropped without a decision: save them, discard them or stay. */
export function UnsavedChangesDialog({
  branch,
  before,
  saving,
  onChoose,
}: {
  branch: string
  /** What waits for the decision, e.g. 'opening develop' or 'closing file visibility'. */
  before: string
  saving: boolean
  onChoose: (choice: UnsavedChoice) => void
}) {
  return (
    <Dialog
      title="Unsaved visibility changes"
      description={`Save the changes for ${branch} before ${before}?`}
      onClose={() => onChoose('stay')}
      busy={saving}
      alert
    >
      <div className={common.actions}>
        <button
          type="button"
          className={common.primary}
          disabled={saving}
          onClick={() => onChoose('save')}
        >
          Save
        </button>
        <button
          type="button"
          className={common.secondary}
          disabled={saving}
          onClick={() => onChoose('discard')}
        >
          Discard
        </button>
        <button
          type="button"
          className={common.secondary}
          disabled={saving}
          onClick={() => onChoose('stay')}
        >
          Stay
        </button>
      </div>
    </Dialog>
  )
}
