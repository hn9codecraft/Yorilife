/**
 * JSON client for the PHP API (same origin: Vite proxies /api in development;
 * production serves /api from the same domain).
 *
 * - The session lives in an HttpOnly cookie the browser sends automatically;
 *   JavaScript never sees it.
 * - The CSRF token is held in memory only (never localStorage) and sent on
 *   every state-changing request.
 * - A 401 notifies the auth layer (session expired / signed out elsewhere).
 */

let csrfToken = null
const unauthorizedListeners = new Set()

export function setCsrfToken(token) {
  csrfToken = token ?? null
}

export function onUnauthorized(listener) {
  unauthorizedListeners.add(listener)
  return () => unauthorizedListeners.delete(listener)
}

export class ApiError extends Error {
  constructor({ status, code, message, fields = {}, retryAfter = null }) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.code = code
    this.fields = fields
    this.retryAfter = retryAfter
  }
}

const NETWORK_MESSAGE = 'Cannot reach the server. Check your connection and try again.'
const SERVER_MESSAGE = 'Something went wrong on the server. Please try again in a moment.'

/**
 * @param {string} path e.g. '/api/users'
 * @param {{ method?: string, body?: object, signal?: AbortSignal, notifyUnauthorized?: boolean }} options
 * @returns {Promise<any>} the `data` of a successful response
 */
export async function api(path, { method = 'GET', body, signal, notifyUnauthorized = true } = {}) {
  const headers = { Accept: 'application/json' }
  if (body !== undefined) headers['Content-Type'] = 'application/json'
  if (method !== 'GET' && csrfToken) headers['X-CSRF-Token'] = csrfToken

  let response
  try {
    response = await fetch(path, {
      method,
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
      credentials: 'same-origin',
      signal,
    })
  } catch (error) {
    if (error.name === 'AbortError') throw error
    throw new ApiError({ status: 0, code: 'network_error', message: NETWORK_MESSAGE })
  }

  let payload = null
  try {
    payload = await response.json()
  } catch {
    // Non-JSON response (e.g. proxy error page) — handled below.
  }

  if (response.ok && payload?.success) return payload.data

  const error = new ApiError({
    status: response.status,
    code: payload?.error?.code ?? (response.status >= 500 ? 'server_error' : 'request_failed'),
    // Only messages produced by our API are shown; anything else gets a generic text.
    message: payload?.error?.message ?? (response.status >= 500 || response.status === 0 ? SERVER_MESSAGE : 'The request could not be completed.'),
    fields: payload?.error?.details?.fields ?? {},
    retryAfter: Number(response.headers.get('Retry-After')) || null,
  })

  if (response.status === 401 && notifyUnauthorized) {
    unauthorizedListeners.forEach((listener) => listener(error))
  }
  throw error
}

/**
 * Upload a multipart form (e.g. a CSV file). The browser sets the multipart
 * boundary; the CSRF token is sent as for any state-changing request.
 * @param {string} path
 * @param {FormData} formData
 */
export async function apiUpload(path, formData) {
  const headers = { Accept: 'application/json' }
  if (csrfToken) headers['X-CSRF-Token'] = csrfToken
  let response
  try {
    response = await fetch(path, { method: 'POST', headers, body: formData, credentials: 'same-origin' })
  } catch {
    throw new ApiError({ status: 0, code: 'network_error', message: NETWORK_MESSAGE })
  }
  let payload = null
  try {
    payload = await response.json()
  } catch {
    // handled below
  }
  if (response.ok && payload?.success) return payload.data
  throw failure(response, payload)
}

/**
 * Download a file the API returns as an attachment and hand it to the browser.
 * @param {string} path
 * @param {string} fallbackName used when the response has no file name
 */
export async function apiDownload(path, fallbackName) {
  let response
  try {
    response = await fetch(path, { headers: { Accept: '*/*' }, credentials: 'same-origin' })
  } catch {
    throw new ApiError({ status: 0, code: 'network_error', message: NETWORK_MESSAGE })
  }
  if (!response.ok) {
    let payload = null
    try {
      payload = await response.json()
    } catch {
      // handled below
    }
    throw failure(response, payload)
  }
  const blob = await response.blob()
  const match = /filename="([^"]+)"/.exec(response.headers.get('Content-Disposition') ?? '')
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = match?.[1] ?? fallbackName
  document.body.appendChild(link)
  link.click()
  link.remove()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}

function failure(response, payload) {
  const error = new ApiError({
    status: response.status,
    code: payload?.error?.code ?? (response.status >= 500 ? 'server_error' : 'request_failed'),
    message: payload?.error?.message ?? (response.status >= 500 ? SERVER_MESSAGE : 'The request could not be completed.'),
    fields: payload?.error?.details?.fields ?? {},
  })
  if (response.status === 401) unauthorizedListeners.forEach((listener) => listener(error))
  return error
}
