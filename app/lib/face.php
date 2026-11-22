<?php
declare(strict_types=1);

/*
 * Face verification: consent, enrollment (pending -> super-admin approval), matching at check-in,
 * failure streaks -> HR review, purge at end of service. Frames are never written to disk: they
 * travel in the POST body to the local face service and are dropped after the comparison.
 * Reference photos and embeddings are encrypted at rest with FACE_KEY (libsodium secretbox).
 */

function face_enabled(): bool { return setting('face_enabled', '1') === '1'; }

function face_key(): string
{
    $k = base64_decode((string)(config('face')['key'] ?? ''), true);
    if ($k === false || strlen($k) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) throw new RuntimeException('FACE_KEY missing/invalid');
    return $k;
}

function face_encrypt(string $plain): string
{
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return $nonce . sodium_crypto_secretbox($plain, $nonce, face_key());
}

function face_decrypt(string $blob): ?string
{
    if (strlen($blob) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return null;
    $p = sodium_crypto_secretbox_open(substr($blob, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($blob, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), face_key());
    return $p === false ? null : $p;
}

function face_dir(string $sub): string
{
    $d = config('storage_dir') . '/faces/' . $sub;
    if (!is_dir($d) && !@mkdir($d, 0770, true) && !is_dir($d)) throw new RuntimeException('face storage not writable');
    return $d;
}

/** Writes an encrypted file under storage/faces/<sub>/ and returns its relative name. */
function face_store_file(string $sub, string $bytes): string
{
    $name = $sub . '/' . bin2hex(random_bytes(16)) . '.bin';
    face_dir($sub);
    // Never record a profile whose photo was not actually stored.
    if (@file_put_contents(config('storage_dir') . '/faces/' . $name, face_encrypt($bytes), LOCK_EX) === false) {
        throw new RuntimeException('face storage not writable');
    }
    return $name;
}

function face_read_file(?string $rel): ?string
{
    if (!$rel || !preg_match('#^[a-z0-9]+/[a-f0-9]{32}\.bin$#', $rel)) return null;
    $f = config('storage_dir') . '/faces/' . $rel;
    return is_file($f) ? face_decrypt((string)file_get_contents($f)) : null;
}

function face_delete_file(?string $rel): void
{
    if (!$rel || !preg_match('#^[a-z0-9]+/[a-f0-9]{32}\.bin$#', $rel)) return;
    $f = config('storage_dir') . '/faces/' . $rel;
    if (is_file($f)) { // overwrite before unlink so the ciphertext doesn't linger in free blocks
        $fh = fopen($f, 'r+');
        if ($fh) { fwrite($fh, random_bytes((int)filesize($f))); fflush($fh); fclose($fh); }
        unlink($f);
    }
}

// ───────────── Service client ─────────────
function face_params(): array
{
    return [
        'match_threshold' => (float)setting('face_match_threshold', '0.40'),
        'min_yaw_range'   => (float)setting('face_min_yaw_range', '0.25'),
    ];
}

/** Calls the local face service. Returns decoded JSON or ['ok'=>false,'code'=>'service',...]. */
/** True when the face service answers GET /health (for the settings server check). */
function face_health(): bool
{
    $cfg = config('face') ?? [];
    $ch = curl_init();
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
        CURLOPT_HTTPHEADER => ['X-Face-Token: ' . ($cfg['token'] ?? '')]];
    if (!empty($cfg['socket'])) { $opts[CURLOPT_UNIX_SOCKET_PATH] = $cfg['socket']; $opts[CURLOPT_URL] = 'http://localhost/health'; }
    else { $opts[CURLOPT_URL] = rtrim((string)($cfg['url'] ?? 'http://127.0.0.1:8765'), '/') . '/health'; }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    curl_close($ch);
    $res = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($res) && !empty($res['ok']);
}

function face_call(string $path, array $body): array
{
    $cfg = config('face') ?? [];
    $ch = curl_init();
    $opts = [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 15, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Face-Token: ' . ($cfg['token'] ?? '')],
    ];
    if (!empty($cfg['socket'])) { $opts[CURLOPT_UNIX_SOCKET_PATH] = $cfg['socket']; $opts[CURLOPT_URL] = 'http://localhost' . $path; }
    else { $opts[CURLOPT_URL] = rtrim((string)($cfg['url'] ?? 'http://127.0.0.1:8765'), '/') . $path; }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $err = curl_errno($ch);
    curl_close($ch);
    $res = is_string($raw) ? json_decode($raw, true) : null;
    if ($err || !is_array($res)) {
        error_log('face service unreachable (curl ' . $err . ')');
        return ['ok' => false, 'code' => 'service', 'message' => __('خدمة التحقق من الوجه مش متاحة دلوقتي. جرّب بعد شوية أو كلّم الإدارة.')];
    }
    return $res;
}

/** Frames posted by the camera widget: JSON array of base64 JPEGs (data-URL prefix stripped). */
function face_frames_from_post(): ?array
{
    $raw = $_POST['frames'] ?? '';
    if (!is_string($raw) || strlen($raw) > 7_000_000) return null;
    $arr = json_decode($raw, true);
    if (!is_array($arr) || count($arr) < 4 || count($arr) > 8) return null;
    $out = [];
    foreach ($arr as $f) {
        if (!is_string($f) || !preg_match('#^data:image/jpeg;base64,([A-Za-z0-9+/=]+)$#', $f, $m)) return null;
        $out[] = $m[1];
    }
    return $out;
}

/** User-facing message for a face-service result, in the current UI language. */
function face_message(array $r): string
{
    static $m = [
        'service' => 'خدمة التحقق من الوجه مش متاحة دلوقتي. جرّب بعد شوية أو كلّم الإدارة.',
        'busy' => 'الخدمة مشغولة دلوقتي. استنى ثواني وجرّب تاني.',
        'bad_request' => 'طلب غير صالح.', 'few_frames' => 'مفيش لقطات كفاية. جرّب تاني.', 'bad_image' => 'اللقطة مش صورة صالحة.',
        'no_face' => 'مفيش وش واضح في الكاميرا. خلي وشك كله جوه الإطار.', 'multi_face' => 'فيه أكتر من وش في الصورة. لازم تكون لوحدك قدام الكاميرا.',
        'too_small' => 'وشك صغير أو بعيد. قرّب من الكاميرا.', 'too_dark' => 'الإضاءة ضعيفة. قف في مكان منوّر.',
        'too_bright' => 'الإضاءة قوية جداً أو فيه نور ورا وشك. غيّر مكانك.', 'blurry' => 'الصورة مش واضحة (مهزوزة). اثبت ونضّف الكاميرا.',
        'not_frontal' => 'بص للكاميرا على طول في أول لقطة.', 'no_motion' => 'مفيش حركة. لازم تلف راسك يمين وشمال لما يطلب منك.',
        'no_turn' => 'ملفّتش راسك كفاية. لف راسك يمين وشمال براحة.', 'inconsistent' => 'الوش اتغير في نص اللقطات. جرّب تاني لوحدك.',
        'no_reference' => 'مفيش صورة مرجعية.',
    ];
    $code = (string)($r['code'] ?? '');
    return __($m[$code] ?? 'الصورة مش مناسبة.');
}

// ───────────── Consent / profile state ─────────────
function face_has_consent(int $empId): bool
{
    return (bool)q_val('SELECT 1 FROM face_consents WHERE employee_id = ? AND consent_version = ?', [$empId, (int)setting('face_consent_version', '1')]);
}

/** Profile used for matching: approved one if any, otherwise the pending one. */
function face_active_profile(int $empId): ?array
{
    return q_one("SELECT * FROM face_profiles WHERE employee_id = ? AND status IN ('approved','pending') AND embedding_enc IS NOT NULL
                  ORDER BY status = 'approved' DESC, id DESC LIMIT 1", [$empId]);
}

function face_pending_profile(int $empId): ?array
{
    return q_one("SELECT * FROM face_profiles WHERE employee_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1", [$empId]);
}

/** Where the employee must go before using attendance, or null when ready. */
function face_gate_route(int $empId): ?string
{
    if (!face_enabled()) return null;
    if (!face_has_consent($empId)) return 'face_consent';
    if (!face_active_profile($empId)) return 'face_enroll';
    return null;
}

function face_notify(int $empId, string $title, string $body): void
{
    q("INSERT INTO notifications (ntype, title, body, show_at, duration_min, scope, scope_value, status, created_by) VALUES ('تنبيه', ?, ?, NOW(), 10080, 'employee', ?, 'scheduled', ?)",
        [$title, $body, (string)$empId, user()['username'] ?? 'system']);
    $nid = (int)db()->lastInsertId();
    q('INSERT INTO notification_targets (notification_id, employee_id, show_at, hide_at) VALUES (?, ?, NOW(), NOW() + INTERVAL 7 DAY)', [$nid, $empId]);
}

/** Wipes a profile's photo + embedding and marks it with $status. */
function face_wipe_profile(array $p, string $status, string $reason): void
{
    face_delete_file($p['photo_file']);
    q('UPDATE face_profiles SET status = ?, photo_file = NULL, embedding_enc = NULL, deleted_at = NOW(), delete_reason = ? WHERE id = ?', [$status, $reason, $p['id']]);
}

/** End of service: delete every reference photo and embedding of the employee. */
function face_purge_employee(int $empId, string $reason): int
{
    $n = 0;
    foreach (q_all("SELECT * FROM face_profiles WHERE employee_id = ? AND (photo_file IS NOT NULL OR embedding_enc IS NOT NULL)", [$empId]) as $p) {
        face_wipe_profile($p, $p['status'] === 'rejected' ? 'rejected' : 'deleted', $reason);
        $n++;
    }
    if ($n) audit('face.purge', $empId, ['profiles' => $n, 'reason' => $reason]);
    return $n;
}

function face_purge_ended(): int
{
    $n = 0;
    foreach (q_all("SELECT DISTINCT e.id FROM employees e JOIN face_profiles p ON p.employee_id = e.id
                    WHERE e.end_date IS NOT NULL AND e.end_date <= CURDATE() AND (p.photo_file IS NOT NULL OR p.embedding_enc IS NOT NULL)") as $r) {
        $n += face_purge_employee((int)$r['id'], 'end_of_service');
    }
    return $n;
}

// ───────────── Failure streak ─────────────
function face_streak(int $empId): array
{
    $r = q_one('SELECT face_fail_streak, face_review_status FROM employee_review_state WHERE employee_id = ?', [$empId]);
    return ['streak' => (int)($r['face_fail_streak'] ?? 0), 'status' => $r['face_review_status'] ?? null];
}

/** Coarse device label for tuning stats (no full user agent stored). */
function face_device(): string
{
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $os = preg_match('/iPhone|iPad|iPod/i', $ua) ? 'iOS' : (preg_match('/Android/i', $ua) ? 'Android' : (preg_match('/Windows|Macintosh|Linux/i', $ua) ? 'Desktop' : 'Other'));
    $br = preg_match('/Edg/i', $ua) ? 'Edge' : (preg_match('/CriOS|Chrome/i', $ua) ? 'Chrome' : (preg_match('/FxiOS|Firefox/i', $ua) ? 'Firefox' : (preg_match('/Safari/i', $ua) ? 'Safari' : 'Other')));
    return "$os/$br";
}

/** Logs one attempt (enroll / in / out) with its measurements; $counted = part of the failure streak. */
function face_log(int $empId, string $kind, string $result, ?float $score, array $metrics, bool $counted = false, ?int $streak = null): void
{
    $m = array_intersect_key($metrics, array_flip(['yaw_range', 'motion', 'brightness', 'sharpness', 'same_person', 'ms', 'frames']));
    q('INSERT INTO face_match_log (employee_id, at, result, distance, streak, counted, kind, device, metrics) VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?)',
        [$empId, $result, $score, $streak, $counted ? 1 : 0, $kind, face_device(), json_encode($m)]);
}

function face_record_result(int $empId, bool $ok, ?float $score, string $result, string $kind = '', array $metrics = []): array
{
    $max = max(1, (int)setting('face_max_failures', '3'));
    $s = face_streak($empId);
    $streak = $ok ? 0 : $s['streak'] + 1;
    $status = $s['status'];
    if (!$ok && $streak >= $max) $status = 'hr_review';
    q('INSERT INTO employee_review_state (employee_id, face_fail_streak, face_review_status, updated_at) VALUES (?,?,?,NOW())
       ON DUPLICATE KEY UPDATE face_fail_streak = VALUES(face_fail_streak), face_review_status = VALUES(face_review_status), updated_at = NOW()', [$empId, $streak, $status]);
    face_log($empId, $kind, $result, $score, $metrics, !$ok, $streak);
    if (!$ok && $streak === $max) audit('face.hr_review', $empId, ['streak' => $streak]);
    return ['streak' => $streak, 'status' => $status, 'max' => $max];
}
