# Preparação do backend

O projeto contém Laravel 9 e Sanctum 3. A integração com MongoDB ainda não foi
instalada. As variáveis MONGODB_URI e MONGODB_DATABASE estão reservadas no exemplo;
não foi ativado um driver inexistente. A configuração SQL do template permanece.

Antes de desenvolver a persistência, revisar a atualização do Laravel e validar as
versões compatíveis do PHP, extensão mongodb e integração oficial MongoDB Laravel.

## Iniciar (com PHP e Composer no PATH)

```powershell
cd backend
composer install
Copy-Item .env.example .env
php artisan key:generate
php artisan serve --host=127.0.0.1 --port=8000
```

Não sobrescreva um .env existente. Ajuste APP_URL=http://127.0.0.1:8000 e
FRONTEND_URL=http://localhost:5173 conforme a origem efetiva.
GET /api/health retorna {"status":"ok","service":"adoptplace-api"} sem acessar o banco.
PHP e Composer não foram encontrados neste terminal; Laravel não pôde ser executado.

## Contrato proposto (a implementar)

| Método | Endpoint | Objetivo |
| --- | --- | --- |
| GET | /api/pets | Catálogo público, filtros e paginação |
| GET | /api/pets/{id} | Perfil público do animal |
| POST | /api/register | Cadastro da organização/protetor |
| POST | /api/login | Autenticação |
| POST | /api/logout | Encerrar sessão |
| GET | /api/user | Usuário autenticado (rota Sanctum do template) |
| POST | /api/pets | Criar animal da ONG autenticada |
| PATCH | /api/pets/{id} | Editar perfil/status, autorizado por organização |

Animal: id (string pública, inclusive se derivada de ObjectId), name, species, sex,
age, size, city, image, personality (array), description, health (array), status
(available/adopted), organization_id. Use frontend/src/data/pets.js como referência.
Dados públicos de ONG: nome, cidade e contato autorizado.

Cadastro: name, city, email, password, password_confirmation, responsible_adoption.
Login: email, password. O servidor deve validar campos, aplicar hash de senha,
garantir unicidade do e-mail e limitar tentativas. Erros 422 devem seguir o formato:

```json
{"message":"Dados inválidos.","errors":{"email":["E-mail já cadastrado."]}}
```

## Próximos passos

1. Validar/atualizar Laravel e PHP; instalar extensão e driver MongoDB compatíveis.
2. Configurar banco, modelos, índices e validações.
3. Implementar Sanctum, cookies, bootstrap CSRF, recuperação de senha e logout.
4. Proteger dashboard no React e endpoints no servidor com autorização por ONG.
5. Substituir exemplos por serviços, estados de carregamento e erro.
6. Implementar uploads validados e contato real da organização.

O frontend tem cliente HTTP que envia credenciais, mas ainda não gerencia sessão
nem inicializa CSRF. A flag VITE_AUTH_ENABLED deve permanecer false até concluir
esse fluxo. O painel atual é uma demonstração pública, sem dados privados.

O Vite encaminha /api e /sanctum para 127.0.0.1:8000, mantendo as chamadas na mesma
origem. Para implantação entre origens, configurar CORS com credenciais, cookies
de sessão e domínios stateful do Sanctum conforme a topologia final.
