<?php
$file = "sample.txt";
$command = escapeshellcmd("python3 tokenize.py $file");
$output = shell_exec($command);
$result = json_decode($output, true);

echo "File: " . $result["file"] . PHP_EOL;
echo "Token count: " . $result["token_count"] . PHP_EOL;
echo "First tokens:" . PHP_EOL;
foreach ($result["decoded"] as $i => $tok) {
    echo str_pad($i, 3, " ") . " → '$tok'" . PHP_EOL;
}
?>