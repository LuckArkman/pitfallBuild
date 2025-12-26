<?php

// Configurações de Destino
define('ENDPOINT_DESTINO', 'https://localhost:7154/api/Pix/callback');
define('LOG_FILE', __DIR__ . '/webhook_logs.txt');
define('SECRET_TOKEN', 'seu_token_secreto_aqui'); // Ajuste conforme necessário

header('Content-Type: application/json; charset=utf-8');

/**
 * Função para registrar eventos no arquivo TXT para controle
 */
function logarEvento($tipo, $dados) {
    $timestamp = date('d/m/Y H:i:s');
    $conteudo = is_array($dados) ? json_encode($dados, JSON_UNESCAPED_UNICODE) : $dados;
    $logEntry = "[$timestamp] $tipo: $conteudo\n";
    file_put_contents(LOG_FILE, $logEntry, FILE_APPEND | LOCK_EX);
}

/**
 * Processa o Webhook e encaminha para a API interna
 */
function processarERedirecionar() {
    // 1. Captura o corpo bruto enviado pelo Webhook
    $rawData = file_get_contents('php://input');
    
    if (empty($rawData)) {
        logarEvento('ERRO', 'Corpo da requisição vazio.');
        http_response_code(400);
        return;
    }

    // 2. Decodifica o JSON recebido (conforme estrutura do log )
    $webhookData = json_decode($rawData, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        logarEvento('ERRO_JSON', json_last_error_msg());
        http_response_code(400);
        return;
    }

    // 3. Mapeia os parâmetros conforme solicitado e visto na documentação
    // Baseado no log recebido: status, idTransaction e typeTransaction 
    $payloadParaAPI = [
        'status'          => $webhookData['status'] ?? 'unknown',
        'idTransaction'   => $webhookData['idTransaction'] ?? '',
        'typeTransaction' => $webhookData['typeTransaction'] ?? ''
    ];

    // 4. Envia para o servidor interno via cURL
    $ch = curl_init(ENDPOINT_DESTINO);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payloadParaAPI),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . SECRET_TOKEN,
        ],
        CURLOPT_SSL_VERIFYPEER => false, // Necessário para localhost/auto-assinado
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // 5. Loga o resultado do encaminhamento para conferência
    logarEvento('ENCAMINHAMENTO', [
        'payload_enviado' => $payloadParaAPI,
        'http_code_destino' => $httpCode,
        'resposta_destino' => $response
    ]);

    // Responde ao emissor original
    http_response_code(200);
    echo json_encode(['status' => 'success', 'forwarded' => true]);
}

// Execução
try {
    processarERedirecionar();
} catch (Exception $e) {
    logarEvento('ERRO_FATAL', $e->getMessage());
}