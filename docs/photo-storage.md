# Fotos: Etapas 9D.4A e 9D.4B

O padrão é `PET_MEDIA_PROVIDER=local`, usando `PET_MEDIA_DISK=public` ou outro disco Laravel configurado. Para Cloudinary, selecionar `PET_MEDIA_PROVIDER=cloudinary` e configurar `CLOUDINARY_CLOUD_NAME`, `CLOUDINARY_API_KEY` e `CLOUDINARY_API_SECRET` no backend. Não colocar segredos em Vite, Docker ou Git. SDK oficial 3.3.0; transformation-builder 2.1.5.

Novas fotos registram provedor, disco/caminho ou public_id. A API preserva id, url e path (nulo), sem publicar identificadores internos. Legados sem provedor/caminho são aceitos como demonstração somente para `/demo-pets/*.png`. Legados com caminho precisam estar sob `pets/{id-do-animal}/`; o disco legado padrão é public, configurável por `photos.legacy_disk`. Não se infere o disco do provedor atual. Origens ambíguas retornam 409 sem alterações; não há migração automática.

## Exclusão

A autorização continua na policy. Em transação MongoDB, PhotoRemoval relê o animal, verifica a última foto de animais publicados/reservados, atualiza fotos/capa e grava photo_deletions. Se qualquer escrita falhar, ambas são revertidas. Não há operação no provedor antes do retorno confirmado da transação. Conflitos concorrentes podem retornar erro e precisam de nova tentativa; não há promessa de sucesso automático.

Cada tarefa tem _id determinístico (SHA-256 de pet_id e photo_id), protegido pela unicidade nativa de _id. Armazena pet_id, photo_id, snapshot do provedor, ator, motivo, datas e estado: pending, failed, blocked ou completed. Não exige criar índices para funcionar. Não há TTL: registros concluídos são preservados para idempotência e rastreabilidade.

Após commit, a requisição tenta a limpeza imediatamente. A resposta mantém o recurso Pet e adiciona photo_cleanup=completed ou pending. Pending significa que a referência já foi removida, mas a exclusão física não foi confirmada. Repetir DELETE para o mesmo animal/foto reutiliza a tarefa. Se nem foto nem tarefa existirem, retorna 404. Falha no audit log posterior não reverte as referências ou a tarefa; uma repetição é segura.

O processador valida o prefixo de propriedade do animal e verifica se foto/caminho/public_id/URL ou capa ainda são referenciados naquele animal; nesse caso marca blocked. Fotos demonstrativas não causam exclusão física. A falta de um arquivo local ou resultado Cloudinary not found é sucesso. Timeouts podem ocorrer após o provedor apagar: a repetição conclui sem falha permanente. Processadores concorrentes podem chamar delete mais de uma vez; os adaptadores são idempotentes e uma atualização tardia de falha não sobrescreve completed. Não existe lease/worker permanente.

Troca de capa e upload também escrevem após releitura dentro de transação, evitando decisões baseadas em arrays antigos. O upload verifica novamente o limite de dez fotos. Conflitos de escrita são rejeitados pelo MongoDB; sua semântica real depende de replica set/Atlas e precisa ser validada em banco isolado.

## Reconciliação explícita

O comando NÃO é chamado no Dockerfile, entrypoint, scheduler ou deploy. Ele usa a conexão configurada no processo. Antes de usá-lo, confirmar ambiente, URI privada e banco autorizado. Exemplo ilustrativo para um banco de demonstração:

```sh
php artisan photos:reconcile --database=adoptplace_demo --limit=25
php artisan photos:reconcile --database=adoptplace_demo --limit=25 --execute
```

Sem --execute, apenas lista ids e estados, sem atualizar tarefas ou chamar provedores. O nome precisa coincidir exatamente com a configuração; limite entre 1 e 100. Com --execute, reprocessa pending/failed/blocked, retorna erro se alguma não concluir e mantém tarefas para nova tentativa. A confirmação do nome não substitui credenciais restritas. Execute primeiro em demonstração e somente após autorização para o ambiente. O comando pode requerer várias chamadas para esgotar o backlog. Tarefas bloqueadas antigas podem exigir investigação antes de avançar o lote.

## Uploads e limitações

O upload externo ocorre antes da persistência. Falha conhecida da persistência provoca compensação imediata. Se a compensação falhar ou o resultado MongoDB for incerto, tenta registrar uma tarefa durável; o processador não apaga imagem ainda referenciada. Detalhes do SDK/segredos não entram nos logs ou respostas.

Persistem janelas de falha: processo encerrado entre upload e registro; upload remoto com timeout e resultado desconhecido; indisponibilidade MongoDB impedindo registrar compensação; confirmação de commit incerta. Essas condições podem exigir inspeção manual dos logs e do provedor. Não há registro de intenção ANTES do upload nem garantia de ausência total de órfãos. Rotinas de descoberta automática não foram implementadas.

Registros locais só podem ser reconciliados na instância que possui seus arquivos. No Render sem disco persistente, arquivo perdido é considerado ausente, mas isso não recupera imagens. Não reutilizar IDs/caminhos de fotos removidas; uploads novos usam identificadores únicos. Alterações diretas no banco, referências cruzadas corrompidas ou remoção de animais fora deste fluxo exigem inspeção própria. A seleção do provedor usa adaptadores e pode ser ampliada sem alterar o fluxo transacional.

## Validação

Testes sem banco usam Storage::fake e cliente Cloudinary simulado para falhas de rede, repetição, recuperação e validação de propriedade. Testes transacionais estão no MarketplaceMongoTest opt-in: rollback caso a tarefa falhe, repetição do endpoint e conflito real entre dois snapshots MongoDB. São executáveis somente com banco descartável isolado; não foram executados nesta etapa. Simulações não comprovam atomicidade real.
