<?php

define('ENDPOINT_DESTINO', 'https://localhost:7154/api/Pix/callback');
define('LOG_FILE', __DIR__ . '/webhook_pix_recebidos.log');

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método não permitido. Use POST."]);
    exit;
}

// Lê o JSON recebido
$rawJson = file_get_contents('php://input');

// Valida se veio vazio
if (empty($rawJson)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Corpo da requisição vazio."]);
    exit;
}

// Log original para auditoria
file_put_contents(LOG_FILE, "[" . date("Y-m-d H:i:s") . "] " . $rawJson . PHP_EOL, FILE_APPEND);

// Decodifica o JSON para array
$data = json_decode($rawJson, true);

// Se falhar, JSON inválido
if ($data === null) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "JSON inválido."]);
    exit;
}

/*
 Redirecionamento com renomeação de campos:
 - e2e → e2d
 - Mantém apenas os campos necessários
*/

$payloadToSend = [
    "typeTransaction"   => $data["typeTransaction"] ?? "",
    "statusTransaction" => $data["statusTransaction"] ?? "",
    "idTransaction"     => $data["idTransaction"] ?? "",
    "e2d"               => $data["e2e"] ?? "", // renomeado aqui
    "paid_by"           => $data["paid_by"] ?? "",
    "paid_doc"          => $data["paid_doc"] ?? ""
];

// Envia via cURL para API de destino
$ch = curl_init(ENDPOINT_DESTINO);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payloadToSend));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Accept: application/json'
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Resposta ao originador
echo json_encode([
    "status" => "ok",
    "forward_status" => $httpCode,
    "payload_enviado" => $payloadToSend,
    "callback_response" => $response
]);
