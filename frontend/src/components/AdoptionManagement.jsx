import { useEffect, useState } from 'react'
import { api } from '../services/api'
import { Pagination } from '../pages/Account'

const labels = { new: 'Novo interesse', reviewing: 'Em análise', contacted: 'Em contato', approved: 'Aprovada — aguardando confirmação do adotante', completed: 'Concluída', rejected: 'Não aprovada', withdrawn: 'Cancelada pelo adotante' }
const petStatusLabels = { draft: 'Rascunho', published: 'Disponível', adoption_in_progress: 'Reservado (em processo)', adopted: 'Adotado', archived: 'Arquivado' }
const housingLabels = { house: 'Casa', apartment: 'Apartamento', farm: 'Sítio ou fazenda', other: 'Outro' }
export default function AdoptionManagement({ onUpdated }) {
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
    try { await api.updateAdoptionRequest(item.id, data); setVersion(v => v + 1); onUpdated?.(); setMessage('Andamento atualizado. O adotante pode acompanhar pela conta.') } catch (error) { setMessage(error.message) } finally { setBusy(false) }
  }
  return <section className="panel adoption-list"><h2>Interesses de adoção</h2><p>Aprove após combinar a adoção. O animal ficará reservado até que o adotante confirme o recebimento pela própria conta, concluindo o processo e liberando a avaliação.</p><p role="status">{message}</p>
    {!result.data.length && <p>Nenhuma solicitação recebida.</p>}{result.data.map(item => {
      const petReservedElsewhere = item.pet?.status === 'adoption_in_progress' && item.status !== 'approved'
      const petUnavailable = ['adopted', 'archived'].includes(item.pet?.status)
      const lastReason = item.status_history?.at?.(-1)?.reason
      const housing = housingLabels[item.adopter?.housing_type] || item.adopter?.housing_type || 'Não informada'
      const hasOtherPets = item.adopter?.has_other_pets === undefined || item.adopter?.has_other_pets === null
        ? null
        : (item.adopter.has_other_pets ? 'Sim' : 'Não')
      return <article className="adoption-item" key={item.id}><h3>{item.adopter?.name} · {item.pet?.name}</h3><p><strong>{labels[item.status]}</strong>{item.pet?.status ? ` · Animal: ${petStatusLabels[item.pet.status] || item.pet.status}` : ''}</p>{lastReason && <p className="small muted">{lastReason}</p>}<p>{item.adopter?.email} · {item.adopter?.phone}</p><p>{item.adopter?.message}</p><p>Moradia: {housing} · {item.adopter?.city}/{item.adopter?.state}{hasOtherPets ? ` · Possui outros animais: ${hasOtherPets}` : ''}</p>{item.adopter?.experience && <p className="small muted">Experiência com animais: {item.adopter.experience}</p>}
        {petReservedElsewhere && !['completed', 'rejected', 'withdrawn'].includes(item.status) && <p className="small muted">Este animal já possui outra solicitação aprovada e está reservado no momento.</p>}
        {!['completed', 'rejected', 'withdrawn'].includes(item.status) && !petUnavailable && <form onSubmit={event => save(event, item)}><label className="field">Próxima etapa<select name="status" defaultValue={item.status === 'new' ? 'reviewing' : item.status}>{['reviewing', 'contacted', 'approved', 'rejected'].map(status => <option key={status} value={status} disabled={status === 'approved' && petReservedElsewhere}>{labels[status]}</option>)}</select></label><label className="field">Anotações internas (não visíveis ao adotante)<textarea name="internal_notes" defaultValue={item.internal_notes || ''} maxLength={4000} /></label><button className="button" disabled={busy}>Atualizar solicitação</button></form>}
      </article>
    })}<Pagination page={page} meta={result.meta} onChange={setPage} /></section>
}
