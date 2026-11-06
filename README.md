# AdoptPlace

Plataforma sem fins lucrativos para adoção responsável. O projeto usa React 19 + Vite no frontend, Laravel 13 no backend e MongoDB.

## Funcionalidades

- Catálogo público com filtros, favoritos e perfis de animais.
- Formulário de interesse em adoção com consentimento de privacidade.
- Cadastro e login de ONGs/protetores com sessão segura via Sanctum.
- Painel privado para cadastrar animais, acompanhar status e interesses.
- Aprovação de organizações, moderação de animais e auditoria administrativa.
- Upload de imagens em disco local ou armazenamento compatível com S3.
- Validação, políticas de autorização, rate limits e índices MongoDB.

## Executar localmente

Requisitos: Node.js 24+, PHP 8.3+ com extensão `mongodb`, Composer e MongoDB 7+ (local ou Atlas).

```powershell
cd backend
composer install
Copy-Item .env.example .env
php artisan key:generate
# Configure DB_URI e DB_DATABASE no .env
php artisan mongodb:ensure-indexes
php artisan db:seed # opcional; cria dados locais demonstrativos
php artisan serve --host=127.0.0.1 --port=8000
```

Em outro terminal:

```powershell
cd frontend
npm ci
Copy-Item .env.example .env
npm run dev
```

Não sobrescreva arquivos `.env` existentes. O Vite encaminha `/api` e `/sanctum` ao Laravel. Acesse `http://localhost:5173`.

## Verificações

```powershell
cd frontend
npm run lint
npm run build

cd ..\backend
composer test
```

## Estrutura

- `frontend/src/pages`: catálogo, perfil/adoção, autenticação e painel.
- `frontend/src/services/api.js`: cliente da API e inicialização CSRF.
- `backend/app/Http/Controllers/Api/V1`: API pública, privada e administrativa.
- `backend/app/Models`: documentos MongoDB.
- `backend/app/Policies`: isolamento dos dados por organização.
- `docs/backend.md`: endpoints, configuração e implantação.

O redesign parte de `design-system.html`. Os HTMLs originais na raiz permanecem apenas como referência visual.
