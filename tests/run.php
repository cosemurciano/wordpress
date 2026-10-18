<?php
/**
 * Esegue tutte le prove standalone del plugin.
 *
 * Si esegue con: php tests/run.php
 *
 * Ogni file `test-*.php` di questa cartella gira in un processo suo: i test
 * dichiarano shim di WordPress con `function_exists`, e in un processo solo
 * il primo dichiarato vincerebbe su tutti gli altri — due file con bisogni
 * diversi si rovinerebbero a vicenda senza dirlo.
 *
 * Esce con 1 se anche una sola prova fallisce, così può stare in una
 * verifica automatica.
 *
 * @package AffiliateLinkManagerAI
 */

if ('cli' !== PHP_SAPI) {
    exit;
}

$runner = glob(__DIR__ . '/test-*.php');
sort($runner);

if (empty($runner)) {
    echo "Nessun runner trovato in " . __DIR__ . "\n";
    exit(1);
}

$falliti = array();
$php     = defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'php';

foreach ($runner as $file) {
    $nome = basename($file);

    echo "\n" . str_repeat('=', 52) . "\n";
    echo "  $nome\n";
    echo str_repeat('=', 52) . "\n";

    $uscita = 0;
    passthru(escapeshellarg($php) . ' ' . escapeshellarg($file), $uscita);

    if ($uscita !== 0) {
        $falliti[] = $nome;
    }
}

echo "\n" . str_repeat('=', 52) . "\n";

if (!empty($falliti)) {
    printf("%d runner su %d FALLITI: %s\n\n", count($falliti), count($runner), implode(', ', $falliti));
    exit(1);
}

printf("Tutti i %d runner eseguiti sono passati.\n\n", count($runner));
exit(0);
