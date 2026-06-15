/**
 * Upload multipart via fetch — evita problemas do axios com Content-Type/boundary.
 */
export async function postFormData(path, formData, { method = 'POST' } = {}) {
  const baseURL = (import.meta.env.VITE_API_URL || 'http://localhost:8000/api/v1').replace(/\/+$/, '')
  const token = localStorage.getItem('auth_token')
  const headers = { Accept: 'application/json' }

  if (token) {
    headers.Authorization = `Bearer ${token}`
  }

  const response = await fetch(`${baseURL}${path}`, {
    method,
    body: formData,
    headers,
    credentials: 'include',
  })

  let data = {}

  try {
    data = await response.json()
  } catch {
    data = {}
  }

  if (!response.ok) {
    const error = new Error(data.message || data.error || 'Falha no envio.')
    error.response = { status: response.status, data }
    throw error
  }

  return { data, status: response.status }
}
