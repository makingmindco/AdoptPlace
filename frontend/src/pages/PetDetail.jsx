import { useEffect, useState } from 'react'
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom'
import { api } from '../services/api'
import { useAuth } from '../context/AuthContext'

const labels = {
  dog: 'Cachorro',
  cat: 'Gato',
  other: 'Outro',
  male: 'Macho',
  female: 'Fêmea',
  unknown: 'A confirmar',
  small: 'Pequeno',
  medium: 'Médio',
  large: 'Grande',
}

const healthLabels = {
  vaccinated: 'Vacinado(a)',
  neutered: 'Castrado(a)',
  dewormed: 'Vermifugado(a)',
  special_needs: 'Necessidades especiais',
}

function formatHealthBadges(rawHealth) {
  if (Array.isArray(rawHealth)) {
    return rawHealth
      .filter(item => typeof item === 'string' && item.trim() && item !== 'notes')
      .map(item => healthLabels[item] || item)
  }
  if (!rawHealth || typeof rawHealth !== 'object') {
    return []
  }
  return Object.entries(rawHealth)
    .filter(([key, value]) => key !== 'notes' && Boolean(value))
    .map(([key]) => healthLabels[key] || key.replaceAll('_', ' '))
}

export default function PetDetail() {
  const { user, loading: authLoading } = useAuth()
  const { id } = useParams()
  const navigate = useNavigate()
  const location = useLocation()
  const [pet, setPet] = useState(null)
  const [activePhoto, setActivePhoto] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [formOpen, setFormOpen] = useState(false)
  const [message, setMessage] = useState('')

  useEffect(() => {
    api.pet(id).then(setPet).catch(err => setError(err.message)).finally(() => setLoading(false))
  }, [id])

  if (loading) return <main className="container empty"><p>Buscando esse bichinho…</p></main>
  if (!pet) return <main className="container empty"><h1>Esse bichinho não foi encontrado</h1><p>{error}</p><Link className="button" to="/animais">Explorar catálogo</Link></main>

  const galleryPhotos = Array.isArray(pet.photos)
    ? pet.photos.map(item => (typeof item === 'string' ? item : item?.url)).filter(Boolean)
    : []
  const allPhotos = galleryPhotos.length
    ? galleryPhotos
    : pet.cover_photo
      ? [pet.cover_photo]
      : []
  const photo = (activePhoto && allPhotos.includes(activePhoto))
    ? activePhoto
    : (pet.cover_photo || allPhotos[0] || null)

  const health = formatHealthBadges(pet.health)
  const healthNotes = !Array.isArray(pet.health) && typeof pet.health?.notes === 'string'
    ? pet.health.notes.trim()
    : ''
  const locationParts = [pet.location?.neighborhood, pet.location?.city].filter(Boolean).join(' · ')
  const facts = [
    ['Espécie', labels[pet.species] || pet.species],
    ...(pet.breed ? [['Raça', pet.breed]] : []),
    ['Idade', pet.approximate_age || 'A confirmar'],
    ['Porte', labels[pet.size] || pet.size],
    ['Sexo', labels[pet.sex] || pet.sex || 'A confirmar'],
  ]

  async function share() {
    if (navigator.share) await navigator.share({ title: `Adote ${pet.name}`, url: window.location.href })
    else await navigator.clipboard.writeText(window.location.href)
    setMessage('Link pronto para compartilhar!')
  }

  function handleAdoptClick() {
    if (!user) {
      navigate('/login', { state: { from: `${location.pathname}${location.search}` } })
      return
    }
    setFormOpen(value => !value)
  }

  return <main id="main" className="container detail">
    <div className="detail-top"><Link to="/animais">← Voltar para o catálogo</Link><button className="text-button" onClick={share}>Compartilhar ↗</button></div>
    <div className="detail-grid"><div>
      <div className="detail-photo">
        {photo ? <img src={photo} alt={`${pet.name} para adoção`} /> : <div className="empty">Foto em breve</div>}
        {allPhotos.length > 1 && (
          <div className="detail-gallery" aria-label="Galeria de fotos do animal">
            {allPhotos.map((url, index) => (
              <button
                key={`${url}-${index}`}
                type="button"
                className={`detail-thumb ${photo === url ? 'is-active' : ''}`}
                aria-label={`Ver foto ${index + 1} de ${pet.name}`}
                aria-pressed={photo === url}
                onClick={() => setActivePhoto(url)}
              >
                <img src={url} alt="" />
              </button>
            ))}
          </div>
        )}
      </div>
      <section className="detail-story"><span className="eyebrow">UM POUQUINHO SOBRE MIM</span><h2>Oi, eu sou {pet.name}!</h2><p>{pet.description}</p>
        <div className="tags">{pet.personality?.map(tag => <span key={tag}>{tag}</span>)}</div>
        <h3>Cuidados de saúde</h3>
        {health.length > 0 && <div className="health">{health.map(item => <span key={item}>✓ {item}</span>)}</div>}
        {healthNotes && <p className="health-notes">{healthNotes}</p>}
        <p className="muted">Confirme o histórico e as necessidades de saúde diretamente com a organização.</p>
      </section>
    </div><aside className="adoption-card">
      <span className={`status ${pet.status === 'adopted' ? 'adopted' : ''}`}>{pet.status === 'adoption_in_progress' ? '● Reservado — em processo de adoção' : pet.status === 'adopted' ? '● Já encontrou um lar' : '● À espera de uma família'}</span><h1>{pet.name}</h1><p className="muted">⌖ {locationParts}{pet.location?.state ? `, ${pet.location.state}` : ''}</p>
      <div className="pet-facts">{facts.map(([label, value]) => <div key={label}><small>{label}</small><strong>{value}</strong></div>)}</div>
      <hr /><h3>Um lar é um compromisso para a vida.</h3><p>Conte um pouco sobre você para quem cuida de {pet.name}.</p>
      {(!pet.status || pet.status === 'published') ? <button className="button full-width" disabled={authLoading} onClick={handleAdoptClick}>{formOpen ? 'Fechar formulário' : `Quero adotar ${pet.name} ↗`}</button> : <p className="muted">{pet.status === 'adoption_in_progress' ? 'Este animal está reservado em processo de adoção no momento.' : 'Este animal já foi adotado.'}</p>}
      <p className="small muted">Seus dados serão enviados somente à organização responsável.</p>
      {pet.organization && <Link className="organization" to={`/organizacoes/${pet.organization.slug}`}><span className="org-avatar">♡</span><div><strong>{pet.organization.name} ↗</strong><small>{pet.organization.city}, {pet.organization.state}</small><small>Conheça quem cuida de mim</small></div></Link>}
    </aside></div>
    {formOpen && (!pet.status || pet.status === 'published') && user && <AdoptionForm pet={pet} user={user} onSuccess={() => { setFormOpen(false); setMessage('Interesse enviado! Acompanhe o andamento em Minha conta.') }} />}
    <p role="status" className="feedback">{message}</p>
  </main>
}

function AdoptionForm({ pet, user, onSuccess }) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  async function submit(event) {
    event.preventDefault(); setBusy(true); setError('')
    const data = Object.fromEntries(new FormData(event.currentTarget))
    data.has_other_pets = data.has_other_pets === '1'
    try { await api.adoptionRequest(pet.id, data); onSuccess() }
    catch (err) { setError(Object.values(err.errors || {}).flat().join(' ') || err.message) }
    finally { setBusy(false) }
  }
  return <section className="panel"><span className="eyebrow">ADOÇÃO RESPONSÁVEL</span><h2>Conte sobre o futuro lar de {pet.name}</h2>
    <form onSubmit={submit} className="adoption-form">
      <label className="field">Nome completo<input name="name" defaultValue={user?.name || ''} required maxLength={120} /></label>
      <label className="field">E-mail<input name="email" type="email" defaultValue={user?.email || ''} required /></label>
      <label className="field">Telefone/WhatsApp<input name="phone" required maxLength={30} /></label>
      <label className="field">Cidade<input name="city" required /></label>
      <label className="field">Estado (UF)<input name="state" required minLength={2} maxLength={2} /></label>
      <label className="field">Tipo de moradia<select name="housing_type" required><option value="">Selecione</option><option value="house">Casa</option><option value="apartment">Apartamento</option><option value="farm">Sítio ou fazenda</option><option value="other">Outro</option></select></label>
      <label className="field">Possui outros animais?<select name="has_other_pets" required><option value="0">Não</option><option value="1">Sim</option></select></label>
      <label className="field">Experiência com animais<textarea name="experience" maxLength={1000} /></label>
      <label className="field">Por que deseja adotar?<textarea name="message" required minLength={20} maxLength={2000} /></label>
      <label className="checkbox-label"><input name="privacy_consent" value="1" type="checkbox" required /> Autorizo o envio destes dados à organização responsável por este animal.</label>
      <button className="button" disabled={busy}>{busy ? 'Enviando…' : 'Enviar interesse'}</button><p className="feedback" role="alert">{error}</p>
    </form>
  </section>
}
