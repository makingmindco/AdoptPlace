# Primeiro deploy Render — revisão de prontidão

O build Docker real continua pendente. Build React e testes PHP locais sem Atlas passaram nesta revisão; o usuário informou dez integrações MongoDB aprovadas, 245 asserções e limpeza confirmada. Cloudinary real permanece não homologado. Nenhuma publicação foi executada.

## Código revisado

Docker multi-stage na raiz: Node 24 + npm ci/build; PHP 8.3 Apache + MongoDB PECL 2.5.0; Composer install pelo lock, sem dev, sem scripts, com check-platform-reqs. O estágio final não contém Node/Composer. .dockerignore exclui credenciais, dados locais, manifestos, vendor e arquivos temporários. Não declarar ARG para segredos nem copiar .env. CA padrão é /etc/ssl/certs/ca-certificates.crt, instalado pelo Debian; não incorporar a CA Kaspersky do notebook.

Apache publica somente public e escuta PORT (padrão 10000) em todas as interfaces. Entry point valida PORT e presença de APP_KEY/DB_URI/DB_DATABASE, prepara permissões em storage/bootstrap/cache, executa package:discover e config:cache e inicia apache2-foreground. Não executa artisan serve, migrations, seeds, criação de índices ou reconciliação. Prefork limitado a dois workers para reduzir pressão de memória; não é garantia de capacidade sob carga.

React é servido pelo fallback Laravel; api, sanctum, email/verify e storage não são capturados. Assets são servidos pelo Apache. VITE_API_URL=/api/v1 está fixado como caminho relativo no estágio de build, sem domínio de produção. O proxy Vite é somente de desenvolvimento.

Health check: /api/health. Throttle e middleware stateful do Sanctum são excluídos somente dessa rota, evitando consultas de sessão/cache na sondagem. Não valida conectividade Atlas, fotos ou entrega de e-mail. Demais endpoints preservam seus middlewares.

## Configuração manual no Render

Somente após autorizar revisão/commit/push dos arquivos locais e CI do novo commit aprovado:

1. New > Web Service, conectar GitHub/makingmindco/AdoptPlace e branch main. Não conectar como Static Site ou runtime Node.
2. Language Docker; Root Directory vazio (raiz do monorepo); Dockerfile Path ./Dockerfile; contexto Docker raiz. Não escolher backend como raiz, pois o build precisa também de frontend.
3. Selecionar Free, sem disco persistente e sem serviços adicionais pagos. Confirmar no painel se a conta permite continuar sem cartão; não há garantia de dispensa de verificação de identidade/pagamento específica da conta.
4. Não preencher override de Docker Command. A imagem já tem ENTRYPOINT/CMD. Não cadastrar pre-deploy, seed, migration ou comando de limpeza.
5. Health Check Path /api/health. Usar PORT fornecido pelo Render ou 10000. URLs públicas devem corresponder exatamente ao hostname atribuído ao serviço.
6. Cadastrar as variáveis abaixo como valores do ambiente, sem enviá-las ao chat. Selecionar auto-deploy After CI Checks Pass após o primeiro build manual aprovado. Não disparar o primeiro deploy antes de conferir commit/CI e variáveis.
7. Em Atlas, autorizar as faixas de saída da região Render e usar credencial restrita ao banco dedicado à instância publicada. Esse banco não deve ser o temporário dos testes. Operações de preparação/índices e dados demonstrativos exigem autorização separada.

## Variáveis

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<servico>.onrender.com
FRONTEND_URL=https://<servico>.onrender.com
SANCTUM_STATEFUL_DOMAINS=<servico>.onrender.com
TRUSTED_PROXIES=*
LOG_CHANNEL=stderr
LOG_LEVEL=warning
DB_CONNECTION=mongodb
SESSION_DRIVER=mongodb
SESSION_CONNECTION=mongodb
SESSION_LIFETIME=120
SESSION_SECURE_COOKIE=true
SESSION_COOKIE=adoptplace_session
CACHE_STORE=mongodb
PASSWORD_RESET_STORE=mongodb_passwords
PET_MEDIA_PROVIDER=cloudinary
QUEUE_CONNECTION=sync
CLOUDINARY_HOMOLOGATION_ENABLED=0
RUN_MONGODB_INTEGRATION=0
```

Fornecer privadamente APP_KEY, DB_URI, DB_DATABASE e CLOUDINARY_CLOUD_NAME/API_KEY/API_SECRET. SESSION_DOMAIN deve ficar sem definição (cookie restrito ao host). Não definir CACHE_DRIVER=file/array: o legado tem precedência sobre CACHE_STORE. TRUSTED_PROXIES=* é destinado exclusivamente ao ingresso controlado do Render. CORS usa FRONTEND_URL, e a mesma origem dispensa arquitetura de autenticação entre Vercel e API.

APP_KEY deve permanecer estável: reutilizar a chave correspondente caso o banco autorizado contenha dados criptografados existentes. Banco novo pode ter chave própria, preparada privadamente, nunca regenerada no deploy. Não usar a chave pública de fixtures do PHPUnit. Alterar hostname/cookies/chave pode exigir novo login e invalidar links antigos.

MAIL_MAILER=array permite um primeiro smoke test sem envio nem conteúdo de e-mail nos logs; não é solução de entrega. No gratuito, portas SMTP 25/465/587 são bloqueadas. Publicação de animais exige e-mail confirmado e recuperação depende de entrega real: homologação completa precisa de transporte compatível. Não presumir que selecionar um mailer HTTPS funciona sem conferir dependências e configurar o respectivo provedor. Não contornar confirmação de e-mail.

## Limitações e verificações após publicação autorizada

- Docker/Apache ainda precisam de build real no Render; não é necessário instalar Docker no notebook.
- TLS Linux usa CAs Debian e não passa pelo Kaspersky do notebook. Isso elimina a dependência desse ajuste local, mas não comprova antecipadamente acesso/autenticação Cloudinary no Render.
- Cloudinary não foi homologado: não repetir o upload de resultado incerto e não executar o executor de homologação automaticamente. Uploads locais no Render são efêmeros; não usar PET_MEDIA_PROVIDER=local para preservar novas fotos.
- Conferir URLs de fotos antigas: /demo-pets estão na imagem, mas localhost/storage e arquivos só existentes no notebook não ficam disponíveis na nuvem. Sem migração autorizada, referências antigas podem continuar quebradas.
- Catálogo vazio num banco novo é esperado; deploy não cria usuários, organizações aprovadas ou animais. Planejar dados/índices separadamente. Não reutilizar credencial de testes para o serviço publicado.
- Free suspende após inatividade, tem cold start, quotas e não oferece Shell/SSH ou one-off jobs. photos:reconcile não poderá ser executado via Shell gratuito; requer ambiente externo autorizado com acesso ao banco/provedor. Não criar endpoint público de manutenção para contornar isso.
- Verificar /, refresh /animais, asset JS/CSS, /api/health, /api/v1/pets e 404 de endpoint API; depois login, CSRF, cookies HTTPS, sessão após reinício, fotos e e-mail em homologação autorizada.
- O CI não constrói a imagem Docker nem executa Cloudinary/Atlas real. Primeira compilação pode revelar requisitos do Linux/PECL, limites de memória ou rede.

Reversão: voltar ao commit/imagem anterior conhecido pelo painel, preservando chaves, credenciais, banco e mídia. Rollback de código não reverte dados nem restaura fotos excluídas. O gratuito limita opções de histórico; preservar identificadores de commits validados.

Fontes consultadas: https://render.com/docs/docker ; https://render.com/docs/free ; https://render.com/docs/deploys ; https://render.com/docs/ssh ; https://render.com/changelog/free-web-services-will-no-longer-allow-outbound-traffic-to-smtp-ports . Regras e elegibilidade devem ser conferidas no painel da conta.
