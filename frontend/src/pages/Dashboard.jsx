import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { api } from '../services/api'
import OrganizationEditor from '../components/OrganizationEditor'
import AdoptionManagement from '../components/AdoptionManagement'
import PanelShell from '../components/PanelShell'

const statusLabel = { draft: 'Rascunho', published: 'Disponível', adoption_in_progress: 'Em processo', adopted: 'Adotado', archived: 'Arquivado' }
const speciesLabel = { dog: 'Cachorro', cat: 'Gato', other: 'Outro' }
const sizeLabel = { small: 'Pequeno', medium: 'Médio', large: 'Grande' }

export default function Dashboard() {
  const { user } = useAuth()
  const approved = user?.organization?.verification_status === 'approved' || user?.role === 'admin'
  const [items, setItems] = useState([])
  const [requests, setRequests] = useState([])
  const [query, setQuery] = useState('')
  const [message, setMessage] = useState('')
  const [showForm, setShowForm] = useState(false)
  const [loading, setLoading] = useState(approved)

  useEffect(() => {
    if (!approved) return
    Promise.all([api.organizationPets(), api.adoptionRequests()])
      .then(([petsResponse, requestsResponse]) => {
        setItems(petsResponse.data || [])
        setRequests(requestsResponse.data || [])
      })
      .catch(error => setMessage(error.message))
      .finally(() => setLoading(false))
  }, [approved])

  async function toggleAvailability(pet) {
    try {
      const makeAvailable = pet.status === 'adopted' || pet.status === 'draft'
      const updated = await api[makeAvailable ? 'publishPet' : 'markAdopted'](pet.id)
      setItems(current => current.map(item => item.id === pet.id ? updated : item))
      setMessage(makeAvailable ? `${pet.name} está disponível novamente.` : `${pet.name} ganhou um novo lar!`)
    } catch (error) { setMessage(error.message) }
  }

  const visible = items.filter(pet => pet.name.toLowerCase().includes(query.toLowerCase()))
  const available = items.filter(pet => pet.status === 'published').length
  return <PanelShell title="Seus bichinhos" subtitle={`${user?.organization?.name || user?.name} · Acompanhe quem espera um lar e os novos pedidos de adoção.`}>
    <section className="dashboard-main">
      {user?.organization?.verification_status !== 'approved' && <p className="notice">O cadastro está em análise. Você poderá gerenciar animais assim que a organização for aprovada.</p>}
      <OrganizationEditor organization={user?.organization} />
      <div className="stats">{[['Animais cadastrados', items.length], ['Esperando um lar', available], ['Interesses recebidos', requests.length]].map(([label, count]) => <div key={label}><span>{label}</span><strong>{String(count).padStart(2, '0')}</strong></div>)}</div>
      <div className="panel"><div className="panel-toolbar"><label className="field">Buscar por nome<input type="search" value={query} onChange={event => setQuery(event.target.value)} placeholder="Digite o nome do animal" /></label><button className="button" disabled={!approved} onClick={() => setShowForm(value => !value)}>{showForm ? 'Fechar cadastro' : '+ Cadastrar animal'}</button></div>
        {showForm && <PetForm onCreated={pet => { setItems(current => [pet, ...current]); setShowForm(false); setMessage('Animal cadastrado como rascunho. Revise e publique quando quiser.') }} />}
        {loading ? <p>Carregando animais…</p> : <div className="table-scroll"><table><caption className="sr-only">Animais da organização</caption><thead><tr><th>Animal</th><th>Perfil</th><th>Status</th><th>Ações</th></tr></thead><tbody>
          {visible.map(pet => <tr key={pet.id}><td><Link className="table-pet" to={`/pets/${pet.slug}`}>{pet.cover_photo ? <img src={pet.cover_photo} alt="" /> : <span className="pet-thumb-placeholder">♡</span>}<strong>{pet.name}</strong></Link></td><td>{speciesLabel[pet.species] || pet.species}<small>{pet.approximate_age || 'Idade a confirmar'} · {sizeLabel[pet.size] || pet.size}</small></td><td><span className={`status ${pet.status === 'adopted' ? 'adopted' : ''}`}>{statusLabel[pet.status] || pet.status}</span></td><td>{['published', 'adopted', 'draft'].includes(pet.status) && <button className="text-button" onClick={() => toggleAvailability(pet)}>{pet.status === 'published' ? 'Marcar adotado' : 'Disponibilizar'}</button>}</td></tr>)}
        </tbody></table>{visible.length === 0 && <p className="empty">Nenhum animal encontrado.</p>}</div>}
        <p className="feedback" role="status">{message}</p>
      </div>
      {approved && <AdoptionManagement />}
    </section>
  </PanelShell>
}

function PetForm({ onCreated }) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  async function submit(event) {
    event.preventDefault(); setBusy(true); setError('')
    const formData = new FormData(event.currentTarget)
    const photo = formData.get('photo')
    const values = Object.fromEntries(formData)
    const body = { ...values, location: { city: values.city, state: values.state }, personality: values.personality.split(',').map(value => value.trim()).filter(Boolean) }
    delete body.city; delete body.state; delete body.photo
    try {
      let pet = await api.createPet(body)
      if (photo?.size) {
        const upload = new FormData()
        upload.append('photo', photo)
        upload.append('is_cover', '1')
        pet = await api.uploadPetPhoto(pet.id, upload)
      }
      onCreated(pet)
    } catch (err) { setError(Object.values(err.errors || {}).flat().join(' ') || err.message) } finally { setBusy(false) }
  }
  return <form className="pet-form" onSubmit={submit}><div className="pet-form-heading"><div><span className="eyebrow">NOVO PERFIL</span><h3>Conte a história desse bichinho</h3><p>Informações claras ajudam a encontrar uma família compatível.</p></div><span className="pet-form-step">Cadastro inicial</span></div>
    <div className="pet-form-grid">
      <label className="field">Nome<input name="name" required maxLength={80} placeholder="Ex.: Luna" /></label>
      <label className="field">Espécie<select name="species"><option value="dog">Cachorro</option><option value="cat">Gato</option><option value="other">Outro</option></select></label>
      <label className="field">Raça<input name="breed" placeholder="Ex.: Sem raça definida" /></label>
      <label className="field">Sexo<select name="sex"><option value="female">Fêmea</option><option value="male">Macho</option><option value="unknown">Não identificado</option></select></label>
      <label className="field">Porte<select name="size"><option value="small">Pequeno</option><option value="medium">Médio</option><option value="large">Grande</option></select></label>
      <label className="field">Idade aproximada<input name="approximate_age" placeholder="Ex.: 2 anos" /></label>
      <label className="field">Cidade<input name="city" required placeholder="São Paulo" /></label>
      <label className="field">Estado<input name="state" minLength={2} maxLength={2} required placeholder="SP" /></label>
      <label className="field pet-form-wide">Personalidade<input name="personality" placeholder="carinhoso, brincalhão, sociável" /></label>
      <label className="field pet-form-wide">Descrição<textarea name="description" required minLength={30} maxLength={4000} placeholder="Conte sobre a rotina, o temperamento e o lar ideal para esse animal." /></label>
      <label className="field pet-form-wide file-field"><span>Foto principal</span><input name="photo" type="file" accept="image/jpeg,image/png,image/webp" required /><small>JPG, PNG ou WebP. Use uma foto clara, sem texto ou marca-d'água.</small></label>
    </div>
    <div className="pet-form-actions"><p>O perfil será salvo como rascunho para sua revisão.</p><button className="button" disabled={busy}>{busy ? 'Salvando…' : 'Salvar perfil'}</button></div>
    {error && <div className="form-alert" role="alert"><span>!</span><p>{error}</p></div>}
  </form>
}
