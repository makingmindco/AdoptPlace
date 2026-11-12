import { test, expect } from '@playwright/test'

const org = { id: 'org1', name: 'Amigos dos animais', slug: 'amigos', type: 'ngo', city: 'São Paulo', state: 'SP', verification_status: 'approved', description: 'Resgatamos e cuidamos de animais.', contact_publicly_visible: false }
const pet = { id: 'pet1', name: 'Luna', slug: 'luna', species: 'cat', sex: 'female', size: 'small', location: { city: 'São Paulo', state: 'SP' }, organization: org }
const paginate = data => ({ data, meta: { current_page: 1, last_page: 1 } })
async function setup(page, role = 'adopter') {
  const state = { favorites: [], status: 'approved', review: null, user: role ? { id: 'user1', name: 'Ana', role, status: 'active', organization: role === 'organization_owner' ? org : null } : null }
  await page.route('**/sanctum/csrf-cookie', route => route.fulfill({ status: 204 }))
  await page.route('**/api/v1/**', async route => {
    const path = new URL(route.request().url()).pathname.replace('/api/v1', '')
    const method = route.request().method()
    const reply = (data, status = 200) => route.fulfill({ status, json: data })
    if (path === '/auth/me') return reply(state.user ? { data: state.user } : {}, state.user ? 200 : 401)
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
    if (path === '/pets') return reply(paginate([pet]))
    if (path === '/pets/luna') return reply({ data: pet })
    if (path === '/organizations/amigos') return reply({ data: org })
    if (path === '/organizations/amigos/pets') return reply(paginate([pet]))
    if (path === '/organizations/amigos/reviews') return reply({ count: 1, average: 5, reviews: { data: [{ id: 'r1', rating: 5, author: 'Adotante com adoção concluída', comment: 'Acompanhamento muito cuidadoso.' }], last_page: 1 } })
    if (path === '/organization/pets') return reply(paginate([pet]))
    if (path === '/organization/adoption-requests') return reply(paginate([{ id: 'a1', pet, status: 'new', adopter: { name: 'Ana', message: 'Gostaria de adotar.' } }]))
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
