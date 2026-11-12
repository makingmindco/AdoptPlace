import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { api } from '../services/api'
import PetCard from '../components/PetCard'
import Reviews from '../components/Reviews'

export default function Organization() {
  const { slug } = useParams()
  const [profile, setProfile] = useState(null)
  const [pets, setPets] = useState([])
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [busy, setBusy] = useState(true)
  const [error, setError] = useState('')
  useEffect(() => {
    let active = true
    Promise.all([api.publicOrganization(slug), api.publicOrganizationPets(slug, page)])
      .then(([organization, result]) => { if (active) { setProfile(organization); setPets(result.data); setLastPage(result.meta.last_page) } })
      .catch(() => { if (active) setError('Não foi possível carregar este perfil. Ele pode estar indisponível.') })
      .finally(() => { if (active) setBusy(false) })
    return () => { active = false }
  }, [slug, page])
  return <main id="main" className="container detail org-profile">
    <Link to="/animais" className="org-back-link">← Voltar ao catálogo</Link>
    {error && <p role="alert">{error}</p>}
    {busy ? <p role="status">Carregando perfil…</p> : profile && <>
      <section className="panel org-hero-panel"><span className="status">{profile.type === 'ngo' ? 'ONG' : 'Protetor individual'} · Cadastro aprovado</span>
        <h1>{profile.name}</h1><p className="muted">{profile.city}, {profile.state}</p>
        <p style={{ whiteSpace: 'pre-wrap' }}>{profile.description || 'Cuidando de novos encontros e adoções responsáveis.'}</p>
        {profile.contact_publicly_visible && <div className="org-contact-box"><h2>Entre em contato</h2>{profile.email && <p><a href={`mailto:${profile.email}`}>{profile.email}</a></p>}{profile.phone && <p>{profile.phone}</p>}</div>}
      </section>
      <section className="org-pets-section"><h2>À espera de um lar</h2><div className="pet-grid">{pets.map(pet => <PetCard key={pet.id} pet={pet} />)}</div>
        {!pets.length && <p>Nenhum animal disponível neste momento.</p>}
        {lastPage > 1 && <div className="register-actions"><button className="button button-outline" disabled={page === 1} onClick={() => { setBusy(true); setPage(page - 1) }}>Anterior</button><span>Página {page} de {lastPage}</span><button className="button" disabled={page === lastPage} onClick={() => { setBusy(true); setPage(page + 1) }}>Próxima</button></div>}
      </section>
      <Reviews key={slug} slug={slug} />
    </>}
  </main>
}
