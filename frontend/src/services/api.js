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
  favorites: () => request('/account/favorites').then(unwrap),
  favoritePets: (page = 1) => request(`/account/favorite-pets?page=${page}`),
  saveFavorite: (id, selected) => request(`/account/favorites/${encodeURIComponent(id)}`, { method: selected ? 'PUT' : 'DELETE' }),
  myAdoptions: (page = 1) => request(`/account/adoptions?page=${page}`),
  adoptionAction: (id, action) => request(`/account/adoptions/${id}`, { method: 'PATCH', body: { action } }),
  review: (id, body) => request(`/account/adoptions/${id}/review`, { method: 'POST', body }),
  reviews: (slug, page = 1) => request(`/organizations/${encodeURIComponent(slug)}/reviews?page=${page}`),
  forgotPassword: body => authenticatedMutation('/auth/forgot-password', { method: 'POST', body }),
  resetPassword: body => authenticatedMutation('/auth/reset-password', { method: 'POST', body }),
  resendVerification: () => request('/auth/email/verification-notification', { method: 'POST' }).catch(error => {
    if (error?.status === 503) {
      const friendly = new Error('Não foi possível enviar o e-mail de confirmação no momento. Tente novamente em instantes.')
      friendly.status = 503
      throw friendly
    }
    throw error
  }),
  adminOrganizations: (page = 1) => request(`/admin/organizations?page=${page}`),
  moderateOrganization: (id, body) => request(`/admin/organizations/${id}/status`, { method: 'PATCH', body }),
  adminReviews: (page = 1, status = 'pending') => request(`/admin/reviews?page=${page}&status=${status}`),
  moderateReview: (id, status) => request(`/admin/reviews/${id}`, { method: 'PATCH', body: { status } }),
  publicOrganization: slug => request(`/organizations/${encodeURIComponent(slug)}`).then(unwrap),
  publicOrganizationPets: (slug, page = 1) => request(`/organizations/${encodeURIComponent(slug)}/pets?page=${page}`),
  address: (cep, options) => lookupAddress(cep, request, options),
  pets: params => {
    const query = new URLSearchParams(
      Object.entries(params || {}).filter(([, value]) => value !== undefined && value !== null && value !== '')
    ).toString()
    return request(query ? `/pets?${query}` : '/pets')
  },
  pet: slug => request(`/pets/${slug}`).then(unwrap),
  adoptionRequest: (petId, body) => request(`/pets/${petId}/adoption-requests`, { method: 'POST', body }),
  login: body => authenticatedMutation('/auth/login', { method: 'POST', body }).then(unwrap),
  register: body => authenticatedMutation('/auth/register', { method: 'POST', body }).then(unwrap),
  logout: () => request('/auth/logout', { method: 'POST' }),
  me: () => request('/auth/me').then(unwrap),
  organization: () => request('/organization').then(unwrap),
  updateOrganization: body => request('/organization', { method: 'PATCH', body }).then(unwrap),
  organizationPets: params => {
    const query = new URLSearchParams(
      Object.entries(params || {}).filter(([, value]) => value !== undefined && value !== null && value !== '')
    ).toString()
    return request(query ? `/organization/pets?${query}` : '/organization/pets')
  },
  organizationPet: id => request(`/organization/pets/${id}`).then(unwrap),
  createPet: body => request('/organization/pets', { method: 'POST', body }).then(unwrap),
  updatePet: (id, body) => request(`/organization/pets/${id}`, { method: 'PATCH', body }).then(unwrap),
  deletePet: id => request(`/organization/pets/${id}`, { method: 'DELETE' }),
  publishPet: id => request(`/organization/pets/${id}/publish`, { method: 'POST' }).then(unwrap),
  markAdopted: id => request(`/organization/pets/${id}/mark-adopted`, { method: 'POST' }).then(unwrap),
  uploadPetPhoto: (id, body) => request(`/organization/pets/${id}/photos`, { method: 'POST', body }).then(unwrap),
  setPetCoverPhoto: (id, photoId) => request(`/organization/pets/${id}/photos/${encodeURIComponent(photoId)}`, { method: 'PATCH' }).then(unwrap),
  deletePetPhoto: (id, photoId) => request(`/organization/pets/${id}/photos/${encodeURIComponent(photoId)}`, { method: 'DELETE' }).then(unwrap),
  adoptionRequests: (page = 1) => request(`/organization/adoption-requests?page=${page}`),
  updateAdoptionRequest: (id, body) => request(`/organization/adoption-requests/${id}`, { method: 'PATCH', body }),
}
