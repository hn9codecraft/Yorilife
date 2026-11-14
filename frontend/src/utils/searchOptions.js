/**
 * Filtering for SearchableSelect (and any other type-to-filter list).
 * Options are { value, label, keywords? }. Every word typed must appear in the
 * label or keywords (any letter case, accents ignored). Labels that start with
 * the query come first, then labels with a word starting with it, then the rest;
 * the original order is kept inside each group. Only `limit` items are returned
 * so very large lists stay fast; `total` says how many matched.
 */

export const DEFAULT_RESULT_LIMIT = 100

export function normalizeSearch(text) {
  return String(text ?? '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .replace(/\s+/g, ' ')
    .trim()
}

function haystackOf(option) {
  const keywords = Array.isArray(option.keywords) ? option.keywords.join(' ') : option.keywords
  return normalizeSearch(`${option.label ?? ''} ${keywords ?? ''}`)
}

/** @returns {{ items: Array, total: number }} */
export function filterOptions(options, query, limit = DEFAULT_RESULT_LIMIT) {
  const list = options ?? []
  const needle = normalizeSearch(query)
  const max = Math.max(0, limit)
  if (!needle) return { items: list.slice(0, max), total: list.length }

  const words = needle.split(' ')
  const starts = []
  const wordStarts = []
  const contains = []
  for (const option of list) {
    const haystack = haystackOf(option)
    if (!words.every((word) => haystack.includes(word))) continue
    const label = normalizeSearch(option.label)
    if (label.startsWith(needle)) starts.push(option)
    else if (` ${haystack}`.includes(` ${words[0]}`)) wordStarts.push(option)
    else contains.push(option)
  }
  const ranked = starts.concat(wordStarts, contains)
  return { items: ranked.slice(0, max), total: ranked.length }
}

/** Next highlighted index for arrow keys, wrapping at both ends; -1 when the list is empty. */
export function moveActiveIndex(current, delta, count) {
  if (count <= 0) return -1
  if (current < 0) return delta > 0 ? 0 : count - 1
  return (current + delta + count) % count
}