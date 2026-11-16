<?php
/**
 * ww_config.php – settings for index.php (Pixel Art).
 *
 * Keep this file next to index.php. It only returns an array, so it prints nothing
 * if someone requests it directly, but it is still best kept out of version control
 * (or outside the web root, with the path in index.php adjusted).
 */
if (basename((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) {
    http_response_code(403);                // not meant to be opened directly
    exit;
}

return [
    /* ---- MySQL ---- */
    'db_host'    => '127.0.0.1',
    'db_port'    => 3306,
    'db_name'    => 'pixelart',      // the database must already exist
    'db_user'    => 'root',
    'db_pass'    => '',
    'db_charset' => 'utf8mb4',
    'db_table'   => 'pixel_art',     // created automatically on the first save (letters, digits, _ only)

    /* ---- Boss ---- */
    'boss_code'  => 'changeme',      // CHANGE THIS
];
