import { useState } from 'react'
import { Search } from 'lucide-react'
import { connectionLabels } from '../../../api/platforms'
import type { UserQuery } from '../api'
import common from '../../../styles/app.module.css'
import styles from '../Admin.module.css'

const states = Object.entries(connectionLabels)

/** Search and the status, role, GitHub, GitLab and sort choices above the user table. */
export function UserFilters({
  filters,
  onChange,
}: {
  filters: UserQuery
  onChange: (change: Partial<UserQuery>) => void
}) {
  const [search, setSearch] = useState(filters.q)
  // Back and Forward change the search in the address; the field follows it.
  const [shown, setShown] = useState(filters.q)
  if (filters.q !== shown) {
    setShown(filters.q)
    setSearch(filters.q)
  }
  return (
    <div className={styles.toolbar}>
      <form
        className={styles.search}
        role="search"
        onSubmit={event => {
          event.preventDefault()
          onChange({ q: search.trim() })
        }}
      >
        <input
          className={common.textField}
          aria-label="Search users"
          placeholder="Search by name, email or username…"
          value={search}
          maxLength={100}
          onChange={event => setSearch(event.target.value)}
        />
        <button className={common.secondary} type="submit">
          <Search size={16} /> Search
        </button>
      </form>
      <FilterSelect
        label="Status"
        value={filters.status}
        onChange={status => onChange({ status })}
        options={[
          ['', 'All statuses'],
          ['active', 'Active'],
          ['disabled', 'Deactivated'],
        ]}
      />
      <FilterSelect
        label="Role"
        value={filters.role}
        onChange={role => onChange({ role })}
        options={[
          ['', 'All roles'],
          ['admin', 'Admins'],
          ['user', 'Users'],
        ]}
      />
      <FilterSelect
        label="GitHub"
        value={filters.github}
        onChange={github => onChange({ github })}
        options={[['', 'Any GitHub state'], ...states]}
      />
      <FilterSelect
        label="GitLab"
        value={filters.gitlab}
        onChange={gitlab => onChange({ gitlab })}
        options={[['', 'Any GitLab state'], ...states]}
      />
      <FilterSelect
        label="Sort"
        value={filters.sort}
        onChange={sort => onChange({ sort })}
        options={[
          ['', 'Sort by name'],
          ['username', 'Sort by username'],
          ['created', 'Newest first'],
          ['last_sign_in', 'Last sign-in'],
        ]}
      />
    </div>
  )
}

function FilterSelect({
  label,
  value,
  options,
  onChange,
}: {
  label: string
  value: string
  /** [value, text] pairs. */
  options: [string, string][]
  onChange: (value: string) => void
}) {
  return (
    <select
      className={common.textField}
      aria-label={label}
      value={value}
      onChange={event => onChange(event.target.value)}
    >
      {options.map(([option, text]) => (
        <option key={option} value={option}>
          {text}
        </option>
      ))}
    </select>
  )
}
