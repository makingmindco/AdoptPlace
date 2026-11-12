import { useEffect, useState } from 'react'
import { Navigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { api } from '../services/api'
import { Pagination } from './Account'

export default function Admin() {
  const { user, loading } = useAuth()
  if (loading) return <main className="container empty">Carregando…</main>
  if (!user) return <Navigate to="/login" replace state={{ from: '/administracao' }} />
  if (user.role !== 'admin') return <Navigate to="/" replace />
  return <Moderation />
}
function Moderation() {
  const [orgs, setOrgs] = useState({ data: [], meta: {} })
  const [reviews, setReviews] = useState({ data: [] })
  const [page, setPage] = useState(1)
  const [reviewPage, setReviewPage] = useState(1)
  const [reviewStatus, setReviewStatus] = useState('pending')
  const [version, setVersion] = useState(0)
  const [message, setMessage] = useState('')
  const [busy, setBusy] = useState(false)
  useEffect(() => {
    let active = true
    Promise.all([api.adminOrganizations(page), api.adminReviews(reviewPage, reviewStatus)]).then(([o, r]) => { if (active) { setOrgs(o); setReviews(r) } }).catch(error => { if (active) setMessage(error.message) })
    return () => { active = false }
  }, [page, reviewPage, reviewStatus, version])
  async function perform(operation) {
    setBusy(true)
    try { await operation(); setMessage('Moderação salva.'); setVersion(v => v + 1) } catch (error) { setMessage(error.message) } finally { setBusy(false) }
  }
  return <main id="main" className="container detail"><h1>Administração</h1><p role="status">{message}</p><section className="panel"><h2>Organizações e protetores</h2>
    {orgs.data.map(org => <article className="adoption-item" key={org.id}><h3>{org.name}</h3><p>{org.city}, {org.state} · {org.verification_status}</p><p>{org.description}</p><p>CNPJ informado: {org.document || 'Não informado'}</p><p>{org.email} · {org.phone}</p>
      <form onSubmit={event => { event.preventDefault(); const data = Object.fromEntries(new FormData(event.currentTarget)); perform(() => api.moderateOrganization(org.id, data)) }}><label className="field">Situação<select name="status" defaultValue={org.verification_status}>{[['pending','Em análise'],['approved','Aprovar'],['rejected','Rejeitar'],['suspended','Suspender']].map(([value,label]) => <option key={value} value={value}>{label}</option>)}</select></label><label className="field">Justificativa<textarea name="notes" required maxLength={2000} defaultValue={org.verification_notes || ''} /></label><button className="button" disabled={busy}>Salvar decisão</button></form></article>)}<Pagination page={page} meta={orgs.meta} onChange={setPage} />
    </section><section className="panel"><h2>Moderação de avaliações</h2><label className="field">Filtrar avaliações<select value={reviewStatus} onChange={event => { setReviewStatus(event.target.value); setReviewPage(1) }}><option value="pending">Pendentes</option><option value="published">Publicadas</option><option value="hidden">Não publicadas</option></select></label>{!reviews.data.length && <p>Nenhuma avaliação pendente.</p>}{reviews.data.map(item => <article className="adoption-item" key={item.id}><strong>{item.review.rating} de 5</strong><p>{item.review.comment}</p><button className="button" disabled={busy} onClick={() => perform(() => api.moderateReview(item.id, 'published'))}>Publicar</button><button className="button button-outline" disabled={busy} onClick={() => perform(() => api.moderateReview(item.id, 'hidden'))}>Não publicar</button></article>)}<Pagination page={reviewPage} meta={reviews} onChange={setReviewPage} /></section></main>
}
