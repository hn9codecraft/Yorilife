/** Stockist codes are `ST-` plus a numeric string, so `001` stays `001`. */

export function stCodeSuffix(code) {
  if (typeof code !== 'string' || !code.toUpperCase().startsWith('ST-')) return null
  const suffix = code.slice(3)
  return /^\d+$/.test(suffix) ? suffix : null
}

/** One past the highest ST- suffix. Older codes such as DEMO-STK-01 are left out. */
export function nextStSequence(stockists) {
  let max = 0
  for (const stockist of stockists) {
    const suffix = stCodeSuffix(stockist.code ?? '')
    if (suffix === null) continue
    const value = Number(suffix)
    if (value > max) max = value
  }
  return String(max + 1).padStart(3, '0')
}
