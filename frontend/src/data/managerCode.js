/** Employee codes are `ER-M{level}-` plus a numeric string, so `001` stays `001`. */

export function managerCodePrefix(level) {
  return `ER-M${level}-`
}

/** Digits after the prefix, or null when the stored code uses another pattern. */
export function managerCodeSuffix(level, code) {
  const prefix = managerCodePrefix(level)
  if (typeof code !== 'string' || !code.startsWith(prefix)) return null
  const suffix = code.slice(prefix.length)
  return /^\d+$/.test(suffix) ? suffix : null
}

/**
 * Next free number for this level. Uses the highest existing suffix, not the
 * row count, so a deleted code is not offered again while a higher one remains.
 * The result is always at least three digits.
 */
export function nextManagerSequence(managers, level) {
  let max = 0
  for (const manager of managers) {
    if (Number(manager.level) !== Number(level)) continue
    const suffix = managerCodeSuffix(level, manager.code ?? '')
    if (suffix === null) continue
    const value = Number(suffix)
    if (value > max) max = value
  }
  return String(max + 1).padStart(3, '0')
}
