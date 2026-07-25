<?php
$env = file_get_contents(__DIR__ . '/../.env');
preg_match('/GOOGLE_API_KEY=(.+)/', $env, $m);
$key = trim($m[1] ?? '');

$models = ['gemini-1.5-flash', 'gemini-2.0-flash', 'gemini-1.5-pro'];

foreach ($models as $mod) {
    echo "=== Testing $mod ===\n";
    $url = "https://generativelanguage.googleapis.com/v1beta/models/$mod:generateContent?key=" . urlencode($key);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['contents' => [['parts' => [['text' => 'Hello']]]]]));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $res = curl_exec($ch);
    echo $res . "\n\n";
}
