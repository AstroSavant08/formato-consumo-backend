<?php

$ch = curl_init('http://127.0.0.1:8000/api/v1/entregas');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode([
        'fecha' => '2026-07-21',
        'producto_id' => 6,
        'area_id' => 1,
        'cantidad' => 5,
        'unidad' => 'UND',
        'quien_recibe' => 'TEST FUENTE',
        'entregado_por' => 'TEST FUENTE',
        'fuente' => 'excel_historico',
    ]),
    CURLOPT_RETURNTRANSFER => true,
]);
$body = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP {$code}\n{$body}\n";
