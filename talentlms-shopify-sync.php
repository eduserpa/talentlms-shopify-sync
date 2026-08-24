<?php
/**
 * Syncs TalentLMS course structure into Shopify product metafields.
 *
 * For every active Shopify product whose main variant SKU matches a
 * TalentLMS course code, this writes the course's unit list
 * (name + type per unit) into a `custom.course_units_structure` JSON
 * metafield on the product — so the storefront can render a course
 * curriculum/syllabus without a live TalentLMS call on every page view.
 *
 * Requirements: PHP 7.4+ with cURL
 *
 * Configuration (environment variables):
 *   TALENTLMS_DOMAIN      - e.g. your-domain.talentlms.com (required)
 *   TALENTLMS_API_KEY     - TalentLMS API key (required)
 *   SHOPIFY_DOMAIN        - e.g. your-store.myshopify.com (required)
 *   SHOPIFY_ACCESS_TOKEN  - Shopify Admin API token, shpat_... (required)
 *   SHOPIFY_API_VERSION   - e.g. 2025-07 (optional, has a default below)
 */

date_default_timezone_set('America/Sao_Paulo');
set_time_limit(1800);
ini_set('memory_limit', '512M');

error_reporting(E_ALL);
ini_set('display_errors', 1);

$logFile = __DIR__ . '/sync_log.txt';
file_put_contents($logFile, '');

function customEcho($message) {
    global $logFile;
    file_put_contents($logFile, $message, FILE_APPEND);
}

$TALENTLMS_DOMAIN = getenv('TALENTLMS_DOMAIN');
$TALENTLMS_API_KEY = getenv('TALENTLMS_API_KEY');
$SHOPIFY_DOMAIN = getenv('SHOPIFY_DOMAIN');
$SHOPIFY_ACCESS_TOKEN = getenv('SHOPIFY_ACCESS_TOKEN');
$SHOPIFY_API_VERSION = getenv('SHOPIFY_API_VERSION') ?: '2025-07';

if (!$TALENTLMS_DOMAIN || !$TALENTLMS_API_KEY || !$SHOPIFY_DOMAIN || !$SHOPIFY_ACCESS_TOKEN) {
    fwrite(STDERR, "Erro: defina TALENTLMS_DOMAIN, TALENTLMS_API_KEY, SHOPIFY_DOMAIN e SHOPIFY_ACCESS_TOKEN.\n");
    exit(1);
}

define('TALENTLMS_DOMAIN', $TALENTLMS_DOMAIN);
define('TALENTLMS_API_KEY', $TALENTLMS_API_KEY);
define('SHOPIFY_DOMAIN', $SHOPIFY_DOMAIN);
define('SHOPIFY_API_VERSION', $SHOPIFY_API_VERSION);
define('SHOPIFY_ACCESS_TOKEN', $SHOPIFY_ACCESS_TOKEN);

define('API_REQUEST_DELAY_MICROSECONDS', 500000);

define('SHOPIFY_METAFEILD_NAMESPACE', 'custom');
define('SHOPIFY_METAFEILD_KEY', 'course_units_structure');

function makeCurlRequest($url, $method = 'GET', $headers = [], $data = [], $useTalentLMSAuth = false) {
    customEcho("        [makeCurlRequest] Chamada para URL: {$url} | Metodo: {$method}\n");
    customEcho("        [makeCurlRequest] Headers enviados: " . json_encode($headers) . "\n");
    if (!empty($data)) {
        customEcho("        [makeCurlRequest] Dados enviados: " . json_encode($data) . "\n");
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HEADER, true);

    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    if ($useTalentLMSAuth) {
        $userPwd = TALENTLMS_API_KEY;
        if ($method == 'GET') {
            $userPwd .= ":";
        }
        curl_setopt($ch, CURLOPT_USERPWD, $userPwd);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    }

    if (!empty($data) && ($method == 'POST' || $method == 'PUT')) {
        $jsonData = json_encode($data);
        if ($jsonData === false) {
            customEcho("        [makeCurlRequest ERROR] Falha ao codificar dados para JSON para URL {$url}. Erro JSON: " . json_last_error_msg() . ". Dados: " . print_r($data, true) . "\n");
            return ['status' => 'error', 'message' => 'Falha ao codificar dados JSON para a requisicao.'];
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
        if (!in_array('Content-Type: application/json', $headers) && !in_array('content-type: application/json', array_map('strtolower', $headers))) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headerString = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);

    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        customEcho("        [makeCurlRequest ERROR] Erro cURL para URL {$url}: " . $error . "\n");
        return ['status' => 'error', 'message' => "Erro cURL: " . $error];
    }

    $decodedBody = json_decode($body, true);

    if ($body !== false && $decodedBody === null && json_last_error() !== JSON_ERROR_NONE) {
        customEcho("        [makeCurlRequest WARNING] Falha ao decodificar JSON da URL {$url}. Erro JSON: " . json_last_error_msg() . ". Resposta bruta do corpo: " . $body . "\n");
    }

    customEcho("        [makeCurlRequest] Resposta HTTP CODE: {$httpCode} | Resposta Bruta do Corpo: " . $body . "\n");

    if ($httpCode >= 400) {
        customEcho("        [makeCurlRequest ERROR] Erro HTTP {$httpCode} para URL {$url}. Resposta bruta: " . $body . "\n");
        return ['status' => 'error', 'message' => "Erro HTTP {$httpCode}: " . ($decodedBody['errors'] ?? ($decodedBody['message'] ?? $body)), 'http_code' => $httpCode, 'response' => $decodedBody];
    }

    $linkHeader = '';
    foreach (explode("\r\n", $headerString) as $headerLine) {
        if (stripos(trim($headerLine), 'Link:') === 0) {
            $linkHeader = trim(substr($headerLine, 5));
            break;
        }
    }

    return ['data' => $decodedBody, 'http_code' => $httpCode, 'link_header' => $linkHeader];
}

function getTalentLMSCourseList() {
    $url = "https://" . TALENTLMS_DOMAIN . "/api/v1/courses";
    customEcho("        [TalentLMS] Buscando lista de cursos em: {$url}\n");
    $response = makeCurlRequest($url, 'GET', [], [], true);
    return $response['data'];
}

function getTalentLMSCourseDetails($courseId) {
    $url = "https://" . TALENTLMS_DOMAIN . "/api/v1/courses/id:" . $courseId;
    customEcho("        [TalentLMS] Buscando detalhes do curso ID {$courseId} em: {$url}\n");
    $response = makeCurlRequest($url, 'GET', [], [], true);
    return $response['data'];
}

function getShopifyProductList($status = null) {
    $allProducts = [];
    $currentUrl = "https://" . SHOPIFY_DOMAIN . "/admin/api/" . SHOPIFY_API_VERSION . "/products.json";

    $fields = 'id,title,variants';

    $queryParams = [];

    if ($status !== null && $status !== '') {
        $queryParams[] = "status=" . urlencode($status);
    }
    $queryParams[] = "fields=" . urlencode($fields);

    if (!empty($queryParams)) {
        $currentUrl .= "?" . implode("&", $queryParams);
    }

    do {
        customEcho("[Shopify] Buscando lista de produtos em: {$currentUrl}\n");
        $headers = [
            'X-Shopify-Access-Token: ' . SHOPIFY_ACCESS_TOKEN
        ];

        $response = makeCurlRequest($currentUrl, 'GET', $headers);

        if (isset($response['status']) && $response['status'] === 'error') {
            customEcho("ERRO: Falha na requisicao Shopify: " . $response['message'] . "\n");
            break;
        }

        if (!isset($response['data']['products']) || !is_array($response['data']['products'])) {
            customEcho("ERRO: Nao foi possivel buscar produtos do Shopify ou resposta invalida na pagina. Detalhes: " . json_encode($response['data']) . "\n");
            break;
        }

        $allProducts = array_merge($allProducts, $response['data']['products']);

        $currentUrl = null;

        if (!empty($response['link_header'])) {
            preg_match('/<([^>]+)>;\s*rel="next"/', $response['link_header'], $matches);
            if (isset($matches[1])) {
                $currentUrl = $matches[1];
                customEcho("        [Shopify] Encontrado link 'next': {$currentUrl}\n");
            }
        }

        if ($currentUrl) {
            usleep(API_REQUEST_DELAY_MICROSECONDS);
        }

    } while ($currentUrl);

    return ['products' => $allProducts];
}

function updateShopifyProductMetafield($productId, $namespace, $key, $value, $type) {
    customEcho("        [Shopify Debug] Iniciando atualizacao de metafield para Product ID: {$productId}\n");
    $headers = [
        'Content-Type: application/json',
        'X-Shopify-Access-Token: ' . SHOPIFY_ACCESS_TOKEN
    ];

    $data = [
        'metafield' => [
            'namespace' => $namespace,
            'key'       => $key,
            'value'     => json_encode($value),
            'type'      => $type,
            'owner_id'  => (int)$productId,
            'owner_resource' => 'product'
        ]
    ];
    customEcho("        [Shopify Debug] Dados para metafield: " . json_encode($data) . "\n");

    $metafieldListUrl = "https://" . SHOPIFY_DOMAIN . "/admin/api/" . SHOPIFY_API_VERSION . "/products/" . $productId . "/metafields.json?" .
                        "namespace=" . urlencode($namespace) . "&key=" . urlencode($key);
    customEcho("        [Shopify Debug] Buscando metafield existente em URL: {$metafieldListUrl}\n");
    $existingMetafieldsResponse = makeCurlRequest($metafieldListUrl, 'GET', $headers);
    $existingMetafields = $existingMetafieldsResponse['data'] ?? null;

    customEcho("        [Shopify Debug] Resposta da busca por metafield existente: " . json_encode($existingMetafields) . "\n");

    $metafieldId = null;
    if (isset($existingMetafields['metafields']) && is_array($existingMetafields['metafields']) && !empty($existingMetafields['metafields'])) {
        foreach ($existingMetafields['metafields'] as $mf) {
            if (isset($mf['namespace']) && $mf['namespace'] === $namespace && isset($mf['key']) && $mf['key'] === $key) {
                $metafieldId = $mf['id'];
                customEcho("        [Shopify Debug] Metafield existente encontrado com ID: {$metafieldId}\n");
                break;
            }
        }
    } else {
        customEcho("        [Shopify Debug] Nenhum metafield existente encontrado ou erro na resposta da busca.\n");
    }

    $response = null;
    if ($metafieldId) {
        $updateUrl = "https://" . SHOPIFY_DOMAIN . "/admin/api/" . SHOPIFY_API_VERSION . "/metafields/" . $metafieldId . ".json";
        $data['metafield']['id'] = $metafieldId;
        customEcho("        [Shopify Debug] Metafield ID {$metafieldId} existe. Fazendo PUT para URL: {$updateUrl}\n");
        $response = makeCurlRequest($updateUrl, 'PUT', $headers, $data);
        customEcho("        [Shopify Debug] Resposta PUT: " . json_encode($response) . "\n");
    } else {
        $createUrl = "https://" . SHOPIFY_DOMAIN . "/admin/api/" . SHOPIFY_API_VERSION . "/products/" . $productId . "/metafields.json";
        customEcho("        [Shopify Debug] Metafield nao existe. Fazendo POST para URL: {$createUrl}\n");
        $response = makeCurlRequest($createUrl, 'POST', $headers, $data);
        customEcho("        [Shopify Debug] Resposta POST: " . json_encode($response) . "\n");
    }

    return $response['data'];
}

function formatUnitsToJsonArray($units) {
    $formattedUnits = [];
    if (empty($units)) {
        customEcho("        [formatUnitsToJsonArray] Nenhuma unidade encontrada para formatar.\n");
        return $formattedUnits;
    }

    foreach ($units as $unit) {
        $unitName = $unit['name'] ?? 'Nome Desconhecido';
        $unitType = $unit['type'] ?? 'Tipo Desconhecido';

        $formattedUnits[] = [
            'type' => $unitType,
            'name' => $unitName
        ];
        customEcho("        [formatUnitsToJsonArray] Unidade processada: Tipo='{$unitType}', Nome='{$unitName}'\n");
    }
    customEcho("        [formatUnitsToJsonArray] Total de unidades formatadas: " . count($formattedUnits) . "\n");
    return $formattedUnits;
}

customEcho("--------------------------------------------------------\n");
customEcho("Iniciando Sincronizacao TalentLMS <-> Shopify (Metafields)\n");
customEcho("Horario de inicio: " . date('Y-m-d H:i:s') . "\n");
customEcho("--------------------------------------------------------\n");

customEcho("[Principal] Obtendo produtos ATIVOS do Shopify...\n");
$shopifyProductsResponse = getShopifyProductList('active');
if (!isset($shopifyProductsResponse['products']) || !is_array($shopifyProductsResponse['products'])) {
    customEcho("ERRO FATAL: Nao foi possivel buscar produtos ATIVOS do Shopify ou resposta invalida. Detalhes: " . json_encode($shopifyProductsResponse) . "\n");
    exit(1);
}

$shopifyProductMap = [];
foreach ($shopifyProductsResponse['products'] as $product) {
    if (isset($product['variants'][0]['sku']) && !empty($product['variants'][0]['sku'])) {
        $sku = trim(strtoupper($product['variants'][0]['sku']));
        $shopifyProductMap[$sku] = $product['id'];
    } else {
        customEcho("    [Principal] Produto Shopify ID {$product['id']} ('{$product['title']}') ignorado: Nao possui SKU na variante principal.\n");
    }
}
customEcho("Produtos ATIVOS do Shopify mapeados por SKU: " . count($shopifyProductMap) . " encontrados.\n");
if (empty($shopifyProductMap)) {
    customEcho("Nenhum produto ativo com SKU valido encontrado no Shopify. Nenhuma sincronizacao sera realizada.\n");
    exit(0);
}

customEcho("[Principal] Obtendo lista de cursos do TalentLMS...\n");
$talentLMSCoursesResponse = getTalentLMSCourseList();

if (!is_array($talentLMSCoursesResponse) || empty($talentLMSCoursesResponse)) {
    customEcho("ERRO FATAL: Nao foi possivel buscar cursos do TalentLMS ou a lista esta vazia/invalida. Detalhes: " . json_encode($talentLMSCoursesResponse) . "\n");
    exit(1);
}
customEcho("Total de cursos encontrados no TalentLMS: " . count($talentLMSCoursesResponse) . "\n");

$syncedCount = 0;
foreach ($talentLMSCoursesResponse as $talentLMSCourse) {
    $courseId = $talentLMSCourse['id'];
    $courseCode = $talentLMSCourse['code'] ?? null;
    $courseName = $talentLMSCourse['name'] ?? 'Nome Desconhecido';

    $normalizedCourseCode = trim(strtoupper($courseCode));

    if (!empty($normalizedCourseCode) && isset($shopifyProductMap[$normalizedCourseCode])) {
        $shopifyProductId = $shopifyProductMap[$normalizedCourseCode];

        customEcho("\n--> Processando curso TalentLMS ID {$courseId} ('{$courseName}', Code: '{$courseCode}') para Shopify Product ID {$shopifyProductId}...\n");

        customEcho("    [Principal] Buscando detalhes do curso TalentLMS ID {$courseId}...\n");
        $detailedCourseResponse = getTalentLMSCourseDetails($courseId);

        if ($detailedCourseResponse && isset($detailedCourseResponse['id'])) {
            $courseDetails = $detailedCourseResponse;
            customEcho("    [Principal] Detalhes do curso TalentLMS ID {$courseId} obtidos com sucesso.\n");

            $unitsJsonArray = formatUnitsToJsonArray($courseDetails['units'] ?? []);

            customEcho("    [Principal] Chamando updateShopifyProductMetafield para Product ID {$shopifyProductId}...\n");
            $updateShopifyResponse = updateShopifyProductMetafield(
                $shopifyProductId,
                SHOPIFY_METAFEILD_NAMESPACE,
                SHOPIFY_METAFEILD_KEY,
                $unitsJsonArray,
                'json'
            );

            if ($updateShopifyResponse && isset($updateShopifyResponse['metafield'])) {
                customEcho("    >>> Metafield '" . SHOPIFY_METAFEILD_KEY . "' atualizado com sucesso para o curso {$courseId} (Shopify ID: {$shopifyProductId}).\n");
                customEcho("    >>> Metafield ID no Shopify: " . ($updateShopifyResponse['metafield']['id'] ?? 'N/A') . "\n");
                $syncedCount++;
            } else {
                customEcho("    !!! ERRO ao atualizar o metafield para o produto Shopify (ID: {$shopifyProductId}). Detalhes da resposta da Shopify: " . json_encode($updateShopifyResponse) . "\n");
            }
        } else {
            customEcho("    !!! ERRO ao obter detalhes completos do curso {$courseId} do TalentLMS ou detalhes incompletos. Detalhes: " . json_encode($detailedCourseResponse) . "\n");
        }
    } else {
        customEcho("    --- Curso TalentLMS ID {$courseId} ('{$courseName}', Code: '{$courseCode}') IGNORADO: Nenhum produto Shopify ATIVO encontrado com este SKU ou SKU vazio.\n");
    }

    usleep(API_REQUEST_DELAY_MICROSECONDS);
}

customEcho("\n--------------------------------------------------------\n");
customEcho("Sincronizacao Finalizada.\n");
customEcho("Total de cursos TalentLMS processados e Shopify atualizados: {$syncedCount}\n");
customEcho("Horario de termino: " . date('Y-m-d H:i:s') . "\n");
customEcho("--------------------------------------------------------\n");
