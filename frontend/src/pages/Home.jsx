import { useEffect, useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import { pets } from "../data/pets";
import PetCard from "../components/PetCard";
import { api } from "../services/api";

const initialFilters = { species: "", size: "", city: "" };

export default function Home() {
  const navigate = useNavigate();
  const [featuredPets, setFeaturedPets] = useState([]);
  const [loading, setLoading] = useState(true);
  const [catalogMessage, setCatalogMessage] = useState("");
  const [draft, setDraft] = useState(initialFilters);

  useEffect(() => {
    let active = true;
    api.pets({ featured: 1, per_page: 6 })
      .then((response) => {
        if (active) setFeaturedPets(response.data || []);
      })
      .catch((error) => {
        if (active) setCatalogMessage(error.message);
      })
      .finally(() => {
        if (active) setLoading(false);
      });
    return () => {
      active = false;
    };
  }, []);

  const update = (event) =>
    setDraft({ ...draft, [event.target.name]: event.target.value });

  function handleSearch(event) {
    event.preventDefault();
    const params = new URLSearchParams();
    if (draft.species) params.set("species", draft.species);
    if (draft.size) params.set("size", draft.size);
    if (draft.city.trim()) params.set("city", draft.city.trim());
    const query = params.toString();
    navigate(query ? `/animais?${query}` : "/animais");
  }

  return (
    <main id="main">
      <section className="hero">
        <div className="bloom-orb orb-one" aria-hidden="true" />
        <div className="bloom-orb orb-two" aria-hidden="true" />
        <div className="container hero-grid">
          <div className="hero-copy">
            <span className="eyebrow">
              <i /> NOVOS COMEÇOS, TODOS OS DIAS
            </span>
            <h1>
              Um encontro.
              <br />
              <span className="serif-em">Duas vidas</span>
              <br />
              transformadas.
            </h1>
            <p>
              Conheça animais que esperam por uma família e encontre uma conexão
              que pode mudar a história de vocês.
            </p>
            <div className="hero-actions">
              <Link to="/animais" className="button">
                Encontre seu amigo <span aria-hidden="true">↗</span>
              </Link>
              <a
                href="#como-funciona"
                className="button button-outline button-pill"
              >
                Como funciona
              </a>
            </div>
            <div className="hero-note">
              <span className="mini-paw" aria-hidden="true">
                ♡
              </span>
              <span>
                Uma plataforma gratuita para adoção responsável.{" "}
                <br />
                <strong>Feita para aproximar, informar e acolher.</strong>
              </span>
            </div>
          </div>
          <div className="hero-art">
            <div className="hero-image-frame">
              <img
                src={pets[0].image}
                alt="Cachorrinho curioso aproveitando o ar livre"
                fetchPriority="high"
                onError={(event) => {
                  event.currentTarget.onerror = null;
                  event.currentTarget.src = "/demo-pets/pipoca.png";
                }}
              />
              <span className="image-index">AMIGO · 01</span>
            </div>
            <span className="art-doodle" aria-hidden="true">
              ♡
            </span>
            <div className="floating-note">
              <span>♥</span>
              <div>
                O amor tem quatro patas.
                <small>E pode estar esperando por você.</small>
              </div>
            </div>
            <span className="vertical-note">TODO ANIMAL MERECE UM LAR.</span>
          </div>
        </div>
      </section>
      <section className="container search-section" aria-label="Buscar animais">
        <form className="search-bar" onSubmit={handleSearch}>
          <label>
            <span>QUEM VOCÊ PROCURA?</span>
            <select name="species" value={draft.species} onChange={update}>
              <option value="">Todos os bichinhos</option>
              <option value="dog">Cachorro</option>
              <option value="cat">Gato</option>
              <option value="other">Outro</option>
            </select>
          </label>
          <label>
            <span>PORTE</span>
            <select name="size" value={draft.size} onChange={update}>
              <option value="">Todos os tamanhos</option>
              <option value="small">Pequeno</option>
              <option value="medium">Médio</option>
              <option value="large">Grande</option>
            </select>
          </label>
          <label>
            <span>LOCALIZAÇÃO</span>
            <input
              name="city"
              value={draft.city}
              onChange={update}
              placeholder="Digite sua cidade"
            />
          </label>
          <button className="button" type="submit">
            Buscar amigos ⌕
          </button>
        </form>
      </section>
      <section id="catalogo" className="container catalog">
        <div className="section-heading">
          <div>
            <span className="eyebrow">
              <i /> ANIMAIS EM DESTAQUE
            </span>
            <h2>
              Pequenas patas.
              <br />
              <span className="serif-em">Grandes amores.</span>
            </h2>
            <p className="muted">
              Cada um tem uma história. Que tal fazer parte do próximo capítulo?
            </p>
          </div>
          <Link to="/animais" className="button button-outline button-pill">
            Ver todos os animais <span aria-hidden="true">↗</span>
          </Link>
        </div>
        <div className="catalog-meta">
          <span aria-live="polite">
            {loading
              ? "Carregando destaques…"
              : `${featuredPets.length} ${featuredPets.length === 1 ? "animal em destaque" : "animais em destaque"}`}
          </span>
          <span className="demo-label">Animais cadastrados por organizações parceiras</span>
        </div>
        {loading ? (
          <div className="empty" role="status">
            <p>Buscando bichinhos que esperam por um lar…</p>
          </div>
        ) : featuredPets.length ? (
          <>
            <div className="pet-grid">
              {featuredPets.map((pet) => (
                <PetCard key={pet.id} pet={pet} />
              ))}
            </div>
            <div className="catalog-cta">
              <Link to="/animais" className="button">
                Ver todos os animais <span aria-hidden="true">↗</span>
              </Link>
            </div>
          </>
        ) : (
          <div className="empty">
            <span className="empty-heart">♡</span>
            <h3>Novos amigos chegarão em breve.</h3>
            <p>
              Explore o catálogo completo para consultar todas as opções disponíveis.
            </p>
            <Link to="/animais" className="button">
              Ver todos os animais
            </Link>
          </div>
        )}
        {catalogMessage && <p className="feedback" role="status">{catalogMessage}</p>}
      </section>
      <section id="como-funciona" className="how-section">
        <div className="container">
          <div className="center">
            <span className="eyebrow">
              <i /> UM NOVO COMEÇO, SEM COMPLICAÇÃO
            </span>
            <h2>
              Três passos para uma
              <br />
              <span className="serif-em">amizade para a vida.</span>
            </h2>
            <p className="muted">A gente aproxima. O cuidado conduz o resto.</p>
          </div>
          <div className="steps">
            {[
              [
                "01",
                "Encontre uma conexão",
                "Explore os perfis, conheça as histórias e escolha um bichinho que combine com sua rotina.",
              ],
              [
                "02",
                "Converse com a ONG",
                "Tire suas dúvidas sobre saúde, comportamento e os cuidados que ele precisa.",
              ],
              [
                "03",
                "Prepare um lar com amor",
                "Conheça o pet, siga as orientações da ONG e dê início a uma amizade para a vida.",
              ],
            ].map(([number, title, copy]) => (
              <article key={number}>
                <span className="step-number">{number}</span>
                <div className="step-icon" aria-hidden="true">
                  {number === "01" ? "⌕" : number === "02" ? "♡" : "⌂"}
                </div>
                <h3>{title}</h3>
                <p>{copy}</p>
              </article>
            ))}
          </div>
        </div>
      </section>
      <section className="container">
        <div className="ngo-banner">
          <div className="banner-mark" aria-hidden="true">
            ♡
          </div>
          <div>
            <span className="eyebrow">
              <i /> JUNTOS, A GENTE VAI MAIS LONGE
            </span>
            <h2>
              Você cuida.{" "}
              <br />A gente ajuda a <span className="serif-em">conectar.</span>
            </h2>
            <p>
              Um espaço gratuito para ONGs e protetores encontrarem{" "}
              <br />
              famílias para os animais que acolhem.
            </p>
          </div>
          <Link to="/cadastro" className="button button-light">
            Cadastrar minha ONG <span aria-hidden="true">↗</span>
          </Link>
        </div>
      </section>
    </main>
  );
}
