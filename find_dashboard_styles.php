<?php
$content = file_get_contents('dashboard.css');
$lines = explode("\n", $content);
foreach ($lines as $num => $line) {
    if (strpos($line, 'form-row') !== false || strpos($line, 'form-group') !== false) {
        echo "Line " . ($num + 1) . ": " . trim($line) . "\n";
    }
}
?>
