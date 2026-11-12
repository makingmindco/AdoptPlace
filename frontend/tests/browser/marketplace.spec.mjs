import { test, expect } from '@playwright/test'

const org = { id: 'org1', name: 'Amigos dos animais', slug: 'amigos', type: 'ngo', city: 'São Paulo', state: 'SP', verification_status: 'approved', description: 'Resgatamos e cuidamos de animais.', contact_publicly_visible: false }
const pet = { id: 'pet1', name: 'Luna', slug: 'luna', species: 'cat', breed: 'SRD Siberiana', sex: 'female', size: 'small', status: 'published', location: { neighborhood: 'Centro', city: 'São Paulo', state: 'SP' }, health: { vaccinated: true, neutered: true, dewormed: false, special_needs: true, notes: 'Acompanhamento oftalmológico em dia.' }, cover_photo: '/demo-pets/luna.png', photos: [{ id: 'p1', url: '/demo-pets/luna.png' }, { id: 'p2', url: '/demo-pets/bento.png' }], organization: org }
const pet2 = { id: 'pet2', name: 'Bento', slug: 'bento', species: 'dog', sex: 'unknown', size: 'small', status: 'published', location: { city: 'Porto Ferreira', state: 'SP' }, organization: org }
const paginate = (data, meta = {}) => ({ data, meta: { current_page: 1, last_page: 1, total: data.length, ...meta } })
async function setup(page, role = 'adopter') {
  const state = { favorites: [], status: 'approved', review: null, queries: [], user: role ? { id: 'user1', name: 'Ana', email: 'ana@example.test', role, status: 'active', organization: role === 'organization_owner' ? org : null } : null }
  await page.route('**/sanctum/csrf-cookie', route => route.fulfill({ status: 204 }))
  await page.route('**/api/v1/**', async route => {
    const url = new URL(route.request().url())
    const path = url.pathname.replace('/api/v1', '')
    const method = route.request().method()
    const reply = (data, status = 200) => route.fulfill({ status, json: data })
    if (path === '/auth/me') return reply(state.user ? { data: state.user } : {}, state.user ? 200 : 401)
    if (path === '/auth/login') { state.user = { id: 'user1', name: 'Ana', role: 'adopter', status: 'active' }; return reply({ data: state.user }) }
    if (path === '/auth/logout') { state.user = null; return reply({}) }
    if (path === '/account/favorites') return reply({ data: state.favorites })
    if (path === '/account/favorites/pet1') { state.favorites = method === 'PUT' ? ['pet1'] : []; return reply({}) }
    if (path === '/account/favorite-pets') return reply(paginate(state.favorites.length ? [pet] : []))
    if (path === '/account/adoptions') return reply(paginate([{ id: 'a1', pet, status: state.status, review: state.review }]))
    if (path === '/account/adoptions/a1') { state.status = route.request().postDataJSON().action === 'confirm' ? 'completed' : 'withdrawn'; return reply({}) }
    if (path === '/account/adoptions/a1/review') { state.review = { ...route.request().postDataJSON(), status: 'pending' }; return reply({ message: 'Avaliação enviada para moderação.' }, 201) }
    if (path === '/auth/email/verification-notification') return reply({ message: 'Link de verificação enviado.' })
    if (path === '/auth/forgot-password') return reply({ message: 'Se houver uma conta, você receberá as instruções.' })
    if (path === '/auth/reset-password') return reply({ message: 'Senha atualizada. Entre novamente.' })
    if (path === '/pets') {
      state.queries.push(Object.fromEntries(url.searchParams.entries()))
      const pageNum = Number(url.searchParams.get('page') || '1')
      const species = url.searchParams.get('species')
      const city = (url.searchParams.get('city') || '').toLowerCase()
      if (pageNum === 2) return reply(paginate([pet2], { current_page: 2, last_page: 2, total: 2 }))
      let items = [pet, pet2]
      if (species) items = items.filter(item => item.species === species)
      if (city) items = items.filter(item => item.location.city.toLowerCase().includes(city))
      if (state.multiPage && !species && !city) return reply(paginate([pet], { current_page: 1, last_page: 2, total: 2 }))
      return reply(paginate(items.length === 2 && !species && !city ? [pet] : items))
    }
    if (path === '/pets/luna') return reply({ data: pet })
    if (path === '/organizations/amigos') return reply({ data: org })
    if (path === '/organizations/amigos/pets') return reply(paginate([pet]))
    if (path === '/organizations/amigos/reviews') return reply({ count: 1, average: 5, reviews: { data: [{ id: 'r1', rating: 5, author: 'Adotante com adoção concluída', comment: 'Acompanhamento muito cuidadoso.' }], last_page: 1 } })
    if (path === '/organization/pets') return reply(paginate(state.orgPets || [pet]))
    if (path === '/organization/pets/pet-draft' && method === 'PATCH') {
      const body = route.request().postDataJSON()
      state.draftPet = { ...state.draftPet, ...body }
      state.orgPets = [state.draftPet, pet]
      return reply({ data: state.draftPet })
    }
    if (path === '/organization/pets/pet-draft/photos/p2' && method === 'PATCH') {
      state.draftPet = { ...state.draftPet, cover_photo: '/demo-pets/bento.png' }
      state.orgPets = [state.draftPet, pet]
      return reply({ data: state.draftPet })
    }
    if (path === '/organization/pets/pet-draft/photos/p1' && method === 'DELETE') {
      state.draftPet = { ...state.draftPet, photos: state.draftPet.photos.filter(p => p.id !== 'p1') }
      state.orgPets = [state.draftPet, pet]
      return reply({ data: state.draftPet })
    }
    if (path === '/organization/pets/pet-draft/publish' && method === 'POST') {
      state.draftPet = { ...state.draftPet, status: 'published' }
      state.orgPets = [state.draftPet, pet]
      return reply({ data: state.draftPet })
    }
    if (path === '/organization/pets/pet-draft' && method === 'DELETE') {
      state.draftPet = { ...state.draftPet, status: 'archived' }
      state.orgPets = [state.draftPet, pet]
      return reply({ message: 'Animal arquivado.' })
    }
    if (path === '/organization/adoption-requests') return reply(paginate([{ id: 'a1', pet, status: 'new', adopter: { name: 'Ana', email: 'ana@example.test', phone: '19991112222', housing_type: 'house', city: 'Porto Ferreira', state: 'SP', has_other_pets: true, experience: 'Cuido de gatos há 10 anos.', message: 'Gostaria de adotar.' } }]))
    if (path === '/organization/adoption-requests/a1') { state.updated = route.request().postDataJSON(); return reply({ data: {} }) }
    if (path === '/admin/organizations') return reply(paginate([org]))
    if (path === '/admin/reviews') return reply({ data: [{ id: 'a1', review: { rating: 5, comment: 'Muito cuidado com os animais.' } }], last_page: 1 })
    if (path.startsWith('/admin/')) { state.moderated = route.request().postDataJSON(); return reply({}) }
    return reply(paginate([]))
  })
  return state
}

test('favorites persist on account reload and can be removed', async ({ page }) => {
  const state = await setup(page)
  await page.goto('/')
  await page.getByRole('button', { name: 'Adicionar Luna aos favoritos' }).click()
  await expect(page.getByRole('button', { name: 'Remover Luna dos favoritos' })).toBeVisible()
  expect(state.favorites).toEqual(['pet1'])
  await page.goto('/minha-conta')
  await page.reload()
  await expect(page.getByRole('button', { name: 'Remover Luna dos favoritos' })).toBeVisible()
  await page.getByRole('button', { name: 'Remover Luna dos favoritos' }).click()
  await expect(page.getByText('Nenhum animal disponível nos seus favoritos.')).toBeVisible()
})

test('adopter confirms completion and submits only one review', async ({ page }) => {
  const state = await setup(page)
  page.on('dialog', dialog => dialog.accept())
  await page.goto('/minha-conta')
  await page.getByRole('button', { name: 'Recebi o animal' }).click()
  await expect(page.getByText('Adoção concluída', { exact: true })).toBeVisible()
  await page.getByRole('combobox', { name: 'Nota', exact: true }).selectOption('5')
  await page.getByLabel('Sua avaliação', { exact: true }).fill('Um processo cuidadoso e acolhedor.')
  await page.getByRole('button', { name: 'Enviar avaliação' }).click()
  await expect(page.getByText('Avaliação enviada · Aguardando moderação')).toBeVisible()
  await expect(page.getByRole('button', { name: 'Enviar avaliação' })).toHaveCount(0)
  expect(state.review.rating).toBe(5)
})

test('adopter can withdraw and resend verification', async ({ page }) => {
  await setup(page)
  page.on('dialog', dialog => dialog.accept())
  await page.goto('/minha-conta')
  await page.getByRole('button', { name: 'Reenviar confirmação' }).click()
  await expect(page.getByRole('status')).toContainText('Link de verificação enviado')
  await page.getByRole('button', { name: 'Cancelar solicitação' }).click()
  await expect(page.getByText('Cancelada por você')).toBeVisible()
})

test('public profile shows pets and verified-adoption reviews', async ({ page }) => {
  await setup(page, null)
  await page.goto('/organizacoes/amigos')
  await expect(page.getByRole('heading', { name: org.name })).toBeVisible()
  await expect(page.getByText('Acompanhamento muito cuidadoso.')).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Luna' })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Entre em contato' })).toHaveCount(0)
})

test('recovery and reset pages allow a new eight-character password', async ({ page }) => {
  await setup(page, null)
  await page.goto('/recuperar-senha')
  await page.getByLabel('E-mail').fill('ana@example.test')
  await page.getByRole('button', { name: 'Enviar link de recuperação' }).click()
  await expect(page.getByRole('status')).toContainText('Se houver uma conta')
  await page.goto('/redefinir-senha?token=sample&email=ana@example.test')
  await page.getByLabel('Nova senha', { exact: true }).fill('Amigo#12')
  await page.getByLabel('Confirme a nova senha').fill('Amigo#12')
  await page.getByRole('button', { name: 'Atualizar senha' }).click()
  await expect(page.getByRole('status')).toContainText('Senha atualizada')
})

test('provider updates adoption progress', async ({ page }) => {
  const state = await setup(page, 'organization_owner')
  await page.setViewportSize({ width: 1440, height: 1000 })
  await page.goto('/dashboard')
  await expect(page.getByText('Moradia: Casa · Porto Ferreira/SP · Possui outros animais: Sim')).toBeVisible()
  await expect(page.getByText('Experiência com animais: Cuido de gatos há 10 anos.')).toBeVisible()
  await page.getByLabel('Próxima etapa').selectOption('approved')
  await page.getByRole('button', { name: 'Atualizar solicitação' }).click()
  await expect(page.getByText('Andamento atualizado.', { exact: false })).toBeVisible()
  expect(state.updated.status).toBe('approved')
  await page.screenshot({ path: 'test-results/provider-desktop.png', fullPage: true })
})

test('admin moderates organizations and reviews', async ({ page }) => {
  const state = await setup(page, 'admin')
  await page.setViewportSize({ width: 1440, height: 1000 })
  await page.goto('/administracao')
  await expect(page.getByRole('heading', { name: 'Organizações e protetores' })).toBeVisible()
  await expect(page.getByRole('heading', { name: org.name })).toBeVisible()
  await page.screenshot({ path: 'test-results/admin-desktop.png', fullPage: true })
  await page.setViewportSize({ width: 390, height: 844 })
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
  await page.screenshot({ path: 'test-results/admin-mobile.png', fullPage: true })
  await page.setViewportSize({ width: 1440, height: 1000 })
  await page.getByLabel('Situação').selectOption('approved')
  await page.getByLabel('Justificativa').fill('Cadastro analisado e aprovado.')
  await page.getByRole('button', { name: 'Salvar decisão' }).click()
  await expect(page.getByRole('status')).toContainText('Moderação salva')
  expect(state.moderated.status).toBe('approved')
  await page.getByRole('button', { name: 'Publicar', exact: true }).click()
  await expect.poll(() => state.moderated.status).toBe('published')
  await page.goto('/dashboard')
  await expect(page).toHaveURL(/\/administracao$/)
  await expect(page.getByText('O cadastro está em análise.', { exact: false })).toHaveCount(0)
  await page.goto('/minha-conta')
  await expect(page.getByRole('heading', { name: 'Minha conta' })).toBeVisible()
  await page.screenshot({ path: 'test-results/account-desktop.png', fullPage: true })
})

test('home featured section navigates to full catalog with filters, URL sync, and pagination', async ({ page }) => {
  const state = await setup(page, null)
  state.multiPage = true
  await page.goto('/')
  await expect(page.getByRole('heading', { name: 'Luna' })).toBeVisible()
  expect(state.queries[0]).toMatchObject({ featured: '1', per_page: '6' })

  await page.getByRole('link', { name: /Ver todos os animais/i }).first().click()
  await expect(page).toHaveURL(/\/animais$/)
  await expect(page.getByText('2 amigos disponíveis')).toBeVisible()

  await page.getByRole('button', { name: 'Próxima' }).click()
  await expect(page).toHaveURL(/\/animais\?page=2$/)
  await expect(page.getByRole('heading', { name: 'Bento' })).toBeVisible()

  await page.getByLabel('ESPÉCIE').selectOption('dog')
  await page.getByLabel('CIDADE').fill('Porto Ferreira')
  await page.getByRole('button', { name: /Buscar amigos/i }).click()
  await expect(page).toHaveURL(/\/animais\?species=dog&city=Porto\+Ferreira$/)
  await expect(page.getByText('1 amigo disponível')).toBeVisible()

  await page.getByRole('button', { name: 'Limpar filtros' }).click()
  await expect(page).toHaveURL(/\/animais$/)
})

test('visitor is redirected to login on protected actions and returns to destination after login', async ({ page }) => {
  await setup(page, null)
  await page.goto('/animais')
  await page.getByRole('button', { name: 'Adicionar Luna aos favoritos' }).click()
  await expect(page).toHaveURL(/\/login$/)

  await page.goto('/pets/luna')
  await page.getByRole('button', { name: 'Quero adotar Luna' }).click()
  await expect(page).toHaveURL(/\/login$/)
  await page.getByLabel('E-mail').fill('ana@example.test')
  await page.getByLabel('Senha', { exact: true }).fill('Amigo#12')
  await page.getByRole('button', { name: /Entrar na minha conta/i }).click()
  await expect(page).toHaveURL(/\/pets\/luna$/)
})

test('provider edits draft pet, manages photos, publishes and archives in dashboard', async ({ page }) => {
  const state = await setup(page, 'organization_owner')
  const dialogs = []
  page.on('dialog', dialog => {
    dialogs.push(dialog.message())
    dialog.accept()
  })
  state.draftPet = {
    id: 'pet-draft',
    name: 'Pipoca Rascunho',
    slug: 'pipoca-rascunho',
    species: 'dog',
    sex: 'female',
    size: 'small',
    status: 'draft',
    description: 'Cachorrinha muito carinhosa esperando para ser publicada.',
    personality: ['alegre'],
    health: { vaccinated: true, neutered: false },
    location: { city: 'Porto Ferreira', state: 'SP' },
    cover_photo: '/demo-pets/luna.png',
    photos: [
      { id: 'p1', url: '/demo-pets/luna.png' },
      { id: 'p2', url: '/demo-pets/bento.png' },
    ],
  }
  state.orgPets = [state.draftPet, pet]

  await page.goto('/dashboard')
  await expect(page.getByText('Rascunho privado · Clique para editar')).toBeVisible()

  await page.getByRole('button', { name: 'Editar Pipoca Rascunho' }).click()
  await expect(page.getByRole('heading', { name: 'Editar Pipoca Rascunho' })).toBeVisible()

  await page.getByRole('button', { name: 'Definir como capa' }).click()
  await expect(page.getByText('Foto principal atualizada.')).toBeVisible()

  await page.getByRole('button', { name: 'Remover foto' }).first().click()
  await expect(page.getByText('Fotografia removida.')).toBeVisible()

  await page.getByLabel('Nome', { exact: true }).fill('Pipoca Pronta')
  await page.getByLabel('Castrado(a)').check()
  await page.getByRole('button', { name: 'Salvar e disponibilizar' }).click()

  await expect(page.getByText('Pipoca Pronta foi atualizado e disponibilizado no catálogo.')).toBeVisible()
  await page.getByRole('button', { name: 'Arquivar Pipoca Pronta' }).click()
  await expect(page.getByText('Pipoca Pronta foi arquivado.')).toBeVisible()
  expect(dialogs.some(msg => msg.includes('solicitações de adoção em aberto poderão ser encerradas'))).toBe(true)
})

test('pet detail displays translated health badges, clinical notes, breed, neighborhood, gallery and pre-fills adopter data', async ({ page }) => {
  await setup(page, 'adopter')
  await page.goto('/pets/luna')

  await expect(page.getByText('✓ Vacinado(a)')).toBeVisible()
  await expect(page.getByText('✓ Castrado(a)')).toBeVisible()
  await expect(page.getByText('✓ Necessidades especiais')).toBeVisible()
  await expect(page.getByText('✓ notes')).toHaveCount(0)
  await expect(page.getByText('Acompanhamento oftalmológico em dia.')).toBeVisible()
  await expect(page.getByText('SRD Siberiana')).toBeVisible()
  await expect(page.getByText('⌖ Centro · São Paulo, SP')).toBeVisible()

  const secondThumb = page.getByRole('button', { name: 'Ver foto 2 de Luna' })
  await expect(secondThumb).toBeVisible()
  await secondThumb.click()
  await expect(secondThumb).toHaveAttribute('aria-pressed', 'true')

  await page.getByRole('button', { name: /Quero adotar Luna/i }).click()
  await expect(page.getByLabel('Nome completo')).toHaveValue('Ana')
  await expect(page.getByLabel('E-mail')).toHaveValue('ana@example.test')
})
