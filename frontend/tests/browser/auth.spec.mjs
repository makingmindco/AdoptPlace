import { test, expect } from '@playwright/test'

async function mockApi(page, options = {}) {
  await page.route('https://viacep.com.br/**', route => route.fulfill({ status: 503, json: {} }))
  let user = options.user || null
  await page.route('**/sanctum/csrf-cookie', route => route.fulfill({ status: 204 }))
  await page.route('**/api/v1/**', async route => {
    const path = new URL(route.request().url()).pathname
    const reply = (data, status = 200) => route.fulfill({ status, json: data })
    if (path.endsWith('/auth/me')) return reply(user ? { data: user } : {}, user ? 200 : 401)
    if (path.endsWith('/auth/logout')) { user = null; return reply({}) }
    if (path.endsWith('/auth/login')) {
      if (options.login) return options.login(route)
      user = { id: 'demo', name: 'Ana', role: 'adopter' }
      return reply({ data: user })
    }
    if (path.endsWith('/auth/register')) {
      const body = route.request().postDataJSON()
      if (options.register) return options.register(route, body)
      user = { id: 'demo', name: body.name, role: body.account_type === 'adopter' ? 'adopter' : 'organization_owner', organization: body.account_type === 'adopter' ? null : { name: body.provider_name, verification_status: 'pending' } }
      return reply({ data: user }, 201)
    }
    if (path.includes('/address/cep/')) {
      if (options.cep) return options.cep(route)
      return reply({ cep: '01001000', street: 'Praça da Sé', neighborhood: 'Sé', city: 'São Paulo', state: 'SP' })
    }
    return reply({ data: [] })
  })
}

async function credentials(page, type = 'Quero adotar') {
  await page.goto('/cadastro')
  await page.getByRole('radio', { name: new RegExp(type) }).check()
  await page.getByRole('button', { name: 'Continuar' }).click()
  await page.getByLabel('Seu nome completo').fill('Ana Teste')
  await page.getByLabel('E-mail', { exact: true }).fill('ana@example.test')
  await page.getByLabel('Telefone com DDD').fill('11999999999')
  await page.getByLabel('Crie sua senha').fill('Encontro#123')
  await page.getByLabel('Confirme sua senha').fill('Encontro#123')
  await page.getByRole('button', { name: 'Continuar' }).click()
}

async function address(page) {
  await page.getByLabel('CEP', { exact: true }).fill('01001000')
  await page.getByRole('button', { name: 'Buscar CEP' }).click()
  await expect(page.getByLabel('Cidade', { exact: true })).toHaveValue('São Paulo')
}

test('login links to signup, shows pending animation and hides server details', async ({ page }) => {
  let release
  const pending = new Promise(resolve => { release = resolve })
  await mockApi(page, { login: async route => { await pending; await route.fulfill({ status: 500, json: { message: 'Internal MongoDB connection host' } }) } })
  await page.goto('/login')
  await expect(page.getByRole('link', { name: 'Crie sua conta gratuitamente' })).toHaveAttribute('href', '/cadastro')
  await expect(page.locator('.auth-tabs')).toHaveCount(0)
  await page.getByLabel('E-mail').fill('ana@example.test')
  await page.getByLabel('Senha', { exact: true }).fill('existing-password')
  await page.getByRole('button', { name: 'Entrar na minha conta' }).click()
  await expect(page.getByRole('button', { name: 'Entrando na sua conta' })).toBeDisabled()
  await expect(page.locator('.loading-spinner')).toBeVisible()
  release()
  await expect(page.getByRole('alert')).toContainText('indisponível')
  await expect(page.getByRole('alert')).not.toContainText('MongoDB')
  await expect(page.getByRole('button', { name: 'Entrar na minha conta' })).toBeEnabled()
})

test('adopter registration, session refresh and dashboard protection', async ({ page }) => {
  await mockApi(page)
  await credentials(page)
  await expect(page.getByLabel('Nome da ONG')).toHaveCount(0)
  await address(page)
  await expect(page.getByLabel('Rua / logradouro')).toHaveCount(0)
  await page.getByLabel('Autorizo o uso dos dados').check()
  await page.getByRole('button', { name: 'Criar minha conta gratuita' }).click()
  await expect(page).toHaveURL(/\/#catalogo$/)
  await page.reload()
  await expect(page.getByRole('button', { name: 'Sair', exact: true })).toBeVisible()
  await page.goto('/dashboard')
  await expect(page).toHaveURL(/\/#catalogo$/)
  await page.getByRole('button', { name: 'Sair', exact: true }).click()
  await expect(page.getByRole('link', { name: 'Entrar', exact: true })).toBeVisible()
})

test('password mismatch is visible and values survive backward navigation', async ({ page }) => {
  await mockApi(page)
  await credentials(page)
  await page.getByRole('button', { name: 'Voltar', exact: true }).click()
  await expect(page.getByLabel('Seu nome completo')).toHaveValue('Ana Teste')
  await expect(page.getByRole('meter')).toHaveAttribute('value', '5')
  await page.getByLabel('Confirme sua senha').fill('Different#123')
  await page.getByRole('button', { name: 'Continuar' }).click()
  await expect(page.getByRole('alert')).toContainText('confirmação da senha')
  await expect(page.getByText('As senhas precisam ser iguais.')).toBeVisible()
})

for (const type of ['Represento uma ONG', 'Sou protetor individual']) {
  test(`${type}: address and pending approval`, async ({ page }) => {
    await mockApi(page)
    await credentials(page, type)
    await page.getByLabel(type === 'Represento uma ONG' ? 'Nome da ONG' : 'Seu nome público como protetor').fill('Amigos dos animais')
    await page.getByLabel('Conte sobre seu trabalho').fill('Acolhemos animais e buscamos famílias responsáveis.')
    if (type === 'Represento uma ONG') {
      await page.getByLabel('A organização possui CNPJ').check()
      await page.getByLabel('CNPJ da organização').fill('11.222.333/0001-81')
    } else {
      await expect(page.getByLabel('A organização possui CNPJ')).toHaveCount(0)
    }
    await address(page)
    await expect(page.getByLabel('Rua / logradouro')).toHaveValue('Praça da Sé')
    await page.getByLabel('Número (ou S/N)').fill('100')
    await page.getByLabel('Comprometo-me com adoções responsáveis').check()
    await page.getByLabel('Autorizo o uso dos dados').check()
    await page.getByRole('button', { name: 'Criar minha conta gratuita' }).click()
    await expect(page).toHaveURL(/\/dashboard$/)
    await expect(page.getByText('O cadastro está em análise.', { exact: false })).toBeVisible()
    await expect(page.getByRole('button', { name: 'Cadastrar animal' })).toBeDisabled()
  })
}

test('manual CEP fallback and server validation keep the form editable', async ({ page }) => {
  await mockApi(page, {
    cep: route => route.fulfill({ status: 503, json: { message: 'Unavailable' } }),
    register: route => route.fulfill({ status: 422, json: { message: 'Invalid', errors: { email: ['Este e-mail já está cadastrado.'] } } }),
  })
  await credentials(page)
  await page.getByLabel('CEP', { exact: true }).fill('01001000')
  await page.getByRole('button', { name: 'Buscar CEP' }).click()
  await expect(page.getByText('Você pode preencher o endereço manualmente.', { exact: false })).toBeVisible()
  await page.getByLabel('Cidade', { exact: true }).fill('São Paulo')
  await page.getByLabel('Estado', { exact: true }).selectOption('SP')
  await page.getByLabel('Autorizo o uso dos dados').check()
  await page.getByRole('button', { name: 'Criar minha conta gratuita' }).click()
  await expect(page.getByRole('heading', { name: 'Vamos nos conhecer?' })).toBeVisible()
  await expect(page.getByRole('alert')).toContainText('já está cadastrado')
  await expect(page.getByLabel('E-mail', { exact: true })).toHaveValue('ana@example.test')
})

test('signup remains usable on mobile without horizontal overflow', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await mockApi(page)
  await credentials(page, 'Represento uma ONG')
  await expect(page.getByLabel('Nome da ONG')).toBeVisible()
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
  await page.screenshot({ path: 'test-results/signup-mobile.png', fullPage: true })
})
