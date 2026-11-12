import { useEffect, useState } from 'react'
import { api } from '../services/api'
import { Pagination } from '../pages/Account'

export default function Reviews({ slug }) {
  const [result, setResult] = useState(null)
  const [page, setPage] = useState(1)
  const [error, setError] = useState('')
  useEffect(() => {
    let active = true
    api.reviews(slug, page).then(data => { if (active) setResult(data) }).catch(() => { if (active) setError('Não foi possível carregar as avaliações.') })
    return () => { active = false }
  }, [slug, page])
  return <section className="panel org-reviews-panel"><h2>Experiências de quem adotou</h2><p>Avaliações de adoções concluídas e confirmadas.</p>{error && <p role="alert">{error}</p>}
    {!result && !error && <p className="muted" role="status">Carregando avaliações…</p>}
    {result && <><p>{result.count ? `${result.average} de 5 · ${result.count} avaliações` : 'Ainda não há avaliações publicadas.'}</p>{result.reviews.data.map(item => <article className="adoption-item" key={item.id}><strong>{item.rating} de 5 · {item.author}</strong><p style={{ whiteSpace: 'pre-wrap' }}>{item.comment}</p></article>)}<Pagination page={page} meta={result.reviews} onChange={setPage} /></>}
  </section>
}
