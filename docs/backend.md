# Backend do AdoptPlace

## Arquitetura

Laravel 13 expõe uma API versionada em `/api/v1`. O catálogo é público; o envio de interesse exige autenticação para permitir acompanhamento pela conta. Sessões usam cookies HttpOnly do Laravel Sanctum; políticas impedem uma organização de acessar animais ou pedidos de outra. MongoDB armazena usuários, organizações, animais, pedidos, avaliações embutidas nos pedidos, tokens e auditoria.

Dados pessoais do adotante são criptografados no documento de pedido. O histórico de mudanças e os logs de auditoria apoiam moderação e rastreabilidade.

## Principais endpoints

| Método | Endpoint | Acesso |
| --- | --- | --- |
| GET | `/api/v1/pets` | público, filtros e paginação |
| GET | `/api/v1/pets/{slug}` | público |
| POST | `/api/v1/pets/{id}/adoption-requests` | autenticado, limitado |
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

Busca geográfica, notificações adicionais ao adotante e edição completa de fotos no painel são melhorias futuras. Os fluxos de conta, recuperação, confirmação de e-mail, favoritos, avaliações e testes MongoDB estão descritos abaixo.

## Cadastro por perfil — primeira etapa do marketplace

O login fica em `/login` e o cadastro em `/cadastro`. O cadastro tem três passos: tipo de perfil, credenciais e localização/apresentação. O indicador de senha exige pelo menos 8 caracteres, maiúscula, minúscula, número e símbolo. A política vale para novos cadastros; senhas de contas existentes continuam funcionando no login.

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
- Configure `APP_URL` com a URL pública da API, `FRONTEND_URL` com a URL do React e o transporte `MAIL_*` para entrega real. Uma falha no envio não desfaz uma conta já criada. O endpoint autenticado `POST /api/v1/auth/email/verification-notification` permite reenviar a confirmação, com limite de seis tentativas/minuto. A interface fica em Minha conta. Publicar animais exige e-mail confirmado.

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

## Marketplace: fluxos implementados

### Páginas

| Página | Uso |
| --- | --- |
| `/organizacoes/{slug}` | Perfil aprovado, animais disponíveis e avaliações publicadas |
| `/dashboard` | Edição de perfil, contatos públicos, animais e andamento das solicitações |
| `/minha-conta` | Favoritos da conta, solicitações, confirmação de adoção, avaliação e reenvio de confirmação de e-mail |
| `/administracao` | Aprovação/suspensão de organizações e moderação de avaliações; exclusiva de administradores |
| `/recuperar-senha` | Solicitar link de recuperação |
| `/redefinir-senha?token=...&email=...` | Redefinir senha com token válido |
| `/privacidade` | Explicação dos usos de dados e das regras de participação |

O catálogo continua público. Visitantes continuam tendo favoritos locais; ao entrar, passam a ver os favoritos da conta, sem importar automaticamente os favoritos do navegador compartilhado. Favoritos são atualizados com operações atômicas de inclusão sem duplicidade e remoção. A lista mostra apenas animais disponíveis de organizações aprovadas.

### Adoção e avaliação

1. Um usuário autenticado envia interesse em um animal disponível (`published`) de uma organização aprovada. Não pode solicitar um animal da própria organização nem um animal reservado (`adoption_in_progress`) ou já adotado (`adopted`). `user_id` vem da sessão e nunca do formulário.
2. A organização consulta o interesse no painel e define `reviewing`, `contacted`, `approved` ou `rejected`. Anotações internas não são retornadas ao adotante.
3. Apenas uma solicitação pode ficar `approved` por animal por vez (garantido também pelo índice único parcial `adoption_requests_one_approved_per_pet`). Ao aprovar uma solicitação, o animal passa atomicamente para `adoption_in_progress` (reservado), impedindo novas solicitações enquanto reservado, mas preservando as demais solicitações pendentes (`new`, `reviewing`, `contacted`) para eventual reconsideração.
4. Se o adotante aprovado desistir (`withdrawn`) ou a organização rejeitar/retornar a solicitação aprovada, a reserva é liberada e o animal volta para `published` (desde que não esteja `adopted` ou `archived`).
5. Em Minha conta, somente o adotante titular de uma solicitação `approved` pode confirmar o recebimento (`confirm`). A confirmação atualiza atomicamente a solicitação para `completed`, o animal para `adopted` e encerra todas as demais solicitações abertas daquele animal como `rejected` com histórico e motivo registrado. O MongoDB precisa suportar transações, como o Atlas/replica set.
6. A marcação manual de animal como adotado (`POST /organization/pets/{pet}/mark-adopted`) é exclusiva para adoções externas (fora da plataforma): se existir uma solicitação `approved` ativa, a operação é bloqueada com `409 Conflict` orientando a organização a aguardar a confirmação do adotante. Quando realizada sem solicitação aprovada, marca o animal como `adopted` e encerra as solicitações abertas com histórico (`rejected`), sem nunca marcar uma solicitação como `completed` sem confirmação do adotante.
7. A republicação (`POST /organization/pets/{pet}/publish`) é bloqueada enquanto houver solicitação `approved` ativa. Quando um animal adotado é republicado (por exemplo, após devolução), o histórico de solicitações concluídas e avaliações anteriores é integralmente preservado. **Limitação conhecida nesta etapa:** como o índice único parcial `(user_id, pet_id)` é mantido em `adoption_requests`, um usuário que já enviou uma solicitação anterior para aquele mesmo animal (mesmo que encerrada como `withdrawn`, `rejected` ou `completed`) não pode enviar uma segunda candidatura para o mesmo `pet_id` após eventual republicação.
8. Apenas o titular de uma solicitação concluída (`completed`) pode avaliá-la: nota de 1 a 5 e comentário de 10 a 1500 caracteres. A avaliação fica embutida na solicitação como documento BSON e é gravada uma única vez com atualização condicional.
9. A avaliação nasce `pending`. O administrador publica (`published`) ou oculta (`hidden`). Somente avaliações publicadas de adoções concluídas entram na nota média e no perfil público. Nenhum dado de contato do adotante é publicado.

### Novos endpoints

| Método | Endpoint relativo a `/api/v1` | Acesso |
| --- | --- | --- |
| GET | `/account/favorites` e `/account/favorite-pets` | Conta autenticada |
| PUT/DELETE | `/account/favorites/{petId}` | Conta autenticada |
| GET | `/account/adoptions` | Apenas solicitações do usuário |
| PATCH | `/account/adoptions/{id}` | Titular; `action: withdraw` ou `confirm` |
| POST | `/account/adoptions/{id}/review` | Titular da adoção concluída |
| GET | `/organizations/{slug}/pets` e `/organizations/{slug}/reviews` | Público, organização aprovada |
| GET | `/admin/reviews?status=pending` | Administrador; também aceita `published` e `hidden` |
| PATCH | `/admin/reviews/{id}` | Administrador; `status: published` ou `hidden` |
| POST | `/auth/forgot-password` | Público, limitado |
| POST | `/auth/reset-password` | Público, token e senha confirmada |

### Recuperação e segurança de sessão

O password broker do Laravel usa tokens criptograficamente seguros armazenados como hash no cache, com expiração de 60 minutos e intervalo mínimo de 60 segundos entre emissões por usuário. A resposta de solicitação é genérica para não informar se um e-mail existe. Limpar o cache invalida links pendentes. Em múltiplas instâncias, configure um cache compartilhado, por exemplo Redis.

Após redefinir a senha, o token é consumido, o remember token é trocado, tokens pessoais são excluídos e a versão de sessão é alterada. A próxima requisição autenticada de uma sessão antiga é recusada. Contas inativas também são bloqueadas. As senhas exigem oito caracteres, maiúscula, minúscula, número e símbolo.

### Índices e ativação

Execute `php artisan mongodb:ensure-indexes` ao atualizar outros ambientes. Ele adiciona um índice único parcial em `adoption_requests(user_id, pet_id)` para novas solicitações vinculadas a contas e um índice para moderação. Solicitações antigas sem `user_id` não entram na restrição. Os índices foram aplicados no banco configurado nesta implementação, sem remoção de documentos. Não é preciso rodar seed.

A entrega de e-mails depende do transporte SMTP configurado. Antes de publicar a plataforma para uso real, valide entrega de confirmação e recuperação, configure os dados de contato do operador e revise retenção e atendimento de pedidos de privacidade. A página `/privacidade` descreve os fluxos implementados; não constitui certificação jurídica de conformidade.

### Testes integrados

Além dos testes de navegador com API simulada, `MarketplaceMongoTest` executa gravações reais, permissões entre usuários, favoritos, solicitação duplicada, conclusão transacional, avaliação única, publicação da avaliação e redefinição de senha. Cria um banco exclusivo `adoptplace_test_<identificador>` e remove somente esse banco ao terminar. Notificações são simuladas para não enviar e-mails de teste.

```powershell
cd backend
$env:RUN_MONGODB_INTEGRATION='1'
php artisan test
Remove-Item Env:RUN_MONGODB_INTEGRATION
```

Sem essa variável, o teste integrado é ignorado. Para executar, o usuário MongoDB precisa ter permissão para criar e remover o banco temporário. Nunca altere o teste para usar o nome do banco da aplicação. Validação desta entrega: 27 testes PHP com integração (116 verificações), 14 cenários de navegador, teste do indicador de senha, lint e build.
