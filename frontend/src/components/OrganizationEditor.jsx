import { useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../services/api'

export default function OrganizationEditor({ organization }) {
  const [profile, setProfile] = useState(organization)
  const [message, setMessage] = useState('')
  const [busy, setBusy] = useState(false)
  async function save(event) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    setBusy(true)
    setMessage('')
    try {
      setProfile(await api.updateOrganization({ ...Object.fromEntries(form), contact_publicly_visible: form.has('contact_publicly_visible') }))
      setMessage('Perfil atualizado.')
    } catch (error) { setMessage(Object.values(error.errors || {}).flat().join(' ') || error.message) }
    finally { setBusy(false) }
  }
  if (!profile) return null
  return <details className="panel"><summary>Editar apresentação e contato público</summary>
    <form onSubmit={save}><fieldset className="register-fields" disabled={busy}>
      <label className="field">Nome público<input name="name" defaultValue={profile.name} required maxLength={120} /></label>
      <label className="field">Apresentação<textarea name="description" defaultValue={profile.description || ''} maxLength={2000} /></label>
      <div className="register-grid"><label className="field">Cidade<input name="city" defaultValue={profile.city} required maxLength={100} /></label><label className="field">Estado<input name="state" defaultValue={profile.state} required minLength={2} maxLength={2} /></label></div>
      <label className="field">E-mail de contato<input name="email" type="email" defaultValue={profile.email || ''} required /></label>
      <label className="field">Telefone público<input name="phone" defaultValue={profile.phone || ''} maxLength={30} /></label>
      <label className="checkbox-label"><input name="contact_publicly_visible" type="checkbox" defaultChecked={profile.contact_publicly_visible} /> Exibir meus contatos no perfil público e nos anúncios</label>
      <button className="button" disabled={busy}>{busy ? 'Salvando…' : 'Salvar perfil'}</button>
    </fieldset><p role="status">{message}</p></form>
    {profile.verification_status === 'approved' && <Link className="text-link" to={`/organizacoes/${profile.slug}`}>Ver perfil público ↗</Link>}
  </details>
}
