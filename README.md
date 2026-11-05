# AdoptPlace

Plataforma sem fins lucrativos para conectar animais resgatados a novas famílias.
React 19 + Vite no frontend, Laravel existente no backend e MongoDB planejado.

## Executar o frontend

Recomendado Node.js 24. No PowerShell:

```powershell
cd frontend
npm ci
Copy-Item .env.example .env
npm run dev
```

Não sobrescreva um .env existente. Abra a URL exibida pelo Vite.
Build de produção: `npm run build`. Verificação estática: `npm run lint`.

## Páginas

- `/`: catálogo com filtros por espécie, porte e cidade; favoritos persistidos no navegador.
- `/pets/pipoca`, `/pets/luna`, `/pets/thor`: perfis individuais e compartilhamento.
- `/login` e `/cadastro`: formulários com validação e opção de mostrar senha.
- `/dashboard`: demonstração de gestão, busca por nome e alteração local de status.

Os caminhos antigos .html redirecionam para as novas páginas. Na hospedagem do
frontend, configurar fallback para index.html nas rotas que não sejam arquivos.

## Organização

- `frontend/src/pages`: páginas React.
- `frontend/src/components`: marca, cabeçalho, rodapé, cards e favoritos.
- `frontend/src/context`: estado compartilhado dos favoritos.
- `frontend/src/data`: animais demonstrativos.
- `frontend/src/services/api.js`: cliente HTTP preparado para erros Laravel.
- `frontend/src/assets/adoptplace-logo.png`: logo original, movido da raiz.
- `docs/backend.md`: preparação e contrato proposto para o backend.

## Design system

O redesign usa `design-system.html` como fonte visual. A implementação React adota
a paleta Cream/Ink/Bloom, Manrope para a interface, Fraunces para ênfase editorial,
JetBrains Mono para metadados, raios de 14/20/28 px e estados de foco, hover e
movimento reduzido definidos no sistema. A aplicação está em
`frontend/src/styles-v2.css`; o CSS anterior foi mantido apenas como referência.

Os HTMLs originais foram preservados na raiz como referência. A interface não usa
Tailwind nem Font Awesome por CDN. Fotos Unsplash e fontes Google precisam de
conexão; fontes de sistema servem como alternativa.

## Integração

O catálogo e a ONG são fictícios, identificados como demonstração. Alterações do
painel não persistem e não afetam o catálogo público. Contatos reais aguardam a API.

Login/cadastro validam campos sem criar contas ou sessões e sem guardar senhas.
`VITE_AUTH_ENABLED=false` impede envio enquanto a API não estiver implementada.
A flag só deve ser habilitada após concluir autenticação, CSRF e proteção do painel.

O Vite encaminha `/api` e `/sanctum` para `http://127.0.0.1:8000`.
`VITE_API_URL` permite configurar outra base. Laravel tem `GET /api/health` e CORS
limitado a FRONTEND_URL. MongoDB ainda não está instalado/configurado: as variáveis
reservadas e os próximos passos estão em [docs/backend.md](docs/backend.md).

PHP e Composer não foram encontrados neste terminal; o backend não pôde ser validado.
