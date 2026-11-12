import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { api } from '../services/api'
import OrganizationEditor from '../components/OrganizationEditor'
import AdoptionManagement from '../components/AdoptionManagement'
import PanelShell from '../components/PanelShell'
import { Pagination } from './Account'

const statusLabel = {
  draft: 'Rascunho',
  published: 'Disponível',
  adoption_in_progress: 'Reservado (em processo)',
  adopted: 'Adotado',
  archived: 'Arquivado',
}
const speciesLabel = { dog: 'Cachorro', cat: 'Gato', other: 'Outro' }
const sizeLabel = { small: 'Pequeno', medium: 'Médio', large: 'Grande' }

function formatApiError(error) {
  return Object.values(error?.errors || {}).flat().join(' ') || error?.message || 'Não foi possível concluir a operação.'
}

function normalizeHealth(health) {
  if (Array.isArray(health)) {
    return {
      vaccinated: health.includes('vaccinated'),
      neutered: health.includes('neutered'),
      dewormed: health.includes('dewormed'),
      special_needs: health.includes('special_needs'),
      notes: '',
    }
  }
  return {
    vaccinated: Boolean(health?.vaccinated),
    neutered: Boolean(health?.neutered),
    dewormed: Boolean(health?.dewormed),
    special_needs: Boolean(health?.special_needs),
    notes: health?.notes || '',
  }
}

export default function Dashboard() {
  const { user } = useAuth()
  const approved = user?.organization?.verification_status === 'approved' || user?.role === 'admin'
  const [petsResult, setPetsResult] = useState({ data: [], meta: {}, counts: null })
  const [requests, setRequests] = useState([])
  const [requestsTotal, setRequestsTotal] = useState(0)
  const [page, setPage] = useState(1)
  const [statusFilter, setStatusFilter] = useState('')
  const [query, setQuery] = useState('')
  const [version, setVersion] = useState(0)
  const [message, setMessage] = useState('')
  const [formState, setFormState] = useState({ open: false, pet: null })
  const [loadedKey, setLoadedKey] = useState('')

  const requestKey = `${approved}:${page}:${statusFilter}:${query.trim()}:${version}`
  const loading = approved && loadedKey !== requestKey

  useEffect(() => {
    if (!approved) return
    let active = true
    const params = {}
    if (page > 1) params.page = page
    if (statusFilter) params.status = statusFilter
    if (query.trim()) params.search = query.trim()

    Promise.all([api.organizationPets(params), api.adoptionRequests()])
      .then(([petsResponse, requestsResponse]) => {
        if (!active) return
        const list = petsResponse?.data || []
        setPetsResult({
          data: list,
          meta: petsResponse?.meta || {},
          counts: petsResponse?.counts || null,
        })
        setRequests(requestsResponse?.data || [])
        setRequestsTotal(requestsResponse?.meta?.total ?? (requestsResponse?.data || []).length)
        setFormState(current => {
          if (!current.open || !current.pet) return current
          const refreshed = list.find(item => item.id === current.pet.id)
          return refreshed ? { ...current, pet: refreshed } : current
        })
      })
      .catch(error => {
        if (active) setMessage(formatApiError(error))
      })
      .finally(() => {
        if (active) setLoadedKey(requestKey)
      })
    return () => { active = false }
  }, [approved, page, statusFilter, query, version, requestKey])

  const items = petsResult.data
  const visible = items.filter(pet => {
    const matchesName = !query.trim() || pet.name?.toLowerCase().includes(query.trim().toLowerCase())
    const matchesStatus = !statusFilter || pet.status === statusFilter
    return matchesName && matchesStatus
  })

  const totalCount = petsResult.counts?.total ?? petsResult.meta?.total ?? items.length
  const availableCount = petsResult.counts?.published ?? items.filter(pet => pet.status === 'published').length

  function updatePetInList(updatedPet) {
    setPetsResult(current => {
      const exists = current.data.some(item => item.id === updatedPet.id)
      const nextData = exists
        ? current.data.map(item => (item.id === updatedPet.id ? updatedPet : item))
        : [updatedPet, ...current.data]
      return { ...current, data: nextData }
    })
    setFormState(current => (current.open && current.pet?.id === updatedPet.id ? { ...current, pet: updatedPet } : current))
  }

  function openCreate() {
    if (formState.open && !formState.pet) {
      setFormState({ open: false, pet: null })
    } else {
      setFormState({ open: true, pet: null })
    }
  }

  function openEdit(pet) {
    setFormState({ open: true, pet })
  }

  async function toggleAvailability(pet) {
    const makeAvailable = pet.status === 'adopted' || pet.status === 'draft' || pet.status === 'archived'
    if (!makeAvailable) {
      const confirmed = window.confirm(
        `Confirmar que ${pet.name} foi adotado fora da plataforma? Esta ação encerra solicitações em aberto e não substitui a confirmação do adotante em uma solicitação aprovada.`
      )
      if (!confirmed) return
    }
    try {
      const updated = await api[makeAvailable ? 'publishPet' : 'markAdopted'](pet.id)
      updatePetInList(updated)
      setVersion(v => v + 1)
      setMessage(
        makeAvailable
          ? `${pet.name} está disponível no catálogo público.`
          : `${pet.name} foi marcado como adotado externamente.`
      )
    } catch (error) {
      const formatted = formatApiError(error)
      setMessage(formatted)
      if (pet.status === 'draft') {
        setFormState({ open: true, pet })
      }
    }
  }

  async function handleArchive(pet) {
    const confirmed = window.confirm(
      `Deseja arquivar ${pet.name}? O animal sairá do catálogo público e solicitações de adoção em aberto poderão ser encerradas.`
    )
    if (!confirmed) return
    try {
      await api.deletePet(pet.id)
      setPetsResult(current => ({
        ...current,
        data: current.data.map(item => (item.id === pet.id ? { ...item, status: 'archived' } : item)),
      }))
      setFormState(current =>
        current.open && current.pet?.id === pet.id
          ? { ...current, pet: { ...current.pet, status: 'archived' } }
          : current
      )
      setVersion(v => v + 1)
      setMessage(`${pet.name} foi arquivado.`)
    } catch (error) {
      setMessage(formatApiError(error))
    }
  }

  return (
    <PanelShell
      title="Seus bichinhos"
      subtitle={`${user?.organization?.name || user?.name} · Acompanhe quem espera um lar e os novos pedidos de adoção.`}
    >
      <section className="dashboard-main">
        {user?.organization?.verification_status !== 'approved' && (
          <p className="notice">
            O cadastro está em análise. Você poderá gerenciar animais assim que a organização for aprovada.
          </p>
        )}
        <OrganizationEditor organization={user?.organization} />
        <div className="stats">
          {[
            ['Animais cadastrados', totalCount],
            ['Esperando um lar', availableCount],
            ['Interesses recebidos', requestsTotal || requests.length],
          ].map(([label, count]) => (
            <div key={label}>
              <span>{label}</span>
              <strong>{String(count).padStart(2, '0')}</strong>
            </div>
          ))}
        </div>

        <div className="panel">
          <div className="panel-toolbar">
            <label className="field">
              Buscar por nome
              <input
                type="search"
                value={query}
                onChange={event => {
                  setQuery(event.target.value)
                  setPage(1)
                }}
                placeholder="Digite o nome do animal"
              />
            </label>
            <label className="field">
              Filtrar por status
              <select
                value={statusFilter}
                onChange={event => {
                  setStatusFilter(event.target.value)
                  setPage(1)
                }}
              >
                <option value="">Todos os status</option>
                <option value="draft">Rascunho</option>
                <option value="published">Disponível</option>
                <option value="adoption_in_progress">Reservado (em processo)</option>
                <option value="adopted">Adotado</option>
                <option value="archived">Arquivado</option>
              </select>
            </label>
            <button className="button" disabled={!approved} onClick={openCreate}>
              {formState.open && !formState.pet ? 'Fechar cadastro' : '+ Cadastrar animal'}
            </button>
          </div>

          {formState.open && (
            <PetForm
              key={formState.pet?.id || 'create'}
              initialPet={formState.pet}
              organization={user?.organization}
              onClose={() => setFormState({ open: false, pet: null })}
              onSaved={(savedPet, options = {}) => {
                updatePetInList(savedPet)
                if (!options.keepEditing) {
                  setVersion(v => v + 1)
                }
                if (options.message) setMessage(options.message)
                if (options.keepEditing) {
                  setFormState({ open: true, pet: savedPet })
                } else {
                  setFormState({ open: false, pet: null })
                }
              }}
            />
          )}

          {loading ? (
            <p>Carregando animais…</p>
          ) : (
            <div className="table-scroll">
              <table>
                <caption className="sr-only">Animais da organização</caption>
                <thead>
                  <tr>
                    <th>Animal</th>
                    <th>Perfil</th>
                    <th>Status</th>
                    <th>Ações</th>
                  </tr>
                </thead>
                <tbody>
                  {visible.map(pet => (
                    <tr key={pet.id}>
                      <td>
                        {pet.status === 'published' ? (
                          <Link className="table-pet" to={`/pets/${pet.slug}`}>
                            {pet.cover_photo ? (
                              <img src={pet.cover_photo} alt="" />
                            ) : (
                              <span className="pet-thumb-placeholder">♡</span>
                            )}
                            <div>
                              <strong>{pet.name}</strong>
                              <small>Ver página pública ↗</small>
                            </div>
                          </Link>
                        ) : (
                          <button
                            type="button"
                            className="table-pet table-pet-button"
                            onClick={() => openEdit(pet)}
                          >
                            {pet.cover_photo ? (
                              <img src={pet.cover_photo} alt="" />
                            ) : (
                              <span className="pet-thumb-placeholder">♡</span>
                            )}
                            <div>
                              <strong>{pet.name}</strong>
                              <small>
                                {pet.status === 'draft'
                                  ? 'Rascunho privado · Clique para editar'
                                  : 'Editar informações'}
                              </small>
                            </div>
                          </button>
                        )}
                      </td>
                      <td>
                        {speciesLabel[pet.species] || pet.species}
                        <small>
                          {pet.approximate_age || 'Idade a confirmar'} · {sizeLabel[pet.size] || pet.size}
                        </small>
                      </td>
                      <td>
                        <span className={`status status-${pet.status} ${pet.status === 'adopted' ? 'adopted' : ''}`}>
                          {statusLabel[pet.status] || pet.status}
                        </span>
                      </td>
                      <td>
                        <div className="table-actions">
                          <button
                            type="button"
                            className="text-button"
                            aria-label={`Editar ${pet.name}`}
                            onClick={() => openEdit(pet)}
                          >
                            {pet.status === 'draft' ? 'Editar rascunho' : 'Editar'}
                          </button>
                          {['published', 'adopted', 'draft', 'archived'].includes(pet.status) && (
                            <button
                              type="button"
                              className="text-button"
                              onClick={() => toggleAvailability(pet)}
                            >
                              {pet.status === 'published'
                                ? 'Marcar adotado'
                                : pet.status === 'draft'
                                  ? 'Disponibilizar'
                                  : 'Republicar'}
                            </button>
                          )}
                          {['draft', 'published', 'adopted'].includes(pet.status) && (
                            <button
                              type="button"
                              className="text-button text-button-muted"
                              aria-label={`Arquivar ${pet.name}`}
                              onClick={() => handleArchive(pet)}
                            >
                              Arquivar
                            </button>
                          )}
                          {pet.status === 'adoption_in_progress' && (
                            <span className="small muted">Aguardando confirmação do adotante</span>
                          )}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
              {visible.length === 0 && <p className="empty">Nenhum animal encontrado.</p>}
              <Pagination page={page} meta={petsResult.meta} onChange={setPage} />
            </div>
          )}
          <p className="feedback" role="status">
            {message}
          </p>
        </div>
        {approved && <AdoptionManagement onUpdated={() => setVersion(v => v + 1)} />}
      </section>
    </PanelShell>
  )
}

function PetForm({ initialPet, organization, onClose, onSaved }) {
  const [pet, setPet] = useState(initialPet)
  const [busy, setBusy] = useState(false)
  const [photoBusy, setPhotoBusy] = useState(false)
  const [error, setError] = useState('')
  const [photoMessage, setPhotoMessage] = useState('')
  const [newPhotoCover, setNewPhotoCover] = useState(false)
  const newPhotoInputRef = useRef(null)

  const isEditing = Boolean(pet?.id)
  const health = normalizeHealth(pet?.health)
  const photos = pet?.photos || []

  function buildPayload(formData) {
    const values = Object.fromEntries(formData)
    const city = (values.city || '').trim()
    const state = (values.state || '').trim().toUpperCase()
    const neighborhood = (values.neighborhood || '').trim()

    const payload = {
      name: (values.name || '').trim(),
      species: values.species || 'dog',
      breed: (values.breed || '').trim() || null,
      sex: values.sex || 'female',
      size: values.size || 'small',
      approximate_age: (values.approximate_age || '').trim() || null,
      birth_date: values.birth_date || null,
      description: (values.description || '').trim() || null,
      personality: (values.personality || '')
        .split(',')
        .map(value => value.trim())
        .filter(Boolean),
      health: {
        vaccinated: formData.has('health_vaccinated'),
        neutered: formData.has('health_neutered'),
        dewormed: formData.has('health_dewormed'),
        special_needs: formData.has('health_special_needs'),
        notes: (values.health_notes || '').trim() || null,
      },
      location: {
        city: city || null,
        state: state || null,
        neighborhood: neighborhood || null,
      },
    }

    return payload
  }

  async function submit(event) {
    event.preventDefault()
    const publishAfterSave = event.nativeEvent?.submitter?.value === 'publish'
    setBusy(true)
    setError('')
    setPhotoMessage('')

    const formData = new FormData(event.currentTarget)
    const photo = formData.get('photo')
    const body = buildPayload(formData)

    if (!isEditing) {
      let created
      try {
        created = await api.createPet(body)
      } catch (err) {
        setError(formatApiError(err))
        setBusy(false)
        return
      }

      if (photo?.size) {
        try {
          const upload = new FormData()
          upload.append('photo', photo)
          upload.append('is_cover', '1')
          created = await api.uploadPetPhoto(created.id, upload)
        } catch (uploadErr) {
          setPet(created)
          const uploadMessage = formatApiError(uploadErr)
          setError(`O rascunho de ${created.name} foi salvo, mas o envio da foto falhou: ${uploadMessage}`)
          onSaved(created, {
            keepEditing: true,
            message: `Rascunho de ${created.name} salvo. Envie uma foto válida para concluir.`,
          })
          setBusy(false)
          return
        }
      }

      if (publishAfterSave) {
        try {
          const published = await api.publishPet(created.id)
          onSaved(published, {
            keepEditing: false,
            message: `${published.name} foi cadastrado e publicado no catálogo.`,
          })
        } catch (pubErr) {
          setPet(created)
          setError(formatApiError(pubErr))
          onSaved(created, {
            keepEditing: true,
            message: `Rascunho de ${created.name} salvo. Complete os dados obrigatórios para publicar.`,
          })
        } finally {
          setBusy(false)
        }
        return
      }

      onSaved(created, {
        keepEditing: false,
        message: 'Animal cadastrado como rascunho. Revise e publique quando quiser.',
      })
      setBusy(false)
      return
    }

    try {
      let updated = await api.updatePet(pet.id, body)
      setPet(updated)

      if (publishAfterSave) {
        try {
          updated = await api.publishPet(updated.id)
          setPet(updated)
          onSaved(updated, {
            keepEditing: false,
            message: `${updated.name} foi atualizado e disponibilizado no catálogo.`,
          })
          return
        } catch (pubErr) {
          const formatted = formatApiError(pubErr)
          setError(formatted)
          onSaved(updated, {
            keepEditing: true,
            message: `As alterações de ${updated.name} foram salvas no rascunho, mas ainda faltam dados para publicar.`,
          })
          return
        }
      }

      onSaved(updated, {
        keepEditing: false,
        message: `Informações de ${updated.name} atualizadas com sucesso.`,
      })
    } catch (err) {
      setError(formatApiError(err))
    } finally {
      setBusy(false)
    }
  }

  async function handleUploadExtraPhoto() {
    const file = newPhotoInputRef.current?.files?.[0]
    if (!file) {
      setError('Selecione um arquivo de imagem (JPG, PNG ou WebP) para enviar.')
      return
    }
    setPhotoBusy(true)
    setError('')
    setPhotoMessage('')
    try {
      const upload = new FormData()
      upload.append('photo', file)
      if (newPhotoCover || !pet.cover_photo) {
        upload.append('is_cover', '1')
      }
      const updated = await api.uploadPetPhoto(pet.id, upload)
      setPet(updated)
      setNewPhotoCover(false)
      if (newPhotoInputRef.current) newPhotoInputRef.current.value = ''
      setPhotoMessage('Fotografia adicionada com sucesso.')
      onSaved(updated, { keepEditing: true, message: `Nova foto adicionada para ${updated.name}.` })
    } catch (err) {
      setError(formatApiError(err))
    } finally {
      setPhotoBusy(false)
    }
  }

  async function handleSetCover(photoId) {
    setPhotoBusy(true)
    setError('')
    setPhotoMessage('')
    try {
      const updated = await api.setPetCoverPhoto(pet.id, photoId)
      setPet(updated)
      setPhotoMessage('Foto principal atualizada.')
      onSaved(updated, { keepEditing: true, message: `Foto de capa de ${updated.name} atualizada.` })
    } catch (err) {
      setError(formatApiError(err))
    } finally {
      setPhotoBusy(false)
    }
  }

  async function handleDeletePhoto(photoId) {
    setPhotoBusy(true)
    setError('')
    setPhotoMessage('')
    try {
      const updated = await api.deletePetPhoto(pet.id, photoId)
      setPet(updated)
      setPhotoMessage('Fotografia removida.')
      onSaved(updated, { keepEditing: true, message: `Fotografia removida de ${updated.name}.` })
    } catch (err) {
      setError(formatApiError(err))
    } finally {
      setPhotoBusy(false)
    }
  }

  return (
    <form className="pet-form" onSubmit={submit}>
      <div className="pet-form-heading">
        <div>
          <span className="eyebrow">
            {isEditing ? `EDITAR PERFIL · ${statusLabel[pet.status] || pet.status}` : 'NOVO PERFIL'}
          </span>
          <h3>{isEditing ? `Editar ${pet.name}` : 'Conte a história desse bichinho'}</h3>
          <p>
            {isEditing
              ? 'Atualize os dados, cuidados de saúde e fotografias do animal.'
              : 'Informações claras ajudam a encontrar uma família compatível. Você pode salvar como rascunho e concluir depois.'}
          </p>
        </div>
        <div className="pet-form-header-actions">
          <span className="pet-form-step">
            {isEditing ? statusLabel[pet.status] || 'Edição' : 'Cadastro inicial'}
          </span>
          <button type="button" className="text-button" onClick={onClose}>
            Fechar
          </button>
        </div>
      </div>

      <div className="pet-form-grid">
        <label className="field">
          Nome
          <input
            name="name"
            defaultValue={pet?.name || ''}
            required
            maxLength={80}
            placeholder="Ex.: Luna"
          />
        </label>
        <label className="field">
          Espécie
          <select name="species" defaultValue={pet?.species || 'dog'}>
            <option value="dog">Cachorro</option>
            <option value="cat">Gato</option>
            <option value="other">Outro</option>
          </select>
        </label>
        <label className="field">
          Raça
          <input
            name="breed"
            defaultValue={pet?.breed || ''}
            maxLength={100}
            placeholder="Ex.: Sem raça definida"
          />
        </label>
        <label className="field">
          Sexo
          <select name="sex" defaultValue={pet?.sex || 'female'}>
            <option value="female">Fêmea</option>
            <option value="male">Macho</option>
            <option value="unknown">Não identificado</option>
          </select>
        </label>
        <label className="field">
          Porte
          <select name="size" defaultValue={pet?.size || 'small'}>
            <option value="small">Pequeno</option>
            <option value="medium">Médio</option>
            <option value="large">Grande</option>
          </select>
        </label>
        <label className="field">
          Idade aproximada
          <input
            name="approximate_age"
            defaultValue={pet?.approximate_age || ''}
            maxLength={50}
            placeholder="Ex.: 2 anos"
          />
        </label>
        <label className="field">
          Data de nascimento (opcional)
          <input
            name="birth_date"
            type="date"
            defaultValue={pet?.birth_date ? String(pet.birth_date).slice(0, 10) : ''}
          />
        </label>
        <label className="field">
          Bairro (opcional)
          <input
            name="neighborhood"
            defaultValue={pet?.location?.neighborhood || ''}
            maxLength={100}
            placeholder="Ex.: Centro"
          />
        </label>
        <label className="field">
          Cidade
          <input
            name="city"
            defaultValue={pet?.location?.city ?? organization?.city ?? ''}
            maxLength={100}
            placeholder="Ex.: Porto Ferreira"
          />
        </label>
        <label className="field">
          Estado
          <input
            name="state"
            defaultValue={pet?.location?.state ?? organization?.state ?? ''}
            minLength={2}
            maxLength={2}
            placeholder="SP"
          />
        </label>
        <label className="field pet-form-wide">
          Personalidade
          <input
            name="personality"
            defaultValue={(pet?.personality || []).join(', ')}
            placeholder="carinhoso, brincalhão, sociável"
          />
        </label>

        <fieldset className="field pet-form-wide pet-health-fieldset">
          <legend>Cuidados de saúde</legend>
          <div className="pet-health-checks">
            <label className="checkbox-label">
              <input name="health_vaccinated" type="checkbox" defaultChecked={health.vaccinated} />
              Vacinado(a)
            </label>
            <label className="checkbox-label">
              <input name="health_neutered" type="checkbox" defaultChecked={health.neutered} />
              Castrado(a)
            </label>
            <label className="checkbox-label">
              <input name="health_dewormed" type="checkbox" defaultChecked={health.dewormed} />
              Vermifugado(a)
            </label>
            <label className="checkbox-label">
              <input name="health_special_needs" type="checkbox" defaultChecked={health.special_needs} />
              Necessidades especiais
            </label>
          </div>
          <label className="field">
            Observações de saúde (opcional)
            <input
              name="health_notes"
              defaultValue={health.notes}
              maxLength={1000}
              placeholder="Vacinas aplicadas, cuidados específicos ou acompanhamento veterinário"
            />
          </label>
        </fieldset>

        <label className="field pet-form-wide">
          Descrição
          <textarea
            name="description"
            defaultValue={pet?.description || ''}
            maxLength={4000}
            placeholder="Conte sobre a rotina, o temperamento e o lar ideal para esse animal (mínimo de 30 caracteres para publicar)."
          />
        </label>

        {!isEditing ? (
          <label className="field pet-form-wide file-field">
            <span>Foto principal</span>
            <input name="photo" type="file" accept="image/jpeg,image/png,image/webp" />
            <small>
              JPG, PNG ou WebP (máx. 5 MB). Opcional para salvar como rascunho; obrigatória antes de disponibilizar no catálogo.
            </small>
          </label>
        ) : (
          <div className="field pet-form-wide file-field pet-photo-manager">
            <div className="pet-photo-manager-header">
              <strong>Fotografias cadastradas ({photos.length}/10)</strong>
              <small>Defina a foto de capa ou adicione novas imagens (JPG, PNG ou WebP até 5 MB).</small>
            </div>

            {photos.length > 0 ? (
              <div className="pet-photo-list">
                {photos.map(photo => {
                  const isCover = pet.cover_photo === photo.url
                  return (
                    <div key={photo.id} className={`pet-photo-item ${isCover ? 'is-cover' : ''}`}>
                      <img src={photo.url} alt={`Foto de ${pet.name}`} />
                      <div className="pet-photo-item-actions">
                        {isCover ? (
                          <span className="photo-cover-badge">★ Foto principal</span>
                        ) : (
                          <button
                            type="button"
                            className="text-button"
                            disabled={photoBusy}
                            onClick={() => handleSetCover(photo.id)}
                          >
                            Definir como capa
                          </button>
                        )}
                        <button
                          type="button"
                          className="text-button text-button-danger"
                          disabled={photoBusy}
                          onClick={() => handleDeletePhoto(photo.id)}
                        >
                          Remover foto
                        </button>
                      </div>
                    </div>
                  )
                })}
              </div>
            ) : pet.cover_photo ? (
              <div className="pet-photo-list">
                <div className="pet-photo-item is-cover">
                  <img src={pet.cover_photo} alt={`Foto de capa de ${pet.name}`} />
                  <div className="pet-photo-item-actions">
                    <span className="photo-cover-badge">★ Foto principal</span>
                  </div>
                </div>
              </div>
            ) : (
              <p className="small muted">Nenhuma fotografia enviada ainda. Adicione uma foto para poder publicar.</p>
            )}

            <div className="pet-photo-upload-row">
              <label className="field">
                Adicionar nova foto
                <input
                  ref={newPhotoInputRef}
                  type="file"
                  accept="image/jpeg,image/png,image/webp"
                  disabled={photoBusy}
                />
              </label>
              <label className="checkbox-label">
                <input
                  type="checkbox"
                  checked={newPhotoCover}
                  onChange={event => setNewPhotoCover(event.target.checked)}
                  disabled={photoBusy}
                />
                Definir como foto principal
              </label>
              <button
                type="button"
                className="button button-outline"
                disabled={photoBusy}
                onClick={handleUploadExtraPhoto}
              >
                {photoBusy ? 'Enviando foto…' : 'Enviar foto'}
              </button>
            </div>
            {photoMessage && (
              <p className="feedback" role="status">
                {photoMessage}
              </p>
            )}
          </div>
        )}
      </div>

      <div className="pet-form-actions">
        <p>
          {isEditing
            ? pet.status === 'draft'
              ? 'Este animal está em rascunho privado. Salve o progresso ou disponibilize no catálogo.'
              : 'As alterações serão atualizadas imediatamente.'
            : 'O perfil será salvo como rascunho para sua revisão.'}
        </p>
        <div className="pet-form-buttons">
          {(!isEditing || pet.status === 'draft') && (
            <button
              type="submit"
              name="intent"
              value="publish"
              className="button button-outline"
              disabled={busy || photoBusy}
            >
              {busy ? 'Processando…' : 'Salvar e disponibilizar'}
            </button>
          )}
          <button
            type="submit"
            name="intent"
            value="save"
            className="button"
            disabled={busy || photoBusy}
          >
            {busy ? 'Salvando…' : isEditing ? 'Salvar alterações' : 'Salvar perfil'}
          </button>
        </div>
      </div>

      {error && (
        <div className="form-alert" role="alert">
          <span>!</span>
          <p>{error}</p>
        </div>
      )}
    </form>
  )
}
