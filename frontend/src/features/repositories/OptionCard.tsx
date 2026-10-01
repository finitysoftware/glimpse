import { useId, type ReactNode } from 'react'
import { Check } from 'lucide-react'
import { clsx } from 'clsx'
import styles from './Repositories.module.css'

/** A radio choice drawn as a card: the transparent radio covers the card, so a click anywhere selects it; buttons inside sit above it. */
export function OptionCard({
  name,
  checked,
  disabled = false,
  onSelect,
  icon,
  title,
  description,
  children,
}: {
  name: string
  checked: boolean
  disabled?: boolean
  onSelect: () => void
  icon: ReactNode
  title: string
  description: string
  children?: ReactNode
}) {
  const id = useId()
  return (
    <div
      className={clsx(
        styles.optionCard,
        checked && styles.optionCardOn,
        disabled && styles.optionCardOff,
      )}
    >
      <label className={styles.optionLabel}>
        <input
          type="radio"
          className={styles.optionInput}
          name={name}
          checked={checked}
          disabled={disabled}
          aria-describedby={`${id}-description`}
          onChange={onSelect}
        />
        <span className={styles.optionIcon}>{icon}</span>
        <strong>{title}</strong>
        <span className={styles.optionRadio} aria-hidden="true">
          {checked && <Check size={12} strokeWidth={3} />}
        </span>
      </label>
      <p id={`${id}-description`} className={styles.optionDescription}>
        {description}
      </p>
      {children}
    </div>
  )
}
