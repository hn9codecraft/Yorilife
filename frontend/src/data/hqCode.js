/** Headquarters codes are `HQ-` plus a numeric string, so `001` stays `001`. */

export function hqCodeSuffix(code) {
  if (typeof code !== 'string' || !code.startsWith('HQ-')) return null
  const suffix = code.slice(3)
  return /^\d+$/.test(suffix) ? suffix : null
}

/** One past the highest HQ- suffix. Deleted gaps stay unused while a higher suffix remains. */
export function nextHqSequence(headquarters) {
  let max = 0
  for (const office of headquarters) {
    const suffix = hqCodeSuffix(office.code ?? '')
    if (suffix === null) continue
    const value = Number(suffix)
    if (value > max) max = value
  }
  return String(max + 1).padStart(3, '0')
}
