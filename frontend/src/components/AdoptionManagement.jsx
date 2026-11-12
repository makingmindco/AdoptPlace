import { useEffect, useState } from 'react'
import { api } from '../services/api'
import { Pagination } from '../pages/Account'

const labels = { new: 'Novo interesse', reviewing: 'Em análise', contacted: 'Em contato', approved: 'Aprovada — aguardando confirmação do adotante', completed: 'Concluída', rejected: 'Não aprovada', withdrawn: 'Cancelada pelo adotante' }
export default function AdoptionManagement() {
  const [result, setResult] = useState({ data: [], meta: {} })
  const [page, setPage] = useState(1)
  const [version, setVersion] = useState(0)
  const [message, setMessage] = useState('')
  const [busy, setBusy] = useState(false)
  useEffect(() => {
    let active = true
    api.adoptionRequests(page).then(data => { if (active) setResult(data) }).catch(error => { if (active) setMessage(error.message) })
    return () => { active = false }
  }, [page, version])
  async function save(event, item) {
    event.preventDefault(); setBusy(true)
    const data = Object.fromEntries(new FormData(event.currentTarget))
    try { await api.updateAdoptionRequest(item.id, data); setVersion(v => v + 1); setMessage('Andamento atualizado. O adotante pode acompanhar pela conta.') } catch (error) { setMessage(error.message) } finally { setBusy(false) }
  }
  return <section className="panel adoption-list"><h2>Interesses de adoção</h2><p>Aprove após combinar a adoção. O adotante confirma o recebimento pela própria conta, concluindo o processo e liberando a avaliação.</p><p role="status">{message}</p>
    {!result.data.length && <p>Nenhuma solicitação recebida.</p>}{result.data.map(item => <article className="adoption-item" key={item.id}><h3>{item.adopter?.name} · {item.pet?.name}</h3><p>{labels[item.status]}</p><p>{item.adopter?.email} · {item.adopter?.phone}</p><p>{item.adopter?.message}</p><p>Moradia: {item.adopter?.housing_type} · {item.adopter?.city}/{item.adopter?.state}</p>
      {!['completed', 'rejected', 'withdrawn'].includes(item.status) && <form onSubmit={event => save(event, item)}><label className="field">Próxima etapa<select name="status" defaultValue={item.status === 'new' ? 'reviewing' : item.status}>{['reviewing', 'contacted', 'approved', 'rejected'].map(status => <option key={status} value={status}>{labels[status]}</option>)}</select></label><label className="field">Anotações internas (não visíveis ao adotante)<textarea name="internal_notes" defaultValue={item.internal_notes || ''} maxLength={4000} /></label><button className="button" disabled={busy}>Atualizar solicitação</button></form>}
    </article>)}<Pagination page={page} meta={result.meta} onChange={setPage} /></section>
}
