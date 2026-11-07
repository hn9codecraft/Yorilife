/** Mask an email for a CSV cell. The screen still shows the real address. */
export function maskEmail(value) {
  const text = String(value ?? '').trim()
  const at = text.lastIndexOf('@')
  if (at <= 0 || at === text.length - 1) return text === '' ? '' : '***'
  return `${text.slice(0, 1)}***@${text.slice(at + 1)}`
}

/** Keep the last four digits of a phone number. Shorter numbers are hidden. */
export function maskPhone(value) {
  const text = String(value ?? '')
  const digits = text.replace(/\D/g, '')
  if (digits.length === 0) return text
  if (digits.length < 8) return text.replace(/\d/g, '*')
  let seen = 0
  const hide = digits.length - 4
  return text.replace(/\d/g, (digit) => {
    seen += 1
    return seen <= hide ? '*' : digit
  })
}

/** Mask email and phone columns. Other columns stay as entered. */
export function maskRecord(record) {
  const next = { ...record }
  Object.keys(next).forEach((key) => {
    const name = key.toLowerCase()
    if (name.includes('email')) next[key] = maskEmail(next[key])
    else if (name.includes('phone') || name.includes('mobile')) next[key] = maskPhone(next[key])
  })
  return next
}
