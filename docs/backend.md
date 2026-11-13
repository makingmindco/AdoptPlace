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

Em um banco de desenvolvimento novo e explicitamente selecionado, execute `php artisan mongodb:ensure-indexes`. O comando grava índices; não converte documentos legados. Não execute seeds para atualizar contas ou dados existentes. A Etapa 9A não cria dados demonstrativos.

As instruções de Atlas, variáveis de ambiente e sessão Sanctum estão no [README](../README.md). Os dois `.env.example` foram conferidos contra as configurações utilizadas, sem leitura de arquivos `.env` reais. Não foi necessário alterá-los. O SMTP de exemplo (`mailpit:1025`) depende de um serviço externo ao projeto; configure um transporte acessível ou `MAIL_MAILER=log` no desenvolvimento, sem entrega real de e-mail. A URI local de exemplo exige MongoDB configurado como replica set para as transações; Atlas é uma alternativa.

### Limites de upload

`UploadPetPhotoRequest` valida imagens de até **5 MB por arquivo**. O limite da aplicação não aumenta os limites do PHP. `upload_max_filesize` pode ser menor (por exemplo, 2M), e `post_max_size` limita o corpo inteiro da requisição, incluindo os campos e o multipart. Isso pode impedir que o arquivo alcance a validação do Laravel.

Consulte o arquivo carregado com `php --ini`. Como exemplo de configuração local, `upload_max_filesize=5M` e `post_max_size=8M` permitem margem para o formulário. Reinicie o processo PHP após ajustes e confira também limites do servidor/proxy em hospedagem. Os valores efetivos da máquina não foram inspecionados nem alterados na Etapa 9A.

Para imagens em produção, configure `PET_MEDIA_DISK=s3` e as variáveis AWS/S3. Para notificações assíncronas, troque `QUEUE_CONNECTION=sync` por um driver de fila e mantenha um worker ativo.

## Produção

- Sirva o Laravel por HTTPS e aponte o document root para `backend/public`.
- Configure `APP_ENV=production`, `APP_DEBUG=false`, MongoDB Atlas com usuário restrito e backup.
- Se frontend e API estiverem em subdomínios, use o mesmo domínio raiz, ajuste `SESSION_DOMAIN`, `SANCTUM_STATEFUL_DOMAINS`, `FRONTEND_URL` e `SESSION_SECURE_COOKIE=true`.
- Rode no deploy: `composer install --no-dev --optimize-autoloader`, `php artisan optimize`, `php artisan mongodb:ensure-indexes` e `php artisan storage:link` quando usar disco público.
- Configure o frontend com `VITE_API_URL=https://api.seudominio.org/api/v1` antes de `npm run build` e fallback SPA para `index.html`.
- Configure SMTP, monitoramento, rotação de logs, política de retenção/eliminação de dados e termos de privacidade antes de aceitar dados reais.

## Estado funcional documentado

Já estão implementados catálogo dedicado com filtros e paginação, edição de animais, gestão de fotos (upload, exclusão e escolha de capa), galeria no detalhe, reserva de adoção e encerramento dos demais interesses após conclusão. Os fluxos de conta, recuperação, confirmação de e-mail, favoritos, avaliações e testes MongoDB estão descritos abaixo. Esta etapa documenta o escopo existente, sem propor novas funcionalidades.

### Persistência BSON e compatibilidade legada

`App\Casts\BsonArray` grava arrays/subdocumentos BSON nativos e normaliza a leitura de arrays e strings JSON legadas. É utilizado em `Pet` (`personality`, `health`, `location`, `photos`), `AdoptionRequest.status_history`, `Organization.social_links`, `AuditLog.metadata` e `User.favorite_ids`. Avaliações ficam embutidas em `adoption_requests.review` como documento BSON.

Essa compatibilidade evita depender do cast genérico `array`, que na integração MongoDB pode armazenar JSON como string. Os filtros públicos de cidade e estado em `PublicPetController` consultam tanto `location.city`/`location.state` quanto o formato JSON legado. Apenas ler um documento não o converte nem faz uma migração em massa; compatibilidade do cast também não substitui o tratamento de consultas a campos aninhados.

Os campos pessoais `User.address`, `Organization.private_address` e `AdoptionRequest.adopter` continuam com `encrypted:array`, pois precisam permanecer criptografados. Preserve a `APP_KEY`. Não foram consultados documentos ou tipos de dados efetivos do Atlas nesta etapa.

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
| `/` | Animais em destaque e acesso ao catálogo |
| `/animais` | Catálogo público com filtros e paginação; `/catalogo` redireciona para esta rota |
| `/pets/{slug}` | Detalhe público do animal disponível, galeria e envio de interesse autenticado |
| `/organizacoes/{slug}` | Perfil aprovado, animais disponíveis e avaliações publicadas |
| `/dashboard` | Edição de perfil, contatos públicos, animais e andamento das solicitações |
| `/minha-conta` | Favoritos da conta, solicitações, confirmação de adoção, avaliação e reenvio de confirmação de e-mail |
| `/administracao` | Aprovação/suspensão de organizações e moderação de avaliações; exclusiva de administradores |
| `/recuperar-senha` | Solicitar link de recuperação |
| `/redefinir-senha?token=...&email=...` | Redefinir senha com token válido |
| `/privacidade` | Explicação dos usos de dados e das regras de participação |

O catálogo continua público. A ação de favoritar nos cartões exige login e redireciona o visitante, preservando o destino de retorno. O contexto mantém compatibilidade com favoritos locais legados, sem importá-los automaticamente para a conta. Favoritos autenticados são atualizados com operações atômicas de inclusão sem duplicidade e remoção. A lista mostra apenas animais disponíveis de organizações aprovadas.

### Adoção e avaliação

| Ação | Solicitação | Animal |
| --- | --- | --- |
| Enviar interesse | `new` | Continua `published` |
| Analisar ou contatar | `reviewing` ou `contacted` | Continua disponível, salvo reserva de outro candidato |
| Aprovar candidato | `approved` | `adoption_in_progress` (reservado) |
| Desistir da candidatura aprovada | `withdrawn` | Volta a `published` quando a reserva é liberada |
| Rejeitar ou retornar a aprovação para análise/contato | `rejected`, `reviewing` ou `contacted` | Libera a reserva conforme as condições abaixo |
| Confirmar recebimento pelo titular | `completed`; demais abertas encerradas como `rejected` | `adopted` |
| Registrar adoção externa | Abertas encerradas como `rejected`, sem gerar `completed` | `adopted`; bloqueada se houver aprovação ativa |
| Republicar | Preserva histórico e avaliações | `published`; bloqueada se houver aprovação ativa |

Solicitações `completed`, `withdrawn` e `rejected` são encerradas e não podem ser reabertas pelos endpoints comuns. Desistir não desfaz uma adoção já concluída. Republicar um animal é uma operação distinta e não permite reenviar uma candidatura já existente para o mesmo par usuário/animal.

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

O comando `php artisan mongodb:ensure-indexes` define, entre outros, o índice único parcial em `adoption_requests(user_id, pet_id)` para solicitações com `user_id` do tipo string, o índice único parcial `adoption_requests_one_approved_per_pet` para status `approved` e índices de moderação. A proteção de uma aprovação por animal depende da instalação desse índice no ambiente, além das regras transacionais da API.

A existência dos índices no Atlas não foi verificada na Etapa 9A. A execução do comando deve ocorrer apenas no ambiente autorizado e pode falhar se dados existentes violarem a unicidade; não remove nem corrige duplicidades automaticamente. Não é preciso executar seed. A restrição `(user_id, pet_id)` permanece após encerramento ou republicação: o mesmo usuário não pode criar outra candidatura para aquele animal.

A entrega de e-mails depende do transporte SMTP configurado. Antes de publicar a plataforma para uso real, valide entrega de confirmação e recuperação, configure os dados de contato do operador e revise retenção e atendimento de pedidos de privacidade. A página `/privacidade` descreve os fluxos implementados; não constitui certificação jurídica de conformidade.

### Testes integrados

Além dos testes de navegador com API simulada, `MarketplaceMongoTest` contém verificações de gravação BSON e leitura/filtros legados, permissões, favoritos, reserva, desistência, rejeição, conclusão transacional, adoção externa, republicação, gestão de animais/fotos, avaliações e redefinição de senha. São testes da API/persistência, sem navegador React. A presença desses testes no código não comprova sua execução nesta etapa nem uma cobertura exaustiva de concorrência.

A suíte exige `TEST_MONGODB_URI`, `TEST_MONGODB_DATABASE`, confirmação do nome e declaração de isolamento, sem fallback para a URI da aplicação. Consulte [o procedimento 9D.5A](mongodb-homologation.md) antes de executar os comandos abaixo. Um banco com coleções preexistentes é recusado; a limpeza exige marcador de propriedade da execução. Notificações e Cloudinary são simulados; arquivos usam Storage::fake.

```powershell
cd backend
$env:RUN_MONGODB_INTEGRATION='1'
try { php artisan test } finally { Remove-Item Env:RUN_MONGODB_INTEGRATION }
```

Sem essa variável, os testes integrados são ignorados. O usuário MongoDB precisa ter permissão para criar índices, gravar e remover os bancos temporários. Nunca altere o teste para usar o nome do banco da aplicação. Comandos das demais verificações estão no [README](../README.md#comandos-de-verificação).

### Resultados históricos informados pelo Antigravity

| Verificação | Resultado reportado | Natureza da evidência |
| --- | --- | --- |
| Backend | 34 testes aprovados, 288 asserções | Relato histórico; há testes PHP versionados, mas não foram executados na Etapa 9A |
| Playwright | 19 testes aprovados | Relato histórico; os testes versionados interceptam e simulam a API |
| Ponta a ponta real | 22 cenários aprovados | Relato de execução React + Laravel + MongoDB; não foram localizados roteiros/resultados versionados que comprovem esses 22 cenários |
| Lint e build | Sem erros | Relato histórico; não executados na Etapa 9A |

Os testes Playwright simulados não equivalem aos cenários reais: validam a interface e seus contratos simulados, sem provar sessão, persistência e integração com a API real. Os testes PHP com MongoDB verificam persistência em banco temporário, mas também não substituem um fluxo completo no navegador com backend real.

Os números acima foram fornecidos pelo usuário como resultados do Antigravity. A Etapa 9A não confirma data, ambiente ou commit exato dessas execuções, nem revalida as contagens de aprovação. Nenhuma suíte, lint ou build foi executada nesta etapa documental. As contagens antigas de 27 testes PHP/14 cenários de navegador foram retiradas para não representar incorretamente a entrega atual.
