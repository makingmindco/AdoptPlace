import { useEffect, useState } from 'react'
import { Link, Navigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { useFavorites } from '../context/FavoritesContext'
import { api } from '../services/api'
import PetCard from '../components/PetCard'

const statuses = { new: 'Interesse enviado', reviewing: 'Em análise', contacted: 'Em contato', approved: 'Aprovada — confirme após receber o animal', completed: 'Adoção concluída', rejected: 'Não aprovada', withdrawn: 'Cancelada por você' }
export default function Account() {
  const { user, loading } = useAuth()
  if (loading) return <main className="container empty">Carregando sua conta…</main>
  if (!user) return <Navigate to="/login" state={{ from: '/minha-conta' }} replace />
  return <AccountContent user={user} />
}
function AccountContent({ user }) {
  const { favorites } = useFavorites()
  const [pets, setPets] = useState({ data: [], meta: {} })
  const [adoptions, setAdoptions] = useState({ data: [], meta: {} })
  const [page, setPage] = useState(1)
  const [petPage, setPetPage] = useState(1)
  const [version, setVersion] = useState(0)
  const [message, setMessage] = useState('')
  const [busy, setBusy] = useState(false)
  useEffect(() => {
    let active = true
    Promise.all([api.favoritePets(petPage), api.myAdoptions(page)]).then(([p, a]) => { if (active) { setPets(p); setAdoptions(a) } }).catch(error => { if (active) setMessage(error.message) })
    return () => { active = false }
  }, [page, petPage, version, favorites])
  async function action(operation) {
    setBusy(true); setMessage('')
    try { const result = await operation(); setMessage(result?.message || 'Atualizado com sucesso.'); setVersion(value => value + 1) } catch (error) { setMessage(Object.values(error.errors || {}).flat().join(' ') || error.message) } finally { setBusy(false) }
  }
  return <main id="main" className="container detail"><h1>Olá, {user.name}</h1><p>Acompanhe seus encontros e os animais que conquistaram seu coração.</p>
    {!user.email_verified_at && <div className="auth-notice"><p>Confirme seu e-mail pelo link enviado para sua caixa de entrada.</p><button className="button button-outline" disabled={busy} onClick={() => action(api.resendVerification)}>Reenviar confirmação</button></div>}
    <p role="status" className="feedback">{message}</p>
    <section className="panel"><h2>Minhas adoções</h2>{!adoptions.data.length && <p>Você ainda não enviou solicitações. <Link to="/#catalogo">Conheça os animais disponíveis.</Link></p>}
      {adoptions.data.map(item => <article key={item.id} className="adoption-item"><h3>{item.pet?.name || 'Animal indisponível'}</h3><p><strong>{statuses[item.status] || item.status}</strong></p>
        {item.pet?.organization && <Link to={`/organizacoes/${item.pet.organization.slug}`}>{item.pet.organization.name} ↗</Link>}
        {item.status === 'approved' && <button className="button" disabled={busy} onClick={() => { if (window.confirm('Você já recebeu o animal e deseja confirmar a adoção?')) action(() => api.adoptionAction(item.id, 'confirm')) }}>Recebi o animal: confirmar adoção</button>}
        {['new', 'reviewing', 'contacted', 'approved'].includes(item.status) && <button className="button button-outline" disabled={busy} onClick={() => { if (window.confirm('Deseja cancelar esta solicitação de adoção?')) action(() => api.adoptionAction(item.id, 'withdraw')) }}>Cancelar solicitação</button>}
        {item.status === 'completed' && (item.review ? <p>Avaliação enviada · {item.review.status === 'published' ? 'Publicada' : item.review.status === 'hidden' ? 'Não publicada' : 'Aguardando moderação'}</p> : <ReviewForm busy={busy} onSave={body => action(() => api.review(item.id, body))} />)}
      </article>)}<Pagination page={page} meta={adoptions.meta} onChange={setPage} />
    </section>
    <section><h2>Meus favoritos</h2><p className="muted">Salvos na sua conta. Aqui aparecem apenas animais ainda disponíveis.</p><div className="pet-grid">{pets.data.map(pet => <PetCard key={pet.id} pet={pet} />)}</div>{!pets.data.length && <p>Nenhum animal disponível nos seus favoritos.</p>}<Pagination page={petPage} meta={pets.meta} onChange={setPetPage} /></section>
  </main>
}
export function Pagination({ page, meta, onChange }) {
  if (!meta || meta.last_page <= 1 || !meta.last_page) return null
  return <div className="register-actions"><button className="button button-outline" disabled={page <= 1} onClick={() => onChange(page - 1)}>Anterior</button><span>Página {page} de {meta.last_page}</span><button className="button button-outline" disabled={page >= meta.last_page} onClick={() => onChange(page + 1)}>Próxima</button></div>
}
function ReviewForm({ busy, onSave }) {
  return <form onSubmit={event => { event.preventDefault(); const data = Object.fromEntries(new FormData(event.currentTarget)); onSave({ ...data, rating: Number(data.rating) }) }}>
    <h3>Como foi sua experiência?</h3><label className="field">Nota<select name="rating" required><option value="">Selecione</option>{[5, 4, 3, 2, 1].map(n => <option key={n} value={n}>{n} de 5</option>)}</select></label>
    <label className="field">Sua avaliação<textarea name="comment" required minLength={10} maxLength={1500} /></label><p className="small muted">Sua avaliação será pública após moderação. Não inclua telefone, endereço ou outros dados pessoais.</p><button className="button" disabled={busy}>Enviar avaliação</button>
  </form>
}
