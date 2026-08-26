<?php
$db = file_get_contents('database.sql');
$sge = file_get_contents('sge_utf8.sql');
$auto = file_get_contents('libs/auto_migrate.php');

preg_match_all('/`([^`]+)`\s+[A-Za-z]+/', $sge, $sge_cols);
$sge_cols = array_unique($sge_cols[1]);

foreach ($sge_cols as $col) {
    if ($col === 'id' || $col === 'created_at' || $col === 'updated_at') continue;
    
    // check if it's in database.sql
    if (strpos($db, "`$col`") === false) {
        // it's not in database.sql. Is it in auto_migrate?
        if (strpos($auto, "'$col'") === false && strpos($auto, "`$col`") === false && strpos($auto, "c'=>'$col'") === false) {
            echo "MISSING COL: $col\n";
        }
    }
}
echo "DONE\n";
