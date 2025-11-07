<?php
/**
 * Webhook Payment Receiver and Forwarder
 * 
 * Este script recebe webhooks de pagamento via POST e os encaminha
 * para um endpoint específico.
 * 
 * Requisitos:
 * - Servidor Apache com SSL ativo
 * - PHP 7.4 ou superior
 * - Extensão cURL habilitada
 */

// Configurações
// Para serviço .NET no Docker no mesmo servidor:
// Opção 1: Se o container expõe uma porta (ex: 5000)
define('ENDPOINT_DESTINO', 'http://localhost:7154/api/Pix/callback');

// Opção 2: Se usa nome do container na rede Docker
// define('ENDPOINT_DESTINO', 'http://nome-container-dotnet:80/api/webhook');

// Opção 3: Se usa domínio/proxy reverso
// define('ENDPOINT_DESTINO', 'https://seu-dominio.com/api/webhook');

define('LOG_FILE', __DIR__ . '/webhook_logs.txt');
define('SECRET_TOKEN', 'seu_token_secreto_aqui'); // Token de autenticação (opcional)

// Headers para resposta JSON
header('Content-Type: application/json; charset=utf-8');

/**
 * Função para receber o webhook de pagamento via POST
 * 
 * @return array|null Dados do webhook recebido ou null em caso de erro
 */
function receberWebhookPagamento() {
    // Verificar se é uma requisição POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'status' => 'error',
            'message' => 'Método não permitido. Use POST.'
        ]);
        return null;
    }

    // Capturar o corpo da requisição
    $jsonData = file_get_contents('php://input');
    
    if (empty($jsonData)) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Corpo da requisição vazio.'
        ]);
        return null;
    }

    // Decodificar JSON
    $webhookData = json_decode($jsonData, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'JSON inválido: ' . json_last_error_msg()
        ]);
        return null;
    }

    // Validar campos obrigatórios
    $camposObrigatorios = ['typeTransaction', 'statusTransaction', 'idTransaction'];
    foreach ($camposObrigatorios as $campo) {
        if (!isset($webhookData[$campo])) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => "Campo obrigatório ausente: {$campo}"
            ]);
            return null;
        }
    }

    // Log do webhook recebido
    logarEvento('WEBHOOK_RECEBIDO', $webhookData);

    return $webhookData;
}

/**
 * Função para enviar o webhook para o endpoint de destino
 * 
 * @param array $webhookData Dados do webhook a serem enviados
 * @return bool Sucesso ou falha no envio
 */
function enviarWebhookParaEndpoint($webhookData) {
    // Inicializar cURL
    $ch = curl_init(ENDPOINT_DESTINO);
    
    // Configurar opções do cURL
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($webhookData),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . SECRET_TOKEN, // Token de autenticação
            'User-Agent: WebhookForwarder/1.0'
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        // Para localhost/Docker no mesmo servidor, pode desabilitar verificação SSL
        CURLOPT_SSL_VERIFYPEER => false, // Mude para true se usar HTTPS externo
        CURLOPT_SSL_VERIFYHOST => 0, // Mude para 2 se usar HTTPS externo
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3
    ]);

    // Executar requisição
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    
    curl_close($ch);

    // Verificar se houve erro
    if ($response === false) {
        logarEvento('ERRO_ENVIO', [
            'error' => $curlError,
            'webhook_id' => $webhookData['idTransaction'] ?? 'unknown'
        ]);
        
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Erro ao enviar webhook para o endpoint de destino.',
            'details' => $curlError
        ]);
        return false;
    }

    // Verificar código HTTP da resposta
    if ($httpCode >= 200 && $httpCode < 300) {
        logarEvento('WEBHOOK_ENVIADO', [
            'webhook_id' => $webhookData['idTransaction'],
            'http_code' => $httpCode,
            'response' => $response
        ]);
        
        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'message' => 'Webhook recebido e encaminhado com sucesso.',
            'transaction_id' => $webhookData['idTransaction']
        ]);
        return true;
    } else {
        logarEvento('ERRO_HTTP', [
            'webhook_id' => $webhookData['idTransaction'],
            'http_code' => $httpCode,
            'response' => $response
        ]);
        
        http_response_code(502);
        echo json_encode([
            'status' => 'error',
            'message' => 'Endpoint de destino retornou erro.',
            'http_code' => $httpCode
        ]);
        return false;
    }
}

/**
 * Função auxiliar para registrar eventos em log
 * 
 * @param string $tipo Tipo do evento
 * @param array $dados Dados do evento
 */
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

// ========================================
// EXECUÇÃO PRINCIPAL
// ========================================

try {
    // 1. Receber o webhook de pagamento
    $webhookData = receberWebhookPagamento();
    
    if ($webhookData === null) {
        exit; // Erro já foi tratado na função
    }

    // 2. Enviar o webhook para o endpoint de destino
    enviarWebhookParaEndpoint($webhookData);
    
} catch (Exception $e) {
    logarEvento('ERRO_EXCEPTION', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Erro interno do servidor.'
    ]);
}
?>