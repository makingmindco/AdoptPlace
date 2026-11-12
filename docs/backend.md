# Backend do AdoptPlace

## Arquitetura

Laravel 13 expõe uma API versionada em `/api/v1`. O catálogo e o envio de interesse são públicos. Sessões de organizações usam cookies HttpOnly do Laravel Sanctum; políticas impedem uma organização de acessar animais ou pedidos de outra. MongoDB armazena usuários, organizações, animais, pedidos, tokens e auditoria.

Dados pessoais do adotante são criptografados no documento de pedido. O histórico de mudanças e os logs de auditoria apoiam moderação e rastreabilidade.

## Principais endpoints

| Método | Endpoint | Acesso |
| --- | --- | --- |
| GET | `/api/v1/pets` | público, filtros e paginação |
| GET | `/api/v1/pets/{slug}` | público |
| POST | `/api/v1/pets/{id}/adoption-requests` | público, limitado por IP |
| POST | `/api/v1/auth/register` | público |
| POST | `/api/v1/auth/login` | público, limitado |
| GET | `/api/v1/auth/me` | autenticado |
| GET/POST | `/api/v1/organization/pets` | organização aprovada |
| PATCH/DELETE | `/api/v1/organization/pets/{id}` | organização proprietária |
| GET/PATCH | `/api/v1/organization/adoption-requests/{id?}` | organização proprietária |
| GET/PATCH | `/api/v1/admin/*` | administrador |

Use `php artisan route:list --path=api/v1` para consultar a relação completa.

## Configuração

Copie `.env.example`, gere `APP_KEY` e defina ao menos:

```dotenv
APP_URL=http://localhost:8000
FRONTEND_URL=http://localhost:5173
DB_URI=mongodb://127.0.0.1:27017
DB_DATABASE=adoptplace
SANCTUM_STATEFUL_DOMAINS=localhost:5173
```

Execute `php artisan mongodb:ensure-indexes` em cada novo banco. `php artisan db:seed` é apenas para desenvolvimento e cria a conta `ong@adoptplace.local` com senha `Adoptplace123`; nunca execute esse seed em produção.

Para imagens em produção, configure `PET_MEDIA_DISK=s3` e as variáveis AWS/S3. Para notificações assíncronas, troque `QUEUE_CONNECTION=sync` por um driver de fila e mantenha um worker ativo.

## Produção

- Sirva o Laravel por HTTPS e aponte o document root para `backend/public`.
- Configure `APP_ENV=production`, `APP_DEBUG=false`, MongoDB Atlas com usuário restrito e backup.
- Se frontend e API estiverem em subdomínios, use o mesmo domínio raiz, ajuste `SESSION_DOMAIN`, `SANCTUM_STATEFUL_DOMAINS`, `FRONTEND_URL` e `SESSION_SECURE_COOKIE=true`.
- Rode no deploy: `composer install --no-dev --optimize-autoloader`, `php artisan optimize`, `php artisan mongodb:ensure-indexes` e `php artisan storage:link` quando usar disco público.
- Configure o frontend com `VITE_API_URL=https://api.seudominio.org/api/v1` antes de `npm run build` e fallback SPA para `index.html`.
- Configure SMTP, monitoramento, rotação de logs, política de retenção/eliminação de dados e termos de privacidade antes de aceitar dados reais.

## Próximas evoluções

Recuperação de senha, interface de reenvio da confirmação de e-mail, edição completa/fotos no painel, busca geográfica, notificações ao adotante e testes de integração com um MongoDB isolado são extensões previstas. A base atual já separa esses domínios para permitir evolução incremental.

## Cadastro por perfil — primeira etapa do marketplace

O login fica em `/login` e o cadastro em `/cadastro`. O cadastro tem três passos: tipo de perfil, credenciais e localização/apresentação. O indicador de senha exige pelo menos 10 caracteres, maiúscula, minúscula, número e símbolo. A política vale para novos cadastros; senhas de contas existentes continuam funcionando no login.

| `account_type` | Papel salvo no usuário | Organização | Destino após entrar |
| --- | --- | --- | --- |
| `adopter` | `adopter` | nenhuma | catálogo |
| `ngo` | `organization_owner` | tipo `ngo`, pendente | painel com aviso de análise |
| `independent_protector` | `organization_owner` | tipo `independent_protector`, pendente | painel com aviso de análise |

Os papéis anteriores de administrador, proprietário e membro de organização foram preservados. Adotantes não podem acessar as APIs de gestão de animais nem editar organizações. A API define o papel com base no tipo de conta validado; parâmetros como `role` e `organization_id` enviados pelo cliente não são usados.

### Contrato de cadastro

`POST /api/v1/auth/register`, com sessão/CSRF do Sanctum:

```json
{
  "account_type": "adopter",
  "name": "Nome completo",
  "email": "pessoa@example.org",
  "phone": "11999999999",
  "password": "UmaSenha#Longa123",
  "password_confirmation": "UmaSenha#Longa123",
  "terms": true,
  "address": { "cep": "01001000", "city": "São Paulo", "state": "SP" }
}
```

ONGs e protetores também informam `provider_name`, `description` (20–2000 caracteres), `responsible_adoption: true` e `address.street`, `address.number`, `address.neighborhood`; `address.complement` é opcional. ONGs informam `has_cnpj` (booleano) e, quando verdadeiro, `cnpj`.

O CNPJ é opcional para permitir que iniciativas ainda sem formalização solicitem análise. Quando informado, formato, dígitos verificadores e duplicidade são validados. São aceitos CNPJs numéricos e alfanuméricos, conforme o [manual de cálculo da Receita Federal](https://www.gov.br/receitafederal/pt-br/centrais-de-conteudo/publicacoes/documentos-tecnicos/cnpj/manual-dv-cnpj.pdf). Essa validação não consulta situação cadastral nem comprova titularidade. A aprovação administrativa continua obrigatória para divulgar animais. Tipo e documento não podem ser alterados pelo endpoint comum de edição de organização.

O consentimento de cadastro fica registrado em `terms_accepted_at`. Endereços novos são criptografados com a `APP_KEY`: `users.address` e `organizations.private_address`. O campo antigo `organizations.address` foi preservado para compatibilidade. No cadastro de adotante, apenas CEP, cidade e estado são salvos. Endereço completo e telefone do usuário não aparecem na serialização pública; cidade e estado da organização continuam públicos. Preserve a `APP_KEY` existente para conseguir ler os dados criptografados.

### CEP e confirmação de e-mail

- `GET /api/v1/address/cep/{cep}` consulta o [ViaCEP](https://viacep.com.br/). Aceita oito dígitos, usa limite de 30 consultas/minuto por cliente e cache de 24 horas. Retorna `cep`, `street`, `neighborhood`, `city`, `state`.
- Respostas: `422` para formato inválido, `404` para CEP inexistente, `503` para falha do serviço. O formulário permite preenchimento manual e cancela resultados antigos quando o CEP muda. Número e complemento são preenchidos pelo usuário.
- O cadastro envia um link de confirmação para `/email/verify/{id}/{hash}` com assinatura e validade do Laravel. Após confirmar, redireciona para `/login?verified=1`.
- Configure `APP_URL` com a URL pública da API, `FRONTEND_URL` com a URL do React e o transporte `MAIL_*` para entrega real. Uma falha no envio não desfaz uma conta já criada. O endpoint autenticado `POST /api/v1/auth/email/verification-notification` permite reenviar a confirmação, com limite de seis tentativas/minuto. A interface de reenvio e a obrigatoriedade de confirmação antes de publicar ficam para a próxima etapa.

Não é necessário reinstalar o MongoDB nem recriar os dados para esses campos adicionais. Não execute novamente o seed para atualizar contas existentes.

### Validação desta etapa

```powershell
cd backend
php artisan test
cd ../frontend
npm ci
npm run lint
npm test
npm run build
npm run test:e2e
```

Os testes PHP usam mocks para consulta de unicidade e ViaCEP; verificam regras de cadastro, CNPJ, permissões e criptografia sem acessar o Atlas. Os testes de navegador usam Playwright com Edge instalado (`channel: msedge`), iniciam Vite na porta 4174 e simulam a API. Cobrem carregamento do login, três perfis, validações, falha de CEP, restauração de sessão na interface, bloqueio do painel de adotante e layout móvel. Evidências de falhas ficam em `frontend/test-results`, fora do Git.

Esses testes não substituem o teste integrado de gravação no MongoDB e entrega de e-mail em homologação. Antes de disponibilizar o fluxo, testar os três cadastros em um banco isolado, a aprovação administrativa e o link de confirmação por um transporte de e-mail configurado.

### Etapas seguintes

1. Perfil público de ONG/protetor com animais disponíveis, edição do perfil e controles de visibilidade.
2. Favoritos vinculados à conta e histórico de solicitações de adoção. Nesta etapa, os favoritos continuam no navegador e o catálogo continua público.
3. Conclusão da adoção vinculada ao adotante e avaliações apenas de quem concluiu uma adoção, com moderação e prevenção de duplicidade.
4. Verificação de prestadores, recuperação de senha, consentimentos/políticas completos e validação integrada para publicação.

Cada etapa deve ter seu próprio commit Conventional Commits após a validação correspondente.
