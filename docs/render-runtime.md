# Sessões, cache e recuperação no Render — Etapa 9D.4C

Laravel 13.40.0 e mongodb/laravel-mongodb 5.12.0 instalados incluem MongoDbSessionHandler e MongoStore. Os drivers registrados são `mongodb`, não o driver SQL `database`. A resolução dos drivers foi testada sem conexão externa; persistência real e expiração no Atlas continuam pendentes de homologação isolada.

## Configuração no painel

Configure no Render, sem versionar segredos:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<servico>.onrender.com
FRONTEND_URL=https://<servico>.onrender.com
SANCTUM_STATEFUL_DOMAINS=<servico>.onrender.com
TRUSTED_PROXIES=*
SESSION_DRIVER=mongodb
SESSION_CONNECTION=mongodb
SESSION_LIFETIME=120
SESSION_SECURE_COOKIE=true
SESSION_COOKIE=adoptplace_session
CACHE_STORE=mongodb
PASSWORD_RESET_STORE=mongodb_passwords
```

Use TRUSTED_PROXIES=* somente atrás do ingresso confiável do Render. Deixe SESSION_DOMAIN sem definição para cookie restrito ao host. Cadastre também APP_KEY estável, DB_CONNECTION=mongodb, DB_URI e DB_DATABASE do ambiente autorizado. Não execute key:generate em deploy ou inicialização. Não envie esses valores reais no chat.

CACHE_DRIVER tem precedência por compatibilidade com ambientes anteriores e phpunit.xml. Remova-o no Render ou defina também mongodb. CACHE_STORE passa a ser reconhecido; antes somente CACHE_DRIVER controlava o cache efetivo. Valores explícitos sempre prevalecem: copiar o .env.example local (file) para produção não ativa persistência. PASSWORD_RESET_STORE=null serve ao desenvolvimento, não à configuração recomendada no Render.

Sem overrides, APP_ENV=production seleciona sessões/cache MongoDB, cookies seguros e store de recuperação mongodb_passwords. Local permanece file, e phpunit.xml/CI continuam selecionando array. Não há fallback silencioso para arquivo quando Atlas falha. A inicialização existente gera config:cache com o ambiente de execução e nunca regenera a chave; nenhuma criação de índices foi adicionada.

## Coleções e expiração

São usadas coleções exclusivas, com nomes definidos na configuração:

- adoptplace_runtime_sessions
- adoptplace_runtime_cache e adoptplace_runtime_cache_locks
- adoptplace_runtime_password_resets e adoptplace_runtime_password_locks

Não apontar esses stores para users, pets ou outras coleções de negócio. O nome da coleção de sessões mudou de sessions: sessões persistidas no nome anterior não são importadas automaticamente. A primeira migração de file para MongoDB exige novo login, pois os arquivos locais não são transferidos. Tokens antigos no cache file também não são importados; solicite links novos após a transição.

Sessões vencidas são rejeitadas na leitura usando expires_at; o driver possui coleta probabilística. Cache vencido também é rejeitado e removido na leitura. Tokens de recuperação continuam com hash, validade de 60 minutos, throttle de 60 segundos e consumo após redefinição. Eles usam um store separado: limpar o cache geral não deve apagá-los. Não limpe o store de senhas durante deploy.

TTL recomendado para sessões e ambos os stores de cache: índice simples `{expires_at: 1}`, com expireAfterSeconds=0. Locks também têm suporte TTL no driver; planejar o índice correspondente nas duas coleções de locks. Isso é uma proposta para execução autorizada posterior, não uma migração ou ação automática. Não aplicar TTL a coleções de negócio. A remoção TTL é assíncrona e não determina a validade lógica. Sem índices, documentos não acessados podem acumular. Nenhum índice foi criado nesta etapa.

Logout preserva o fluxo atual: encerra autenticação, invalida a sessão e renova CSRF. Login continua regenerando a sessão; redefinição continua invalidando versões de sessão antigas e tokens pessoais. Cookies HttpOnly, SameSite=lax e CSRF são preservados. Links de confirmação de e-mail são assinados e temporários, não dependem de cache local: exigem APP_KEY, origem HTTPS e configuração de proxy estáveis. Reenvio e recuperação ainda dependem de transporte de e-mail funcional.

## Homologação posterior

1. Configurar banco/credenciais isolados, com permissão nas coleções de runtime; conferir o nome antes de qualquer escrita.
2. Executar a suíte MongoDB opt-in somente nessa infraestrutura descartável. O teste novo recria drivers, verifica continuidade de sessão/CSRF, invalidação, cache persistente, expiração e tokens de recuperação. Simula recriação dos drivers, não uma reinicialização completa do Render nem login real no navegador.
3. Em homologação HTTPS, entrar pelo navegador, reiniciar/republicar o serviço e confirmar /auth/me sem novo login, enquanto a sessão estiver válida. Conferir cookies e uma ação com CSRF.
4. Fazer logout, verificar 401 e repetir o login. Testar senha redefinida invalidando a sessão anterior.
5. Solicitar recuperação antes de reiniciar, concluir depois e confirmar que o mesmo token não pode ser reutilizado. Testar expiração. Validar confirmação de e-mail antes/depois do reinício.
6. Validar TTL e uso de espaço somente após autorização específica para os índices.

## Limitações

Persistência não elimina a expiração normal, logout, invalidação por senha, mudança de domínio/cookie/chave ou falha do Atlas. A indisponibilidade do banco agora afeta sessões e rate limits/cache também, adicionando latência e operações ao cluster. A documentação do Render informa suspensão gratuita após 15 minutos sem tráfego, retomada com atraso, disco efêmero e quotas de uso; persistir no Atlas não remove essas limitações. SMTP convencional pode estar bloqueado no gratuito: a entrega real precisa ser homologada com transporte compatível.

Referências: https://www.mongodb.com/docs/drivers/php/laravel-mongodb/current/integrations/sessions/ e https://www.mongodb.com/docs/drivers/php/laravel-mongodb/current/integrations/cache/ ; limites Render: https://render.com/docs/free . A implementação instalada foi conferida localmente, pois detalhes da documentação podem diferir entre versões.
