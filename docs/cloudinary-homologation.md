# Executor de homologação Cloudinary — 9D.6C

Comando manual `cloudinary:homologate`, desabilitado por padrão. Não é chamado pelo Docker, deploy, CI, scheduler ou reconciliação. Não houve operação real na implementação. Todos os testes usam cliente simulado.

## Isolamento e credenciais

Somente o processo PowerShell dedicado deve receber CLOUDINARY_CLOUD_NAME, CLOUDINARY_API_KEY, CLOUDINARY_API_SECRET e CLOUDINARY_HOMOLOGATION_ENABLED=1. O executor lê as credenciais por getenv, sem fallback para configuração .env ou CLOUDINARY_URL. Não colocar valores reais em argumentos, arquivos, logs ou chat. Manter RUN_MONGODB_INTEGRATION=0.

O Artisan inicializa o framework, mas um bootstrap específico remove todas as conexões de banco antes dos providers e seleciona sessão/cache em memória. O comando repete a proteção para chamadas Artisan internas. Não resolve DB, models, PhotoManager, PhotoRemoval ou PhotoDeletionProcessor; não escreve pendências MongoDB, não executa migrations/seeds/índices/reconciliação. O isolamento protege o código atual; código de providers alterado no futuro precisa ser novamente auditado.

O cliente específico usa SDK oficial, conexão de até 5 segundos e limite de 20 segundos para requisição/upload, TLS validado. Não configura retries automáticos. Erros exibem mensagem genérica sem detalhes do SDK ou credenciais.

## Fases — executar somente após autorização de cada fase

A partir de backend, no PowerShell com as credenciais temporárias:

```powershell
$env:RUN_MONGODB_INTEGRATION = '0'
$env:CLOUDINARY_HOMOLOGATION_ENABLED = '1'

# 1. Somente preparação local; confirmar o modo de pastas no painel.
php artisan cloudinary:homologate prepare --folder-mode=dynamic
# Para conta com pastas fixas legadas: --folder-mode=fixed

# Copiar os identificadores não secretos exibidos pela preparação:
$run = '<run de 32 caracteres hexadecimais>'
$publicId = '<public_id exato exibido>'

# 2. Modifica Cloudinary: requer autorização própria.
php artisan cloudinary:homologate upload --run=$run --confirm=$publicId

# 3. Consulta somente o recurso exato, consumindo requisição Admin API.
php artisan cloudinary:homologate verify --run=$run

# 4. Modifica Cloudinary: requer autorização própria.
php artisan cloudinary:homologate delete --run=$run --confirm=$publicId

# Repetir SOMENTE a mesma exclusão para testar idempotência, se autorizado.
php artisan cloudinary:homologate delete --run=$run --confirm=$publicId

# Ao encerrar:
$env:CLOUDINARY_HOMOLOGATION_ENABLED = '0'
Remove-Item Env:CLOUDINARY_API_SECRET -ErrorAction SilentlyContinue
Remove-Item Env:CLOUDINARY_API_KEY -ErrorAction SilentlyContinue
```

Não há opção de pasta arbitrária, public_id fornecido livremente, busca, listagem, exclusão por prefixo ou lote. O único parâmetro remoto de exclusão é derivado de um manifesto validado. O --confirm precisa ser exatamente esse identificador. Fechar o terminal remove seu ambiente temporário.

## Manifesto e imagem

Prepare gera run e identificador de recurso aleatórios (128 bits cada), public_id `adoptplace/pets/homologation-<run>/<recurso>`, estado reserved e modo de pasta. Tudo fica em `storage/app/private/cloudinary-homologation/<run>.json`, fora de public e ignorado por Git/Docker. Nunca armazena credenciais. A escrita usa arquivo temporário e rename; falha de gravação impede iniciar a operação remota. Há lock local por execução; não editar ou remover manifestos durante operações. No Windows, a proteção de acesso depende também das permissões NTFS do usuário; chmod não substitui ACLs.

Upload cria um PNG sintético embutido de um pixel, sem entrada de arquivo do usuário. Salva upload_attempted ANTES da chamada, usa overwrite=false e contexto assinado contendo o run. Em pastas dinâmicas, envia asset_folder=adoptplace-homologation/<run>; em pastas fixas, o caminho do public_id determina a pasta. O PNG local é removido após a tentativa. Interrupção abrupta pode deixar esse pequeno arquivo local, sem dados pessoais.

A resposta precisa corresponder a public_id, asset_id não vazio, image/upload, HTTPS, contexto da execução e, no modo dinâmico, asset_folder exato. Estado muda para uploaded. Verify consulta exclusivamente esse public_id, exige a mesma identidade/contexto e grava verified. Sem verificação bem-sucedida não há exclusão inicial.

Delete reinspeciona exatamente o recurso, compara asset_id previamente registrado e contexto/pasta. Grava delete_attempted antes de destroy e aceita apenas ok ou not found. Em sucesso registra deleted. Repetições reinspecionam o mesmo ID: um recurso substituto com asset_id diferente é recusado. O operador não deve renomear, substituir ou mover esses recursos simultaneamente: leitura e exclusão remotas não são uma operação atômica única.

## Falhas e recuperação

- Timeout/resposta inválida no upload mantém upload_attempted; não é permitido repetir upload cegamente. Usar verify, após autorização. Se ausente, o manifesto registra not_found; investigar antes de preparar outra execução.
- Timeout na exclusão mantém delete_attempted. A nova tentativa exige o mesmo --confirm e verifica identidade novamente. Já ausente é idempotente.
- Falha de verificação não autoriza exclusão; divergência de contexto, pasta ou asset_id exige inspeção manual.
- Se a resposta remota chegar mas a gravação local falhar, permanece a intenção anterior; verify recupera os metadados do recurso exato.
- Manter o manifesto até confirmar exclusão. Não há descoberta de órfãos, limpeza automática nem uso de photos:reconcile neste executor.
- Perda do manifesto exige intervenção manual no painel, jamais exclusão ampla por prefixo. Chave inválida ou sem permissão Admin API também impede verificação e exclusão: não contornar removendo as proteções.

O teste consome armazenamento e chamadas API; configurações/presets da conta podem gerar transformações. Consultas Admin API possuem limites próprios. O executor não baixa a URL da imagem nem pede transformações opcionais. Exclusão/invalidação não apagam consumo já ocorrido. Conferir cotas no painel antes de autorizar.

Ainda depende de homologação real: autenticação, resposta de contexto/asset_folder, permissões Admin API, latência e idempotência remota. Mocks validam o controle do executor, não o comportamento da conta.

## Diagnóstico local — 9D.6F

Erros agora exibem `Diagnostic: <codigo>` antes da mensagem genérica. O código representa a etapa, nunca a mensagem bruta, corpo ou URL do SDK. Não é gravado erro no manifesto. A preflight valida configuração/credenciais em memória antes de marcar a intenção, sem rede.

| Códigos | Significado |
| --- | --- |
| homologation_disabled / phase_invalid / run_invalid | Bloqueio local de entrada |
| manifest_directory_failed / manifest_lock_failed / manifest_read_failed / manifest_json_invalid / manifest_validation_failed | Problema local do manifesto, sem chamada nesta execução |
| preparation_configuration_invalid / manifest_save_failed | Preparação local não concluída |
| confirmation_mismatch / upload_state_invalid / verification_state_invalid / deletion_state_invalid | Confirmação ou estado não permite a operação |
| client_resolution_failed / credentials_missing / configuration_preflight_failed | Cliente ou configuração local; nenhuma chamada remota iniciada por essa fase |
| synthetic_image_write_failed / upload_intent_save_failed | Preparação local anterior ao upload |
| upload_remote_outcome_uncertain | A chamada ao cliente foi iniciada, mas seu resultado não foi confirmado; pode incluir falha de transporte ou outro erro local dentro do cliente |
| upload_remote_rejected | SDK reportou erro de API; não exibe causa ou conteúdo. Não autoriza nova tentativa automática |
| upload_response_invalid / upload_existing_asset_rejected | Houve resposta, mas não confirmou um novo recurso com os atributos esperados |
| upload_result_save_failed | O recurso foi validado, mas a confirmação local não foi salva |
| verification_remote_failed / verification_remote_rejected / verification_response_invalid / verification_result_save_failed | Falha na consulta ou na validação/gravação posterior |
| deletion_inspection_failed / deletion_inspection_rejected / deletion_identity_invalid / deletion_intent_save_failed | Exclusão não iniciada nesta fase, embora a consulta de identidade possa ter ocorrido |
| deletion_remote_outcome_uncertain / deletion_remote_rejected / deletion_response_invalid / deletion_result_save_failed | Exclusão chamada ou resultado não confirmado; manter manifesto para investigação |

O diagnóstico descreve a tentativa atual, não operações anteriores. O estado upload_attempted existente nunca é redefinido para reserved. Uma nova execução de upload nesse estado retorna upload_state_invalid, sem revelar o erro original. A escrita de intenção ocorre antes da chamada; portanto upload_attempted não prova que a requisição chegou ao servidor. Também pode haver falha local na construção do SDK durante a chamada. Não reduzir essas incertezas a uma causa presumida.

### Investigação proposta para execução com resultado incerto

A execução `e76f8d43906d905e5b4670b16d243d95` tem somente este identificador autorizado para eventual consulta:

`adoptplace/pets/homologation-e76f8d43906d905e5b4670b16d243d95/a93a7edf6fdcb57c51ec33bfdea2582e`

Nenhuma consulta real foi executada na 9D.6F. Após autorização específica, preferir uma consulta independente por `HomologationSdkClient::inspect` desse ID literal, lendo credenciais somente do processo, sem inicializar Laravel ou MongoDB e sem salvar o resultado no manifesto. O comando `verify` existente é somente de leitura no Cloudinary, mas modifica o manifesto local; portanto não deve ser usado se a autorização exigir preservar integralmente esse arquivo.

A consulta proposta não lista pastas ou recursos e não recebe um prefixo. Sua saída deve limitar-se a encontrado/ausente e verificações de igualdade do public_id, tipo image/upload, contexto da execução e asset_folder; não imprimir chaves, configuração, resposta bruta ou mensagens de exceção. Um resultado encontrado não autoriza exclusão. Erro de autenticação, transporte ou permissão não é equivalente a not found.

Se encontrado, comparar os metadados antes de autorizar qualquer ação posterior. Se ausente, registrar somente no relatório e decidir os próximos passos com o operador; não criar outra execução automaticamente. Preservar o manifesto original e não tentar novamente o upload. TLS deve continuar validado: não desativar verificação de certificados para contornar falhas.
