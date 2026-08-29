/** Shared Kanban types — reusable across Projects and future boards. */

export type KanbanItem = {
  id: number
  code: string
  title: string
  status: string
  priority: string
  assignee_id: number | null
  attachments_count: number
  due_date: string | null
  due_date_formatted: string | null
  is_overdue: boolean
  /** List-view extras (optional for pure board cards). */
  type?: string
  assignee?: string | null
}

export type KanbanColumnDef = {
  key: string
  label: string
}

export type KanbanUserOption = {
  label: string
  value: number
}

export const PRIORITY_LABEL: Record<string, string> = {
  low: 'Low',
  medium: 'Medium',
  high: 'High',
  urgent: 'Urgent',
}

export const TYPE_LABEL: Record<string, string> = {
  task: 'Task',
  bug: 'Bug',
  story: 'Story',
}

export const PRIORITY_CLASS: Record<string, string> = {
  low: 'text-ink-600',
  medium: 'text-ink-900',
  high: 'text-signal-warning font-medium',
  urgent: 'text-signal-danger font-semibold',
}

/** Overdue first, then soonest due date; undated sink to bottom. */
export function sortKanbanItems(items: KanbanItem[]): KanbanItem[] {
  return [...items].sort((a, b) => {
    if (a.is_overdue !== b.is_overdue) return a.is_overdue ? -1 : 1
    return (a.due_date ?? '9999-12-31').localeCompare(b.due_date ?? '9999-12-31')
  })
}
