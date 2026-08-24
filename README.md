# Sincronizador TalentLMS para Shopify

Script PHP de arquivo único que sincroniza a estrutura de um curso do
TalentLMS pra dentro do metafield de um produto Shopify, permitindo que
a loja renderize a grade curricular do curso (lista de unidades/aulas)
sem precisar chamar a API do TalentLMS a cada carregamento de página.

## Como funciona

1. Busca todo produto **ativo** do Shopify e mapeia pelo SKU da
   variante principal.
2. Busca todo curso do TalentLMS.
3. Casa um curso do TalentLMS com um produto Shopify quando o `code` do
   curso é igual ao SKU do produto (sem diferenciar maiúsculas).
4. Para cada correspondência, busca a lista completa de unidades do
   curso e grava como metafield JSON: `custom.course_units_structure`
   no produto correspondente (criando se não existir, atualizando se já
   existir).

Pensado pra rodar como job agendado (cron), mantendo a exibição da
grade curricular no Shopify sincronizada com o que está de fato
configurado no TalentLMS.

## Requisitos

- PHP 7.4+ com cURL
- Uma API key do TalentLMS
- Um token de acesso da Admin API do Shopify com escopos
  `read_products` e `write_products`

## Configuração

```bash
export TALENTLMS_DOMAIN="seu-dominio.talentlms.com"
export TALENTLMS_API_KEY="sua-api-key-do-talentlms"
export SHOPIFY_DOMAIN="sua-loja.myshopify.com"
export SHOPIFY_ACCESS_TOKEN="shpat_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
export SHOPIFY_API_VERSION="2025-07"   # opcional, padrão 2025-07
```

## Uso

```bash
php talentlms-shopify-sync.php
```

O progresso é registrado em detalhe em `sync_log.txt` ao lado do
script (o log é zerado a cada execução). Configure no cron pra rodar
periodicamente:

```cron
0 3 * * * php /caminho/para/talentlms-shopify-sync.php
```
