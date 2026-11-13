# AdoptPlace

Plataforma sem fins lucrativos para adoção responsável de animais, desenvolvida como projeto acadêmico e case de portfólio. Aproxima adotantes, ONGs e protetores individuais, sem comercialização de animais.

## Tecnologias e arquitetura

- React 19, Vite 8 e React Router no frontend SPA.
- Laravel 13 com API REST `/api/v1`, validação e policies de autorização.
- Laravel Sanctum com sessão em cookies HttpOnly e proteção CSRF.
- MongoDB com `mongodb/laravel-mongodb`; Atlas ou replica set para transações.
- Fotos em disco público local ou armazenamento compatível com S3.
- PHPUnit, Playwright, teste Node de senha, ESLint e build Vite.

O React acessa a API por `frontend/src/services/api.js`. O Laravel aplica as regras de acesso e persiste documentos. Em desenvolvimento, Vite encaminha `/api` e `/sanctum` para Laravel na porta 8000.

## Perfis e funcionalidades

| Perfil | Principais ações |
| --- | --- |
| Visitante | Home, catálogo com filtros e paginação, animais e perfis públicos |
| Adotante | Favoritos, interesses, acompanhamento, desistência, confirmação de recebimento e avaliação |
| ONG | Gestão de perfil, animais, fotos e interessados após aprovação administrativa |
| Protetor individual | Gestão equivalente à ONG, com cadastro próprio para pessoa independente |
| Administrador | Aprovação/suspensão de organizações, moderação de animais e avaliações e consulta de auditoria pela API |

ONG e protetor são tipos de organização; seus responsáveis usam `organization_owner`. Publicar exige organização aprovada e e-mail confirmado. O cadastro inclui consulta de CEP com preenchimento manual alternativo e CNPJ opcional para ONGs, sem substituir a aprovação administrativa.

A adoção inclui análise, reserva, desistência, confirmação pelo adotante e avaliação moderada. Há adoção externa e republicação com preservação do histórico. O limite é uma candidatura por usuário e animal, inclusive após republicação. Veja [regras e contratos](docs/backend.md).

## Pré-requisitos

- Node.js 24+ e npm.
- PHP 8.3+ com extensão `mongodb` e demais extensões exigidas pelo Composer; Composer instalado.
- MongoDB 7+ em replica set ou Atlas. Um servidor standalone não atende às transações de adoção.
- Microsoft Edge para a configuração atual do Playwright (`channel: msedge`).

Use os arquivos de lock com `composer install` e `npm ci`. Não ignore requisitos de plataforma. No Windows, `where.exe php`, `php --ini` e `php --ri mongodb` identificam executável, configuração e extensão.

## Executar localmente

Comandos PowerShell, a partir da raiz. São instruções para preparar um ambiente próprio; não foram executados na Etapa 9A.

### Backend e Atlas

```powershell
cd backend
composer install
if (!(Test-Path .env)) { Copy-Item .env.example .env }
```

No Atlas, crie um cluster, um usuário de banco e autorize o IP da máquina em Network Access. Configure a URI e um banco de desenvolvimento no arquivo local. Com Atlas, não é necessário instalar o servidor MongoDB na máquina; a extensão PHP continua necessária.

Exemplo ilustrativo, sem credenciais reais:

```dotenv
DB_CONNECTION=mongodb
DB_URI="mongodb+srv://<usuario>:<senha-codificada>@<cluster>/?retryWrites=true&w=majority"
DB_DATABASE=adoptplace_dev
```

Substitua os marcadores e codifique caracteres especiais do usuário/senha para URI. Não versione `.env`. Preserve a `APP_KEY` de ambientes existentes: dados pessoais são criptografados com ela.

```powershell
php artisan key:generate # somente em instalação nova, se APP_KEY estiver vazia
php artisan mongodb:ensure-indexes # apenas no banco de desenvolvimento escolhido
php artisan storage:link
php artisan serve --host=127.0.0.1 --port=8000
```

O comando de índices modifica o banco selecionado e pode falhar diante de duplicidades incompatíveis com índices únicos. Ele não migra documentos. Não execute seeds para atualizar ambientes existentes; criar dados demonstrativos não é necessário para iniciar o sistema.

### Frontend

Em outro terminal, a partir da raiz:

```powershell
cd frontend
npm ci
if (!(Test-Path .env)) { Copy-Item .env.example .env }
npm run dev
```

Acesse `http://localhost:5173` e mantenha o mesmo hostname durante a sessão. O backend deve estar na porta 8000, conforme o proxy do Vite.

### Variáveis e Sanctum

Os exemplos estão em `backend/.env.example` e `frontend/.env.example`. Nunca coloque segredos em `VITE_*`: essas variáveis são incorporadas ao frontend.

| Arquivo / variáveis | Uso |
| --- | --- |
| Backend: `APP_NAME`, `APP_ENV`, `APP_DEBUG`, `APP_KEY` | Identidade, ambiente, diagnóstico e criptografia |
| Backend: `APP_URL`, `FRONTEND_URL` | URL da API e origem do React; links e CORS |
| Backend: `DB_CONNECTION`, `DB_URI`, `DB_DATABASE` | Conexão e banco MongoDB |
| Backend: `SANCTUM_STATEFUL_DOMAINS` | Hosts com porta e sem protocolo; localmente `localhost:5173,127.0.0.1:5173` |
| Backend: `SESSION_DRIVER`, `SESSION_LIFETIME`, `SESSION_DOMAIN`, `SESSION_SECURE_COOKIE` | Localmente: driver `file`, domínio vazio e cookie seguro `false` para HTTP |
| Backend: `CACHE_STORE`, `QUEUE_CONNECTION` | Exemplos locais: cache `file` e fila `sync` |
| Backend: `PET_MEDIA_DISK`, `FILESYSTEM_DISK` | Fotos usam `PET_MEDIA_DISK=public` por padrão |
| Backend: `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | Confirmação de e-mail, recuperação de senha e notificações |
| Backend: `AWS_*` | Conforme o provedor, somente ao usar `PET_MEDIA_DISK=s3` |
| Frontend: `VITE_API_URL` | `/api/v1` no desenvolvimento com proxy |

O exemplo SMTP aponta para `mailpit:1025`: o projeto não inicia esse serviço e o hostname pode não resolver na máquina. Configure SMTP ou use `MAIL_MAILER=log` apenas em desenvolvimento; mensagens ficam nos logs, sem entrega à caixa de entrada. Redis, Memcached e Pusher presentes no exemplo não são necessários para a configuração local descrita.

O cliente inicializa CSRF em `/sanctum/csrf-cookie`. Para hospedagem com subdomínios, frontend e API devem compartilhar o domínio raiz; ajuste `SESSION_DOMAIN`, hosts stateful, `FRONTEND_URL` e HTTPS com `SESSION_SECURE_COOKIE=true`. Veja [implantação](docs/backend.md#produção).

## Uploads

A aplicação aceita imagens de até **5 MB por arquivo**. O PHP pode rejeitar o envio antes do Laravel se `upload_max_filesize` ou `post_max_size` forem menores. Exemplo local: `upload_max_filesize=5M` e `post_max_size=8M`, reservando espaço para o formulário multipart. Confira o arquivo carregado com `php --ini` e reinicie o processo PHP após ajustes. Servidores e proxies também podem impor limites. Nenhuma configuração PHP foi alterada nesta etapa.

## Comandos de verificação

A partir de `frontend`:

```powershell
npm run lint
npm test
npm run build
npm run test:e2e
```

Playwright inicia Vite em `127.0.0.1:4174`, usa Edge e **simula a API**. Não comprova a integração real com Laravel/MongoDB.

A partir de `backend`:

```powershell
composer test
```

Sem `RUN_MONGODB_INTEGRATION=1`, os testes MongoDB são ignorados. Desde a 9D.5A, habilitar essa variável não basta: são obrigatórias URI exclusiva, nome temporário e confirmações descritos em [homologação MongoDB](docs/mongodb-homologation.md). Não há fallback para a URI da aplicação. Não use credenciais ou infraestrutura do banco principal nessa execução.

```powershell
$env:RUN_MONGODB_INTEGRATION='1'
try { php artisan test } finally { Remove-Item Env:RUN_MONGODB_INTEGRATION }
```

O usuário de teste precisa poder criar índices, gravar e remover esses bancos temporários. Notificações são simuladas. Nunca substitua o nome temporário pelo banco da aplicação.

### Integração contínua (Etapa 9C.1)

`.github/workflows/ci.yml` executa dois jobs independentes em pushes para `main` e pull requests destinados a `main`:

- **Frontend:** Node 24, `npm ci`, lint, teste Node do indicador de senha, build e Playwright com API simulada. O runner instala Edge, conforme a configuração existente; falhas de navegador disponibilizam os arquivos de `test-results` por sete dias.
- **Backend:** PHP 8.3, extensão MongoDB 2.5.0, Composer 2, instalação pelo lockfile, verificação dos requisitos reais da plataforma e `composer test`. Cache, sessão e e-mail usam memória; a URI MongoDB aponta exclusivamente para localhost, sem serviço de banco. `RUN_MONGODB_INTEGRATION=0` mantém os testes Atlas opt-in ignorados explicitamente, sem excluir os demais testes.

O workflow usa caches de downloads npm/Composer, não requer secrets e não executa deploy, migrations, seeders ou resets. Falhas nos comandos reprovam o respectivo job. Os testes reais de persistência/transações e os cenários completos React + Laravel + MongoDB ficam fora deste CI; exigem ambiente isolado e autorização própria. Para impedir também o merge de um PR com falhas, configure os dois jobs como verificações obrigatórias nas regras de proteção de `main` no GitHub.

### Resultados históricos

O Antigravity **reportou** 34 testes backend aprovados (288 asserções), 19 testes Playwright aprovados, 22 cenários reais React + Laravel + MongoDB aprovados e lint/build sem erros. **Nenhuma dessas verificações foi executada na Etapa 9A.** Não foram localizadas evidências versionadas dos 22 cenários reais. Os números são relatos históricos, não certificação do estado atual. Veja [testes integrados](docs/backend.md#testes-integrados).

## Estrutura

- `frontend/src/pages`: páginas públicas, autenticação, conta e painéis.
- `frontend/src/components` e `context`: componentes, sessão e favoritos.
- `frontend/src/services`: API e CEP.
- `backend/routes/api.php`: rotas públicas, privadas e administrativas.
- `backend/app/Http`: controllers, requests, resources e middleware.
- `backend/app/Models`, `Casts`, `Policies`: documentos, compatibilidade BSON/JSON e autorização.
- `backend/tests` e `frontend/tests`: testes automatizados versionados.
- `docs/backend.md`: contratos, persistência, regras e implantação.

A Etapa 9A consolida documentação. Demonstração, novas execuções de validação e demais etapas dependem de autorização posterior.
