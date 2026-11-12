import { useEffect, useState } from 'react'
import { Navigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { api } from '../services/api'
import { Pagination } from './Account'
import PanelShell, { PanelIcon } from '../components/PanelShell'

const statusLabels = { pending: 'Em análise', approved: 'Aprovada', rejected: 'Não aprovada', suspended: 'Suspensa' }
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
  const [query, setQuery] = useState('')
  const [message, setMessage] = useState('')
  const [busy, setBusy] = useState(false)
  const [loaded, setLoaded] = useState(false)
  useEffect(() => {
    let active = true
    Promise.all([api.adminOrganizations(page), api.adminReviews(reviewPage, reviewStatus)])
      .then(([o, r]) => { if (active) { setOrgs(o); setReviews(r); setLoaded(true) } })
      .catch(error => { if (active) setMessage(error.message) })
    return () => { active = false }
  }, [page, reviewPage, reviewStatus, version])
  async function perform(operation) {
    setBusy(true); setMessage('')
    try { await operation(); setMessage('Moderação salva.'); setVersion(v => v + 1) } catch (error) { setMessage(error.message) } finally { setBusy(false) }
  }
  const visible = orgs.data.filter(org => org.name.toLocaleLowerCase('pt-BR').includes(query.toLocaleLowerCase('pt-BR')))
  return <PanelShell title="Administração" subtitle="Um olhar atento para quem cuida. Gerencie cadastros e acompanhe a comunidade." action={<span className="workspace-access"><PanelIcon name="shield" />Acesso administrativo</span>}>
    <div className="workspace-metrics">
      <div><span className="metric-icon"><PanelIcon name="person" /></span><div><small>Organizações e protetores</small><strong>{loaded ? orgs.meta.total ?? orgs.data.length : '—'}</strong><span>Cadastros na plataforma</span></div></div>
      <div><span className="metric-icon amber"><PanelIcon name="message" /></span><div><small>Avaliações · {{ pending: 'pendentes', published: 'publicadas', hidden: 'não publicadas' }[reviewStatus]}</small><strong>{loaded ? reviews.total ?? reviews.data.length : '—'}</strong><span>Na seleção atual</span></div></div>
      <a href="#avaliacoes" className="metric-shortcut"><PanelIcon name="shield" /><div><strong>Uma comunidade de confiança</strong><span>Revise as experiências de quem adotou.</span></div><PanelIcon name="arrow" /></a>
    </div>
    <p role="status" className={message ? 'workspace-feedback' : 'workspace-feedback is-empty'}>{message}</p>
    <section className="workspace-section" aria-labelledby="organizations-title"><div className="workspace-section-heading"><div><h2 id="organizations-title">Organizações e protetores</h2><p>Confira as informações antes de atualizar a situação do cadastro.</p></div><label className="workspace-search"><span className="sr-only">Buscar organização nesta página</span><input type="search" placeholder="Buscar nesta página…" value={query} onChange={event => setQuery(event.target.value)} /></label></div>
      {!loaded && !message && <p className="workspace-empty" role="status">Carregando cadastros…</p>}
      {loaded && !visible.length && <div className="workspace-empty"><PanelIcon name="person" /><strong>Nenhum cadastro encontrado</strong><p>{query ? 'Tente outro nome nesta página.' : 'Os novos cadastros aparecerão aqui para análise.'}</p></div>}
      <div className="moderation-list">{visible.map(org => <article className="moderation-card" key={org.id}>
        <div className="moderation-profile"><div className="moderation-profile-title"><span className="provider-avatar">{org.name.slice(0, 1).toUpperCase()}</span><div><h3>{org.name}</h3><span>{org.type === 'ngo' ? 'Organização' : 'Protetor individual'} · {org.city}, {org.state}</span></div></div>
          <span className={`workspace-badge ${org.verification_status}`}>{statusLabels[org.verification_status] || 'Em análise'}</span><p className="provider-description">{org.description || 'Apresentação não informada.'}</p>
          <dl className="provider-info"><div><dt>CNPJ</dt><dd>{org.document || 'Não informado'}</dd></div><div><dt>E-mail</dt><dd>{org.email || 'Não informado'}</dd></div><div><dt>Telefone</dt><dd>{org.phone || 'Não informado'}</dd></div></dl>
        </div>
        <form className="moderation-form" onSubmit={event => { event.preventDefault(); const data = Object.fromEntries(new FormData(event.currentTarget)); perform(() => api.moderateOrganization(org.id, data)) }}><span className="workspace-label">ANÁLISE DO CADASTRO</span><label className="field">Situação<select name="status" defaultValue={org.verification_status}>{[['pending','Em análise'],['approved','Aprovar cadastro'],['rejected','Não aprovar'],['suspended','Suspender cadastro']].map(([value,label]) => <option key={value} value={value}>{label}</option>)}</select></label><label className="field">Justificativa<textarea name="notes" required maxLength={2000} defaultValue={org.verification_notes || ''} placeholder="Registre o motivo da sua decisão…" rows={3} /></label><div className="moderation-form-footer"><span>A decisão ficará registrada.</span><button className="button" disabled={busy}>{busy ? 'Salvando…' : 'Salvar decisão'}</button></div></form>
      </article>)}</div><Pagination page={page} meta={orgs.meta} onChange={setPage} />
    </section>
    <section className="workspace-section" id="avaliacoes" aria-labelledby="reviews-title"><div className="workspace-section-heading"><div><h2 id="reviews-title">Moderação de avaliações</h2><p>Histórias reais de adoção, com espaço para uma experiência respeitosa.</p></div><label className="field review-filter">Filtrar avaliações<select value={reviewStatus} onChange={event => { setReviewStatus(event.target.value); setReviewPage(1) }}><option value="pending">Pendentes</option><option value="published">Publicadas</option><option value="hidden">Não publicadas</option></select></label></div>
      {loaded && !reviews.data.length && <div className="workspace-empty"><PanelIcon name="message" /><strong>{reviewStatus === 'pending' ? 'Tudo em dia por aqui' : 'Nenhuma avaliação nesta seleção'}</strong><p>{reviewStatus === 'pending' ? 'Quando uma nova avaliação chegar, ela aparecerá aqui para você revisar.' : 'Escolha outro filtro para consultar as avaliações.'}</p></div>}
      {reviews.data.map(item => <article className="moderation-review" key={item.id}><div><span className="review-rating"><span aria-hidden="true">★</span> {item.review.rating}<small>/ 5</small></span><p>{item.review.comment}</p></div><div className="review-actions"><button className="button button-outline" disabled={busy} onClick={() => perform(() => api.moderateReview(item.id, 'hidden'))}>Não publicar</button><button className="button" disabled={busy} onClick={() => perform(() => api.moderateReview(item.id, 'published'))}>Publicar</button></div></article>)}<Pagination page={reviewPage} meta={reviews} onChange={setReviewPage} />
    </section>
  </PanelShell>
}
