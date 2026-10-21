<?php

declare(strict_types=1);

/*
 * Testler için tek bağlantılık sahte IRRd sunucusu.
 *
 * 127.0.0.1'de rastgele bir porta bağlanır, portu stdout'a yazar, tek
 * istemciyi kabul eder, "!q" görene kadar okur ve argümanla verilen yanıtı
 * döndürüp kapatır. Gerçek WhoisIrrdTransport sınıfı böylece kendi soketiyle,
 * kendi çerçevelemesiyle sınanabiliyor — dışarı çıkmadan.
 *
 * Okunan komutlar yanıttan sonra stdout'a basılır; test "!r" kullanıldığını
 * (tam eşleşme) oradan doğrular.
 */

$response = base64_decode($argv[1], true);
$server = stream_socket_server('tcp://127.0.0.1:0', $code, $message);

if ($server === false) {
    fwrite(STDERR, "dinlenemedi: {$message}\n");
    exit(1);
}

$address = stream_socket_get_name($server, false);
fwrite(STDOUT, substr($address, strrpos($address, ':') + 1)."\n");

$client = stream_socket_accept($server, 10);

if ($client === false) {
    exit(1);
}

stream_set_timeout($client, 5);
$request = '';

while (! str_contains($request, "!q\n")) {
    $chunk = fread($client, 4096);

    if ($chunk === false || $chunk === '') {
        break;
    }

    $request .= $chunk;
}

fwrite($client, $response);
fclose($client);
fclose($server);

fwrite(STDOUT, base64_encode($request)."\n");
