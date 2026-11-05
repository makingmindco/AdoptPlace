const baseUrl = (import.meta.env.VITE_API_URL || '/api').replace(/\/$/, '')
export async function request(path, { body, ...options } = {}) {
  const response = await fetch(`${baseUrl}${path}`, {
    ...options,
    headers: { Accept: 'application/json', ...(body ? { 'Content-Type': 'application/json' } : {}), ...options.headers },
    credentials: 'include',
    ...(body ? { body: JSON.stringify(body) } : {}),
  })
  const data = response.status === 204 ? null : await response.json().catch(() => null)
  if (!response.ok) {
    const error = new Error(data?.message || 'Não foi possível concluir. Tente novamente em instantes.')
    error.status = response.status
    error.errors = data?.errors || {}
    throw error
  }
  return data
}
export const api = {
  pets: signal => request('/pets', { signal }),
  login: body => request('/login', { method: 'POST', body }),
  register: body => request('/register', { method: 'POST', body }),
}
