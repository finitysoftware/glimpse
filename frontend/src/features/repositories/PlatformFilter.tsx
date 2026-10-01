import { useId } from 'react'
import { DropdownMenu } from 'radix-ui'
import { Check, ChevronDown } from 'lucide-react'
import { platformLabels, type Platform } from '../../api/platforms'
import styles from './Repositories.module.css'

/**
 * Checkbox menu of the connected platforms, styled like the page-language picker; the last selected
 * platform cannot be cleared. Radix handles the arrow keys, Escape, clicks outside and focus. The menu
 * is not modal and stays open while choosing, so the list behind it updates in view.
 */
export function PlatformFilter({
  available,
  selected,
  onChange,
}: {
  available: Platform[]
  selected: Platform[]
  onChange: (next: Platform[]) => void
}) {
  const labelId = useId()
  const summary =
    selected.length === available.length
      ? 'All platforms'
      : selected.map(platform => platformLabels[platform]).join(', ')
  const toggle = (platform: Platform) =>
    onChange(
      selected.includes(platform)
        ? selected.filter(item => item !== platform)
        : available.filter(item => item === platform || selected.includes(item)),
    )
  return (
    <div className={styles.platformPicker}>
      <span id={labelId} className={styles.visuallyHidden}>
        Platforms
      </span>
      <DropdownMenu.Root modal={false}>
        <DropdownMenu.Trigger
          className={styles.platformTrigger}
          aria-labelledby={`${labelId} ${labelId}-value`}
        >
          <span id={`${labelId}-value`}>{summary}</span>
          <ChevronDown size={15} aria-hidden="true" />
        </DropdownMenu.Trigger>
        <DropdownMenu.Portal>
          <DropdownMenu.Content
            className={styles.platformMenu}
            aria-labelledby={labelId}
            align="end"
            sideOffset={9}
            collisionPadding={16}
          >
            {available.map(platform => {
              const checked = selected.includes(platform)
              const locked = checked && selected.length === 1
              return (
                <DropdownMenu.CheckboxItem
                  key={platform}
                  className={styles.platformOption}
                  checked={checked}
                  disabled={locked}
                  title={locked ? 'At least one platform stays selected' : undefined}
                  onCheckedChange={() => toggle(platform)}
                  onSelect={event => event.preventDefault()}
                >
                  <span className={styles.platformBox} aria-hidden="true">
                    {checked && <Check size={13} />}
                  </span>
                  {platformLabels[platform]}
                </DropdownMenu.CheckboxItem>
              )
            })}
          </DropdownMenu.Content>
        </DropdownMenu.Portal>
      </DropdownMenu.Root>
    </div>
  )
}
