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

Recuperação de senha, confirmação do e-mail por página no frontend, edição completa/fotos no painel, busca geográfica, notificações ao adotante e testes de integração com um MongoDB isolado são extensões previstas. A base atual já separa esses domínios para permitir evolução incremental.
