// Shared labels and helpers for the Announcements screens.
// Times come from the API in UTC (ISO 8601) and are always shown in India time.

export const INDIA_TZ = 'Asia/Kolkata'

export const ANNOUNCEMENT_STATUS = {
  draft: { label: 'Draft', variant: 'secondary', icon: 'bi-pencil' },
  scheduled: { label: 'Scheduled', variant: 'info', icon: 'bi-clock' },
  published: { label: 'Published', variant: 'success', icon: 'bi-broadcast' },
  expired: { label: 'Expired', variant: 'warning', icon: 'bi-hourglass-bottom' },
  recalled: { label: 'Recalled', variant: 'danger', icon: 'bi-arrow-counterclockwise' },
}

export const PRIORITY = {
  normal: { label: 'Normal', variant: 'secondary' },
  important: { label: 'Important', variant: 'warning' },
  urgent: { label: 'Urgent', variant: 'danger' },
}

export const TYPES = [
  { value: 'general', label: 'General' },
  { value: 'hr', label: 'HR' },
  { value: 'policy', label: 'Policy' },
  { value: 'holiday', label: 'Holiday' },
  { value: 'sales', label: 'Sales' },
  { value: 'urgent', label: 'Urgent' },
]

export const PRIORITIES = [
  { value: 'normal', label: 'Normal' },
  { value: 'important', label: 'Important' },
  { value: 'urgent', label: 'Urgent' },
]

export const RECIPIENT_TYPE = { manager: 'Manager', user: 'ERP user', employee: 'Employee' }

export const HISTORY_ACTION = {
  created: 'Created',
  updated: 'Edited',
  scheduled: 'Scheduled',
  unscheduled: 'Schedule cancelled',
  published: 'Published',
  pinned: 'Pinned',
  unpinned: 'Unpinned',
  recalled: 'Recalled',
  expired: 'Expired',
  attachment_added: 'File added',
  attachment_removed: 'File removed',
  deleted: 'Deleted',
}

export const EMPTY_AUDIENCE = { location: [], headquarters: [], managerLevel: [], role: [], employee: [] }

/** "5 Nov 2026, 10:30 am IST" (or a dash when empty). */
export function formatIst(iso) {
  if (!iso) return '\u2014'
  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) return '\u2014'
  return `${date.toLocaleString('en-IN', { timeZone: INDIA_TZ, dateStyle: 'medium', timeStyle: 'short' })} IST`
}

/** ISO (UTC) -> "YYYY-MM-DDTHH:MM" in India time, for a datetime-local field. */
export function toIstInput(iso) {
  if (!iso) return ''
  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) return ''
  const parts = Object.fromEntries(
    new Intl.DateTimeFormat('en-GB', { timeZone: INDIA_TZ, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
      .formatToParts(date)
      .map((part) => [part.type, part.value]),
  )
  return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`
}

/** File size such as "1.2 MB". */
export function formatBytes(bytes) {
  if (!Number.isFinite(bytes)) return ''
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

/** Plain text length of editor HTML (used for the "message is empty" check). */
export function htmlHasText(html) {
  return String(html ?? '').replace(/<[^>]*>/g, '').replace(/&nbsp;/g, ' ').trim().length > 0
}

/** Client-side check before uploading (the server checks the content again). */
export function checkUpload(file, kind, maxBytes = 5 * 1024 * 1024) {
  if (!file) return 'Choose a file.'
  const name = file.name.toLowerCase()
  const allowed = kind === 'banner' ? ['.jpg', '.jpeg', '.png'] : ['.pdf', '.jpg', '.jpeg', '.png']
  if (!allowed.some((ext) => name.endsWith(ext))) {
    return kind === 'banner' ? 'The banner must be a JPG or PNG image.' : 'Only PDF, JPG and PNG files can be attached.'
  }
  if (file.size > maxBytes) return 'The file is larger than 5 MB.'
  if (file.size === 0) return 'The file is empty.'
  return null
}

/** Counts per status tab. */
export function statusCounts(rows) {
  const counts = { all: rows.length, draft: 0, scheduled: 0, published: 0, expired: 0, recalled: 0 }
  rows.forEach((row) => {
    counts[row.status] = (counts[row.status] ?? 0) + 1
  })
  return counts
}

/** History change values shown as text. */
export function changeText(value) {
  if (value === null || value === undefined || value === '') return '\u2014'
  if (typeof value === 'boolean') return value ? 'Yes' : 'No'
  if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}T/.test(value)) return formatIst(value)
  return String(value)
}