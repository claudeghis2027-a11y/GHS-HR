<?php
declare(strict_types=1);
// Daily: deletes face reference photos + embeddings of employees whose service has ended, and stale temp files.
//   php bin/face_cleanup.php      (run as tarek via Hestia cron)
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/../app/bootstrap.php';
$n = face_purge_ended();
foreach (glob(config('storage_dir') . '/tmp/*') ?: [] as $f) if (is_file($f) && filemtime($f) < time() - 3600) @unlink($f);
echo date('c') . " face_cleanup: purged $n profile(s)\n";
