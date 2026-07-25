<?php
$content = file_get_contents('dashboard.css');
$lines = explode("\n", $content);
foreach ($lines as $num => $line) {
    if (strpos($line, 'row') !== false || strpos($line, 'grid') !== false || strpos($line, 'flex') !== false) {
        echo "Line " . ($num + 1) . ": " . trim($line) . "\n";
    }
}
?>
