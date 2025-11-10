<?php

define('ENDPOINT_DESTINO', 'https://localhost:7154/api/Pix/callback');
define('LOG_FILE', __DIR__ . '/webhook_logs.txt');
define('SECRET_TOKEN', 'seu_token_secreto_aqui');
header('Content-Type: application/json; charset=utf-8');

/**
 * Recebe e valida o webhook no formato original de entrada.
 */
function receberWebhookPagamento() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => 'Método não permitido. Use POST.']);
        return null;
    }

    $jsonData = file_get_contents('php://input');
    if (empty($jsonData)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Corpo da requisição vazio.']);
        return null;
    }

    $webhookData = json_decode($jsonData, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'JSON inválido: ' . json_last_error_msg()]);
        return null;
    }

    // Valida os campos do formulário ORIGINAL recebido.
    $camposObrigatorios = ['idTransaction', 'typeTransaction', 'statusTransaction', 'e2e', 'paid_by', 'paid_doc', 'ispb'];
    foreach ($camposObrigatorios as $campo) {
        if (!isset($webhookData[$campo])) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => "Campo obrigatório ausente no webhook de origem: {$campo}"]);
            return null;
        }
    }

    logarEvento('WEBHOOK_RECEBIDO', $webhookData);
    return $webhookData;
}

/**
 * Transforma os dados recebidos para o novo formato e os envia ao endpoint de destino.
 */
function enviarWebhookParaEndpoint($webhookData) {
    // --- ALTERAÇÃO REALIZADA AQUI ---
    // 1. Cria um novo array (payload) com a estrutura de saída desejada.
    $payloadParaEnvio = [
        'typeTransaction'   => $webhookData['typeTransaction'],
        'statusTransaction' => $webhookData['statusTransaction'],
        'idTransaction'     => $webhookData['idTransaction'],
        'e2d'               => $webhookData['e2e'], // Mapeia o valor de 'e2e' para o campo 'e2d'.
        'paid_by'           => $webhookData['paid_by'],
        'paid_doc'          => $webhookData['paid_doc']
        // O campo 'ispb' é intencionalmente omitido.
    ];

    $ch = curl_init(ENDPOINT_DESTINO);
    
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        // 2. Codifica e envia o NOVO payload formatado.
        CURLOPT_POSTFIELDS => json_encode($payloadParaEnvio),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . SECRET_TOKEN,
            'User-Agent: WebhookForwarder/1.0'
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        logarEvento('ERRO_ENVIO', ['error' => $curlError, 'webhook_id' => $webhookData['idTransaction'] ?? 'unknown']);
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Erro ao enviar webhook para o endpoint de destino.', 'details' => $curlError]);
        return false;
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        logarEvento('WEBHOOK_ENVIADO', ['webhook_id' => $webhookData['idTransaction'], 'http_code' => $httpCode, 'response' => $response]);
        http_response_code(200);
        echo json_encode(['status' => 'success', 'message' => 'Webhook recebido, transformado e encaminhado com sucesso.', 'transaction_id' => $webhookData['idTransaction']]);
        return true;
    } else {
        logarEvento('ERRO_HTTP', ['webhook_id' => $webhookData['idTransaction'], 'http_code' => $httpCode, 'response' => $response]);
        http_response_code(502);
        echo json_encode(['status' => 'error', 'message' => 'Endpoint de destino retornou erro.', 'http_code' => $httpCode]);
        return false;
    }
}

function logarEvento($tipo, $dados) {
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = sprintf(
        "[%s] %s: %s\n",
        $timestamp,
        $tipo,
        json_encode($dados, JSON_UNESCAPED_UNICODE)
    );
    file_put_contents(LOG_FILE, $logEntry, FILE_APPEND | LOCK_EX);
}

try {
    $webhookData = receberWebhookPagamento();
    if ($webhookData !== null) {
        enviarWebhookParaEndpoint($webhookData);
    }
} catch (Exception $e) {
    logarEvento('ERRO_EXCEPTION', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Erro interno do servidor.']);
}
?>