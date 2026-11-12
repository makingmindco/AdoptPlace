import { useEffect, useRef, useState } from 'react'
import { Link, Navigate, useNavigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { api } from '../services/api'
import { FormAlert, SubmitButton } from '../components/AuthControls'
import PasswordStrength from '../components/PasswordStrength'
import { passwordStrength } from '../utils/passwordStrength'

const accountTypes = [
  { value: 'adopter', title: 'Quero adotar', icon: '♡', text: 'Encontre um companheiro e salve seus animais favoritos.' },
  { value: 'ngo', title: 'Represento uma ONG', icon: '⌂', text: 'Apresente sua organização e divulgue animais para adoção.' },
  { value: 'independent_protector', title: 'Sou protetor individual', icon: '✧', text: 'Dê visibilidade aos animais que você resgata e cuida.' },
]
const states = 'AC AL AP AM BA CE DF ES GO MA MT MS MG PA PB PR PE PI RJ RN RS RO RR SC SP SE TO'.split(' ')

function Field({ label, error, name, children, ...props }) {
  return <div className="field"><label htmlFor={`register-${name}`}>{label}</label>
    {children || <input id={`register-${name}`} name={name} aria-invalid={!!error} aria-describedby={error ? `error-${name}` : undefined} {...props} />}
    {error && <span className="field-error" id={`error-${name}`}>{error}</span>}
  </div>
}

export default function Register() {
  const auth = useAuth()
  const navigate = useNavigate()
  const [step, setStep] = useState(1)
  const [values, setValues] = useState({ account_type: 'adopter', name: '', email: '', phone: '', password: '', password_confirmation: '', provider_name: '', description: '', has_cnpj: false, cnpj: '', terms: false, responsible_adoption: false })
  const [address, setAddress] = useState({ cep: '', street: '', number: '', complement: '', neighborhood: '', city: '', state: '' })
  const [errors, setErrors] = useState({})
  const [message, setMessage] = useState('')
  const [busy, setBusy] = useState(false)
  const [showPassword, setShowPassword] = useState(false)
  const [cepBusy, setCepBusy] = useState(false)
  const [cepMessage, setCepMessage] = useState('')
  const cepRequest = useRef(null)
  const heading = useRef(null)
  const provider = values.account_type !== 'adopter'
  const type = accountTypes.find(item => item.value === values.account_type)

  useEffect(() => { heading.current?.focus() }, [step])
  useEffect(() => () => cepRequest.current?.abort(), [])

  const update = (name, value) => {
    setValues(previous => ({ ...previous, [name]: value }))
    setErrors(previous => ({ ...previous, [name]: undefined }))
  }
  const updateAddress = (name, value) => {
    if (name === 'cep') {
      cepRequest.current?.abort()
      setCepBusy(false)
      setCepMessage('')
    }
    setAddress(previous => ({ ...previous, [name]: value }))
    setErrors(previous => ({ ...previous, [`address.${name}`]: undefined }))
  }
  const field = (name, label, props = {}) => <Field name={name} label={label} value={values[name]} error={errors[name]?.[0]} onChange={event => update(name, event.target.value)} {...props} />
  const addressField = (name, label, props = {}) => <Field name={`address.${name}`} label={label} value={address[name]} error={errors[`address.${name}`]?.[0]} onChange={event => updateAddress(name, event.target.value)} {...props} />

  async function lookupCep() {
    if (address.cep.length !== 8) {
      setCepMessage('Digite os oito números do CEP para consultar.')
      return
    }
    cepRequest.current?.abort()
    const controller = new AbortController()
    cepRequest.current = controller
    setCepBusy(true)
    setCepMessage('Buscando seu endereço…')
    try {
      const data = await api.address(address.cep, { signal: controller.signal })
      if (controller.signal.aborted) return
      setAddress(previous => ({ ...previous, ...data }))
      setCepMessage('Endereço encontrado. Confira os dados e complete os campos que faltarem.')
    } catch (error) {
      if (!controller.signal.aborted) setCepMessage(`${error.message || 'Não foi possível consultar o CEP.'} Você pode preencher o endereço manualmente.`)
    } finally {
      if (!controller.signal.aborted) setCepBusy(false)
    }
  }

  async function submit(event) {
    event.preventDefault()
    if (busy || cepBusy || auth.loading) return
    setMessage('')
    if (step === 2) {
      if (!passwordStrength(values.password).valid) {
        setMessage('Sua senha ainda precisa atender a todos os requisitos abaixo.')
        return
      }
      if (values.password !== values.password_confirmation) {
        setErrors({ password_confirmation: ['As senhas precisam ser iguais.'] })
        setMessage('Confira a confirmação da senha antes de continuar.')
        return
      }
    }
    if (step < 3) {
      setStep(step + 1)
      return
    }
    setBusy(true)
    setErrors({})
    try {
      const user = await auth.register({ ...values, address })
      navigate(user.role === 'adopter' ? '/#catalogo' : '/dashboard', { replace: true })
    } catch (error) {
      setErrors(error.errors || {})
      setMessage(Object.values(error.errors || {}).flat().join(' ') || error.message)
      if (['name', 'email', 'phone', 'password', 'password_confirmation'].some(name => error.errors?.[name])) setStep(2)
    } finally {
      setBusy(false)
    }
  }

  if (auth.user) return <Navigate to={auth.user.role === 'adopter' ? '/#catalogo' : '/dashboard'} replace />

  return <main id="main" className="register-page container">
    <aside className="register-story">
      <Link to="/login" className="text-link">← Voltar para entrar</Link>
      <span className="eyebrow">FAÇA PARTE DESSA HISTÓRIA</span>
      <h1>Um pequeno passo.<br /><span>Um novo começo.</span></h1>
      <p>Adotar, acolher, cuidar. Seja qual for o seu papel, existe um lugar para você aqui.</p>
      <div className="register-promise"><span aria-hidden="true">♡</span><div><strong>Gratuito. Sempre com propósito.</strong><p>Conectamos pessoas e animais para construir encontros responsáveis.</p></div></div>
      <p className="small muted">Já faz parte? <Link to="/login">Entre na sua conta</Link>.</p>
    </aside>
    <section className="auth-card register-card" aria-labelledby="register-title">
      <ol className="register-steps" aria-label="Etapas do cadastro">{['Seu perfil', 'Sua conta', 'Localização'].map((label, index) => <li key={label} className={step >= index + 1 ? 'is-current' : ''} aria-current={step === index + 1 ? 'step' : undefined}><span>{step > index + 1 ? '✓' : index + 1}</span>{label}</li>)}</ol>
      <p className="eyebrow">ETAPA {step} DE 3</p>
      <h2 id="register-title" ref={heading} tabIndex={-1}>{step === 1 ? 'Como você quer participar?' : step === 2 ? 'Vamos nos conhecer?' : provider ? 'Onde seu trabalho acontece?' : 'Vamos encontrar animais perto de você?'}</h2>
      <p className="muted">{step === 1 ? 'Escolha o perfil que combina com você.' : step === 2 ? `Você está criando uma conta: ${type.title.toLowerCase()}.` : 'Busque pelo CEP ou preencha o endereço manualmente.'}</p>
      <form onSubmit={submit} aria-busy={busy}>
        <fieldset className="register-fields" disabled={busy}>
          {step === 1 && <fieldset className="account-types"><legend className="sr-only">Tipo de conta</legend>{accountTypes.map(item => <label className={`account-type ${values.account_type === item.value ? 'is-selected' : ''}`} key={item.value}>
            <input type="radio" name="account_type" value={item.value} checked={values.account_type === item.value} onChange={() => update('account_type', item.value)} />
            <span className="account-icon" aria-hidden="true">{item.icon}</span><span><strong>{item.title}</strong><small>{item.text}</small></span>
          </label>)}</fieldset>}
          {step === 2 && <>
            {field('name', 'Seu nome completo', { required: true, maxLength: 120, autoComplete: 'name' })}
            <div className="register-grid">{field('email', 'E-mail', { type: 'email', required: true, maxLength: 254, autoComplete: 'email' })}{field('phone', 'Telefone com DDD', { type: 'tel', required: true, minLength: 10, maxLength: 25, autoComplete: 'tel', placeholder: '(11) 99999-9999' })}</div>
            <div className="field"><label htmlFor="register-password">Crie sua senha</label><div className="password-input">
              <input id="register-password" name="password" value={values.password} onChange={event => update('password', event.target.value)} type={showPassword ? 'text' : 'password'} required minLength={8} autoComplete="new-password" aria-describedby="password-requirements" />
              <button type="button" aria-pressed={showPassword} onClick={() => setShowPassword(value => !value)}>{showPassword ? 'Ocultar' : 'Mostrar'}</button>
            </div></div>
            <PasswordStrength password={values.password} />
            {field('password_confirmation', 'Confirme sua senha', { type: showPassword ? 'text' : 'password', required: true, minLength: 10, autoComplete: 'new-password' })}
          </>}
          {step === 3 && <>
            {provider && <div className="provider-fields">
              {field('provider_name', values.account_type === 'ngo' ? 'Nome da ONG' : 'Seu nome público como protetor', { required: true, maxLength: 120, autoComplete: 'organization' })}
              <Field name="description" label="Conte sobre seu trabalho" error={errors.description?.[0]}><textarea id="register-description" name="description" value={values.description} onChange={event => update('description', event.target.value)} required minLength={20} maxLength={2000} rows={3} placeholder="Como você acolhe os animais e conduz as adoções?" /></Field>
              {values.account_type === 'ngo' && <>
                <label className="checkbox-label"><input type="checkbox" checked={values.has_cnpj} onChange={event => update('has_cnpj', event.target.checked)} /> A organização possui CNPJ</label>
                {values.has_cnpj && field('cnpj', 'CNPJ da organização', { required: true, maxLength: 18, placeholder: '00.000.000/0000-00', onChange: event => update('cnpj', event.target.value.toUpperCase()) })}
                <p className="small muted">O CNPJ ajuda na análise da organização. Sem CNPJ, seu cadastro também poderá ser analisado. Informá-lo não gera aprovação automática.</p>
              </>}
            </div>}
            <div className="cep-row">{addressField('cep', 'CEP', { required: true, inputMode: 'numeric', pattern: '[0-9]{8}', maxLength: 8, autoComplete: 'postal-code', placeholder: 'Somente números', onChange: event => updateAddress('cep', event.target.value.replace(/\D/g, '').slice(0, 8)) })}
              <button type="button" className="button button-outline" disabled={cepBusy} onClick={lookupCep}>{cepBusy && <span className="loading-spinner" aria-hidden="true" />}{cepBusy ? 'Buscando…' : 'Buscar CEP'}</button></div>
            <p className="cep-status small" role="status">{cepMessage || 'Consulta de endereço por ViaCEP. Você pode corrigir os dados abaixo.'}</p>
            <div className="register-grid">
              {addressField('city', 'Cidade', { required: true, maxLength: 100, autoComplete: 'address-level2', disabled: cepBusy })}
              <Field name="address.state" label="Estado" error={errors['address.state']?.[0]}><select id="register-address.state" name="address.state" value={address.state} onChange={event => updateAddress('state', event.target.value)} required autoComplete="address-level1" disabled={cepBusy}><option value="">Selecione</option>{states.map(state => <option key={state}>{state}</option>)}</select></Field>
            </div>
            {provider && <>
              {addressField('street', 'Rua / logradouro', { required: true, maxLength: 150, autoComplete: 'address-line1', disabled: cepBusy })}
              <div className="register-grid">{addressField('number', 'Número (ou S/N)', { required: true, maxLength: 20 })}{addressField('complement', 'Complemento (opcional)', { maxLength: 100, autoComplete: 'address-line2' })}</div>
              {addressField('neighborhood', 'Bairro', { required: true, maxLength: 100, autoComplete: 'address-level3', disabled: cepBusy })}
              <div className="auth-notice"><strong>Seu endereço completo é privado.</strong><p>Apenas cidade e estado fazem parte das informações públicas. Seu cadastro será analisado antes da publicação de animais.</p></div>
              <label className="checkbox-label"><input type="checkbox" checked={values.responsible_adoption} onChange={event => update('responsible_adoption', event.target.checked)} required /> Comprometo-me com adoções responsáveis e informações atualizadas sobre os animais.</label>
            </>}
            {!provider && <p className="small muted">Para adotar, precisamos apenas do CEP, cidade e estado. Seu endereço completo não será salvo.</p>}
            <p className="small"><Link to="/privacidade" target="_blank" rel="noopener noreferrer">Leia como seus dados são utilizados e as regras de participação ↗</Link></p>
            <label className="checkbox-label"><input type="checkbox" checked={values.terms} onChange={event => update('terms', event.target.checked)} required /> Autorizo o uso dos dados informados para criar e administrar minha conta no AdoptPlace, conforme as informações de privacidade.</label>
          </>}
        </fieldset>
        <FormAlert message={message} />
        <div className="register-actions">
          {step > 1 && <button type="button" className="button button-outline" disabled={busy} onClick={() => { setMessage(''); setStep(step - 1) }}>Voltar</button>}
          <SubmitButton busy={busy || cepBusy || auth.loading} busyLabel={cepBusy ? 'Consultando endereço…' : auth.loading ? 'Carregando…' : 'Criando sua conta…'}>{step < 3 ? 'Continuar →' : 'Criar minha conta gratuita ↗'}</SubmitButton>
        </div>
      </form>
      <p className="auth-switch">Já tem cadastro? <Link to="/login">Entre aqui</Link>.</p>
    </section>
  </main>
}
