# Homologação MongoDB — 9D.5A

Status: execução real pendente. O usuário informou a criação de uma credencial readWrite restrita ao banco temporário; a URI ainda não foi configurada e os privilégios/rede não foram verificados por conexão. Nenhum teste MongoDB ou operação de limpeza foi executado. Nenhum upload/exclusão Cloudinary foi realizado.

## Ambiente a preparar e autorizar

Nome exato proposto: `adoptplace_test_9d5a_20261113_a7f30c92`. É uma proposta, não um banco já criado. Usar replica set/cluster de testes separado, sem reutilizar banco principal ou demonstrativo, com credencial restrita ao banco temporário. Disponibilizar URI somente em variável local privada, nunca no chat ou repositório. Antes da execução, confirmar isolamento do servidor e permissões; a declaração de isolamento não verifica os privilégios da credencial automaticamente.

Variáveis obrigatórias para uma futura execução autorizada:

```text
TEST_MONGODB_URI=<conexão privada de testes>
TEST_MONGODB_DATABASE=adoptplace_test_9d5a_20261113_a7f30c92
TEST_MONGODB_CONFIRM_DATABASE=adoptplace_test_9d5a_20261113_a7f30c92
TEST_MONGODB_ISOLATED=1
RUN_MONGODB_INTEGRATION=1
```

Não basta definir RUN_MONGODB_INTEGRATION. A proteção exige prefixo/formato temporário, nome confirmado e URI própria; nunca lê DB_URI como fallback. Os testes recusam qualquer coleção preexistente. A primeira escrita insere `_test_ownership` com nonce único, e somente depois a conexão Laravel é apontada para esse banco. Uma execução concorrente com o mesmo nome é recusada pelo marcador único. Executar sequencialmente, sem paralelizar essa classe.

Cada teste usa o mesmo nome autorizado e, futuramente, instalará índices e gravará fixtures somente nele. A limpeza não usa dropDatabase: valida o nonce e uma lista fechada de coleções antes de executar dropCollection. Coleções permitidas: users, organizations, pets, adoption_requests, audit_logs, personal_access_tokens, photo_deletions e as cinco coleções adoptplace_runtime_* definidas em MongoIntegrationGuard::COLLECTIONS. O marcador _test_ownership é removido por último. Se existir qualquer coleção desconhecida, toda a limpeza é recusada. O marcador é relido antes de cada exclusão. O teste seguinte exige novamente banco sem coleções. Não há limpeza por prefixo ou descoberta de outros bancos.

A configuração isolada é validada antes do bootstrap dos providers, inclusive sobrepondo configuração em cache. Nenhuma variável TEST_MONGODB_URI ausente é substituída por DB_URI. A credencial restrita no servidor continua sendo obrigatória: nenhum código pode comprovar isolamento absoluto caso alguém forneça uma credencial administrativa ou altere a suíte. Um erro de limpeza/interrupção exige inspeção e autorização própria antes de remover sobras.

Operações planejadas após autorização: leitura de coleções, criação do marcador, índices de fixtures, inserção/atualização/exclusão de documentos de teste, transações e dropCollection somente nas coleções autorizadas desse banco. readWrite normalmente cobre essas operações; não será solicitado dbAdmin ou acesso a outros bancos para contornar falhas. Nenhuma delas foi executada agora.

## Onde colocar as credenciais

A suíte lê as variáveis TEST_MONGODB_* por getenv, do processo que executa PHPUnit. Não criar .env.testing com segredos nem editar .env principal. Preferir variáveis temporárias em um terminal PowerShell dedicado do VS Code. Informar senha por Get-Credential e montar a URI em memória, codificando usuário e senha para URI; não colar a URI real no histórico, em arquivos versionados ou em saídas de terminal. Configurar RUN_MONGODB_INTEGRATION=0 enquanto estiver somente preparando. As variáveis de um terminal não aparecem automaticamente nos processos de outra sessão/ferramenta.

No Atlas, verificar Database Access para confirmar que a conta tem somente readWrite no nome exato, sem papéis amplos adicionais. Em Network Access, autorizar o IP público atual desta máquina (ou da VPN/proxy efetivamente usado), preferencialmente por entrada temporária /32. Não usar o IP antigo de capturas de tela e não liberar 0.0.0.0/0 para contornar problemas. Sem hostname e configuração local, nem DNS/TCP/TLS nem a lista privada do Atlas foram comprovados. Verificação de conexão somente de leitura deverá ser autorizada separadamente antes da suíte com escrita.

## Cobertura preparada

- Fotos: upload local fake, metadados, capa, última foto, pendência concluída, repetição DELETE sem duplicidade e exclusão física fake.
- Transações: rollback se falhar o registro da pendência; dois snapshots concorrentes escrevendo no mesmo documento, com apenas uma atualização confirmada.
- Runtime: recriação de drivers preservando sessão e CSRF, resolução do usuário via SessionGuard, invalidação e expiração, cache persistente e tokens de recuperação após recriação do repositório.
- Fluxos anteriores: BSON/JSON legado, favoritos, permissões, adoção, reservas, avaliações, senha, fotos e slugs.

O teste de conflito valida o comportamento real do MongoDB por operações nativas; não é uma prova exaustiva de concorrência HTTP do controller. Login/logout reais pelo navegador e reinicialização de contêiner ainda precisam de homologação posterior. Cloudinary fica bloqueado na classe MongoDB por cliente simulado que proíbe chamadas; os testes específicos do adaptador simulam upload/exclusão sem credenciais.

## Resultado local

PHPUnit descobriu 55 testes: 45 aprovados, 213 asserções, 10 ignorados por dependerem de MongoDB isolado, nenhuma falha. URI do processo de teste sem banco apontou para localhost; sessão/cache/senha em array, mídia local fake, sem integração habilitada. Sintaxe e whitespace foram verificados. Nenhuma correção de regra funcional foi necessária; foram reforçadas as proteções do harness e adicionados testes dessas proteções.

## Próximos passos

1. Preparar conexão isolada e conferir direitos sem expor credenciais.
2. Autorizar explicitamente o nome e as operações acima.
3. Executar a suíte completa com as variáveis de teste e analisar falhas sem usar dados reais.
4. Só após aprovação dessa validação, construir a imagem Docker remotamente e homologar HTTPS, persistência após reinício e armazenamento no ambiente de demonstração Render. O build Docker ainda não foi comprovado.
