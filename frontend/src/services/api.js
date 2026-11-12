import { lookupAddress } from './address'
const apiBase = (import.meta.env.VITE_API_URL || '/api/v1').replace(/\/$/, '')
const backendOrigin = new URL(apiBase, window.location.origin).origin

function cookie(name) {
  return document.cookie.split('; ').find(item => item.startsWith(`${name}=`))?.split('=').slice(1).join('=')
}

export async function request(path, { body, headers, ...options } = {}) {
  const xsrf = cookie('XSRF-TOKEN')
  const isFormData = body instanceof FormData
  const response = await fetch(`${apiBase}${path}`, {
    ...options,
    credentials: 'include',
    headers: {
      Accept: 'application/json',
      ...(!isFormData && body ? { 'Content-Type': 'application/json' } : {}),
      ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}),
      ...headers,
    },
    ...(body ? { body: isFormData ? body : JSON.stringify(body) } : {}),
  }).catch(error => {
    if (error.name === 'AbortError') throw error
    throw new Error('Não foi possível conectar ao serviço. Confira sua conexão e tente novamente.')
  })
  const data = response.status === 204 ? null : await response.json().catch(() => null)
  if (!response.ok) {
    const error = new Error(response.status >= 500 ? 'O serviço está indisponível no momento. Tente novamente em instantes.' : data?.message || 'Não foi possível concluir. Tente novamente em instantes.')
    error.status = response.status
    error.errors = data?.errors || {}
    throw error
  }
  return data
}

async function csrf() {
  const response = await fetch(`${backendOrigin}/sanctum/csrf-cookie`, { credentials: 'include' }).catch(() => {
    throw new Error('Não foi possível iniciar uma sessão. Confira sua conexão e tente novamente.')
  })
  if (!response.ok) throw new Error('Não foi possível iniciar uma sessão. Tente novamente em instantes.')
}

async function authenticatedMutation(path, options) {
  await csrf()
  return request(path, options)
}

const unwrap = payload => payload?.data ?? payload

export const api = {
  publicOrganization: slug => request(`/organizations/${encodeURIComponent(slug)}`).then(unwrap),
  publicOrganizationPets: (slug, page = 1) => request(`/organizations/${encodeURIComponent(slug)}/pets?page=${page}`),
  address: (cep, options) => lookupAddress(cep, request, options),
  pets: params => request(`/pets?${new URLSearchParams(params || {})}`),
  pet: slug => request(`/pets/${slug}`).then(unwrap),
  adoptionRequest: (petId, body) => request(`/pets/${petId}/adoption-requests`, { method: 'POST', body }),
  login: body => authenticatedMutation('/auth/login', { method: 'POST', body }).then(unwrap),
  register: body => authenticatedMutation('/auth/register', { method: 'POST', body }).then(unwrap),
  logout: () => request('/auth/logout', { method: 'POST' }),
  me: () => request('/auth/me').then(unwrap),
  organization: () => request('/organization').then(unwrap),
  updateOrganization: body => request('/organization', { method: 'PATCH', body }).then(unwrap),
  organizationPets: () => request('/organization/pets'),
  createPet: body => request('/organization/pets', { method: 'POST', body }).then(unwrap),
  updatePet: (id, body) => request(`/organization/pets/${id}`, { method: 'PATCH', body }).then(unwrap),
  deletePet: id => request(`/organization/pets/${id}`, { method: 'DELETE' }),
  publishPet: id => request(`/organization/pets/${id}/publish`, { method: 'POST' }).then(unwrap),
  markAdopted: id => request(`/organization/pets/${id}/mark-adopted`, { method: 'POST' }).then(unwrap),
  uploadPetPhoto: (id, body) => request(`/organization/pets/${id}/photos`, { method: 'POST', body }).then(unwrap),
  adoptionRequests: () => request('/organization/adoption-requests'),
  updateAdoptionRequest: (id, body) => request(`/organization/adoption-requests/${id}`, { method: 'PATCH', body }),
}
