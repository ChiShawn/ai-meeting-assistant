<?php
// proxy.php (Shell-Exec Version)
// Uses system curl binary to bypass PHP limitation

$SOCKET_PATH = __DIR__ . "/meeting.sock";
set_time_limit(3600); // Allow long polling
ini_set('memory_limit', '1024M'); // Ensure memory for large response
$path = $_GET['path'] ?? ''; // e.g., /health
$method = $_SERVER['REQUEST_METHOD'];

function emit_curl_response($output) {
    if ($output === null) {
        http_response_code(500);
        echo "Shell Exec Failed";
        return;
    }

    $parts = explode("\r\n\r\n", $output, 2);
    $headerPart = $parts[0] ?? '';
    $bodyPart = $parts[1] ?? '';

    if (strpos($headerPart, "100 Continue") !== false) {
        $parts = explode("\r\n\r\n", $bodyPart, 2);
        $headerPart = $parts[0] ?? '';
        $bodyPart = $parts[1] ?? '';
    }

    $lines = explode("\r\n", $headerPart);
    foreach ($lines as $line) {
        if (stripos($line, 'HTTP/') === 0) {
            $code = explode(' ', $line)[1] ?? 200;
            http_response_code((int)$code);
        } else if (strpos($line, ':') !== false) {
            header($line);
        }
    }

    echo $bodyPart;
}

function json_error($message, $code = 400) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(["error" => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function safe_upload_filename($filename) {
    $name = basename($filename ?: "upload.audio");
    $name = preg_replace('/[^\p{L}\p{N}_. -]+/u', '_', $name);
    $name = str_replace([';', "\r", "\n"], '_', $name);
    return $name ?: "upload.audio";
}

if (!$path) {
    http_response_code(400);
    echo "Missing path";
    exit;
}

// DEBUG: Log FILES and POST field lengths (no content)
$postLengths = [];
foreach ($_POST as $k => $v) {
    $postLengths[$k] = strlen($v);
}
file_put_contents(__DIR__ . '/proxy_debug.log',
    "CONTENT_LENGTH=" . ($_SERVER['CONTENT_LENGTH'] ?? 'N/A') . "\n"
    . "POST_LENGTHS=" . json_encode($postLengths) . "\n"
    . print_r($_FILES, true) . "\n",
    FILE_APPEND);

$uploadErrors = [
    UPLOAD_ERR_INI_SIZE => "上傳檔案超過伺服器限制。",
    UPLOAD_ERR_FORM_SIZE => "上傳檔案超過表單限制。",
    UPLOAD_ERR_PARTIAL => "檔案只上傳了一部分（可能是檔案太大、上傳途中網路中斷，或伺服器上傳容量限制）。請壓縮音檔或切短後重新上傳。",
    UPLOAD_ERR_NO_FILE => "沒有收到上傳檔案。",
    UPLOAD_ERR_NO_TMP_DIR => "伺服器暫存目錄不存在。",
    UPLOAD_ERR_CANT_WRITE => "伺服器無法寫入上傳暫存檔。",
    UPLOAD_ERR_EXTENSION => "PHP 擴充套件中止了上傳。",
];

// Upload probe: test-only endpoint, does not forward to ASR
if ($method === 'POST' && $path === '/upload_probe') {
    header('Content-Type: application/json; charset=utf-8');
    if (empty($_FILES['file'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => '未收到 file 欄位。'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $pf      = $_FILES['file'];
    $errCode = is_array($pf['error']) ? UPLOAD_ERR_EXTENSION : (int)$pf['error'];
    $readable = ($errCode === UPLOAD_ERR_OK && isset($pf['tmp_name']) && is_readable($pf['tmp_name']));
    echo json_encode([
        'ok'             => ($errCode === UPLOAD_ERR_OK),
        'filename'       => $pf['name'] ?? '',
        'size'           => (int)($pf['size'] ?? 0),
        'content_length' => (int)($_SERVER['CONTENT_LENGTH'] ?? 0),
        'tmp_readable'   => $readable,
        'upload_error'   => $errCode === UPLOAD_ERR_OK ? null : ($uploadErrors[$errCode] ?? "上傳錯誤碼 $errCode"),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

foreach ($_FILES as $key => $file) {
    $code = is_array($file['error']) ? UPLOAD_ERR_EXTENSION : (int)$file['error'];
    if ($code === UPLOAD_ERR_NO_FILE) {
        continue; // 選填欄位未上傳，略過
    }
    if ($code !== UPLOAD_ERR_OK) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            "error" => $uploadErrors[$code] ?? "檔案上傳失敗（錯誤碼 $code）。",
            "file" => $file['name'] ?? $key,
            "upload_error_code" => $code,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$isMultipart = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') !== false;
if ($method === 'POST' && $isMultipart && empty($_FILES) && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        "error" => "沒有收到完整檔案，可能超過上傳限制或連線中斷。請重新上傳。",
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Security: basic path sanitization
if (preg_match('/[^\w\-\/\.]/', $path)) {
    http_response_code(400);
    echo "Invalid path characters";
    exit;
}

if ($method === 'POST' && $path === '/transcribe_async_chunk') {
    if (empty($_FILES['chunk'])) {
        json_error("沒有收到分段音檔。");
    }
    $chunk = $_FILES['chunk'];
    $code = is_array($chunk['error']) ? UPLOAD_ERR_EXTENSION : (int)$chunk['error'];
    if ($code !== UPLOAD_ERR_OK || !is_readable($chunk['tmp_name'])) {
        json_error($uploadErrors[$code] ?? "分段上傳失敗（錯誤碼 $code）。");
    }

    $uploadId = $_POST['upload_id'] ?? '';
    if (!preg_match('/^[A-Za-z0-9_-]{8,80}$/', $uploadId)) {
        json_error("分段上傳代碼不合法。");
    }
    $chunkIndex = filter_var($_POST['chunk_index'] ?? null, FILTER_VALIDATE_INT);
    $totalChunks = filter_var($_POST['total_chunks'] ?? null, FILTER_VALIDATE_INT);
    if ($chunkIndex === false || $totalChunks === false || $chunkIndex < 0 || $totalChunks < 1 || $chunkIndex >= $totalChunks) {
        json_error("分段序號不合法。");
    }

    $markerDir = sys_get_temp_dir() . "/meeting_app_upload_chunks_done";
    if (is_dir($markerDir)) {
        foreach (glob($markerDir . "/*.json") ?: [] as $oldMarker) {
            if (time() - @filemtime($oldMarker) > 12 * 3600) {
                @unlink($oldMarker);
            }
        }
    }
    $markerPath = $markerDir . "/" . $uploadId . ".json";
    if (is_readable($markerPath)) {
        $markerRaw  = @file_get_contents($markerPath);
        $markerJson = $markerRaw ? json_decode($markerRaw, true) : null;
        if ($markerJson && !empty($markerJson['task_id'])) {
            file_put_contents(__DIR__ . '/proxy_debug.log',
                "[COMPLETION_MARKER_HIT] upload_id=$uploadId task_id=" . $markerJson['task_id'] . "\n",
                FILE_APPEND);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($markerJson, JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    $baseDir = sys_get_temp_dir() . "/meeting_app_upload_chunks";
    $uploadDir = $baseDir . "/" . $uploadId;
    if (is_dir($baseDir)) {
        foreach (glob($baseDir . "/*", GLOB_ONLYDIR) ?: [] as $oldDir) {
            if (time() - @filemtime($oldDir) > 12 * 3600) {
                foreach (glob($oldDir . "/*") ?: [] as $oldFile) {
                    @unlink($oldFile);
                }
                @rmdir($oldDir);
            }
        }
    }
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0700, true)) {
        json_error("伺服器無法建立分段暫存目錄。", 500);
    }

    $partPath = $uploadDir . "/" . $chunkIndex . ".part";
    if (!move_uploaded_file($chunk['tmp_name'], $partPath)) {
        json_error("伺服器無法儲存分段音檔。", 500);
    }

    $receivedParts = [];
    foreach (glob($uploadDir . "/*.part") ?: [] as $partFile) {
        $partName = basename($partFile, ".part");
        if (ctype_digit($partName)) {
            $receivedParts[] = (int)$partName;
        }
    }
    sort($receivedParts);

    if ($chunkIndex < $totalChunks - 1) {
        file_put_contents(__DIR__ . '/proxy_debug.log',
            "[CHUNK_SAVED] upload_id=$uploadId chunk=$chunkIndex/$totalChunks received_count=" . count($receivedParts) . "\n",
            FILE_APPEND);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            "status" => "chunk_received",
            "upload_id" => $uploadId,
            "received" => $chunkIndex + 1,
            "total" => $totalChunks,
            "received_chunks" => $receivedParts,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $missingParts = [];
    for ($i = 0; $i < $totalChunks; $i++) {
        if (!is_readable($uploadDir . "/" . $i . ".part")) {
            $missingParts[] = $i;
        }
    }
    if (!empty($missingParts)) {
        file_put_contents(__DIR__ . '/proxy_debug.log',
            "[MISSING_CHUNKS] upload_id=$uploadId last_chunk=$chunkIndex/$totalChunks missing=" . json_encode($missingParts) . " received=" . json_encode($receivedParts) . "\n",
            FILE_APPEND);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            "status" => "missing_chunks",
            "error" => "分段音檔尚未完整到達，正在自動補傳缺少分段。",
            "missing_chunks" => $missingParts,
            "received_chunks" => $receivedParts,
            "upload_id" => $uploadId,
            "total" => $totalChunks,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    file_put_contents(__DIR__ . '/proxy_debug.log',
        "[ALL_CHUNKS_READY] upload_id=$uploadId total=$totalChunks assembling...\n",
        FILE_APPEND);

    $filename = safe_upload_filename($_POST['filename'] ?? 'upload.audio');
    $assembledPath = $uploadDir . "/assembled_" . $filename;
    $out = fopen($assembledPath, 'wb');
    if (!$out) {
        json_error("伺服器無法合併分段音檔。", 500);
    }
    for ($i = 0; $i < $totalChunks; $i++) {
        $in = fopen($uploadDir . "/" . $i . ".part", 'rb');
        if (!$in) {
            fclose($out);
            json_error("伺服器讀取分段音檔失敗。", 500);
        }
        stream_copy_to_stream($in, $out);
        fclose($in);
    }
    fclose($out);

    $cmd = "curl -s -i --max-time 10800 --unix-socket " . escapeshellarg($SOCKET_PATH);
    $cmd .= " -X " . escapeshellarg("POST");
    $cmd .= " -F " . escapeshellarg("file=@" . $assembledPath . ";type=application/octet-stream;filename=" . $filename);
    $chunkTmpFiles = [];
    foreach ($_POST as $key => $val) {
        if (in_array($key, ['upload_id', 'chunk_index', 'total_chunks', 'filename'], true)) {
            continue;
        }
        $tf = tempnam(sys_get_temp_dir(), 'proxy_chunk_field_');
        file_put_contents($tf, $val);
        $chunkTmpFiles[] = $tf;
        $cmd .= " -F " . escapeshellarg("$key=<$tf");
    }
    $cmd .= " " . escapeshellarg("http://localhost/transcribe_async") . " 2>&1";
    $output = shell_exec($cmd);

    // Extract body from curl output (skip 100-Continue header if present)
    $curlParts = explode("\r\n\r\n", $output ?? '', 2);
    $curlBody  = $curlParts[1] ?? '';
    if (strpos($curlParts[0] ?? '', "100 Continue") !== false) {
        $curlSub  = explode("\r\n\r\n", $curlBody, 2);
        $curlBody = $curlSub[1] ?? '';
    }
    $transcribeData = ($curlBody !== '') ? json_decode($curlBody, true) : null;

    if (empty($transcribeData['task_id'])) {
        $errDetail = !empty($transcribeData['error'])
            ? $transcribeData['error']
            : (!empty($transcribeData['detail'])
                ? $transcribeData['detail']
                : '上傳後伺服器未回傳 task_id，請重試。');
        file_put_contents(__DIR__ . '/proxy_debug.log',
            "[ASSEMBLE_NO_TASK_ID] upload_id=$uploadId body=" . substr($curlBody, 0, 200) . "\n",
            FILE_APPEND);
        foreach ($chunkTmpFiles as $tf) { @unlink($tf); }
        for ($i = 0; $i < $totalChunks; $i++) { @unlink($uploadDir . "/" . $i . ".part"); }
        @unlink($assembledPath);
        @rmdir($uploadDir);
        json_error($errDetail, 502);
    }

    if (!is_dir($markerDir)) { @mkdir($markerDir, 0700, true); }
    $markerPayload = json_encode([
        'task_id'      => $transcribeData['task_id'],
        'status'       => 'completed',
        'created_at'   => time(),
        'filename'     => $filename,
        'total_chunks' => $totalChunks,
    ], JSON_UNESCAPED_UNICODE);
    @file_put_contents($markerPath, $markerPayload);
    file_put_contents(__DIR__ . '/proxy_debug.log',
        "[COMPLETION_MARKER_WRITTEN] upload_id=$uploadId task_id=" . $transcribeData['task_id'] . "\n",
        FILE_APPEND);

    foreach ($chunkTmpFiles as $tf) {
        @unlink($tf);
    }
    for ($i = 0; $i < $totalChunks; $i++) {
        @unlink($uploadDir . "/" . $i . ".part");
    }
    @unlink($assembledPath);
    @rmdir($uploadDir);

    emit_curl_response($output);
    exit;
}

$url = "http://localhost" . $path;

// Start building command
$cmd = "curl -s -i --max-time 10800 --unix-socket " . escapeshellarg($SOCKET_PATH);

// Method
if ($method !== 'GET') {
    $cmd .= " -X " . escapeshellarg($method);
}

// Headers (Pass through standard headers)
$passHeaders = ['Content-Type', 'Authorization']; // Added Auth just in case
foreach ($passHeaders as $h) {
    $phpKey = 'HTTP_' . strtoupper(str_replace('-', '_', $h));
    if (isset($_SERVER[$phpKey])) {
        $val = $_SERVER[$phpKey];
        $cmd .= " -H " . escapeshellarg("$h: $val");
    }
}

// Special case for Content-Type which might be in CONTENT_TYPE
if (isset($_SERVER['CONTENT_TYPE'])) {
    // CRITICAL FIX: Do NOT forward Content-Type if we are using -F (multipart/form-data)
    // because curl generates a NEW boundary. If we force the old header, boundary mismatch occurs.
    if (empty($_FILES)) {
         $val = $_SERVER['CONTENT_TYPE'];
         $cmd .= " -H " . escapeshellarg("Content-Type: $val");
    }
}

// Temp files to clean up after curl runs
$tmpFiles = [];

// Handle POST Data
if ($method === 'POST') {
    // 1. File Upload (multipart/form-data)
    if (!empty($_FILES)) {
        foreach ($_FILES as $key => $file) {
            // Check for macOS resource fork files
            if (strpos($file['name'], '._') === 0) {
                http_response_code(400);
                echo "Error: You selected a macOS metadata file (._" . $file['name'] . "). Please select the real audio file.";
                exit;
            }

            // curl -F "key=@/path/to/file;filename=name;type=mime"
            if (is_readable($file['tmp_name'])) {
                $arg = $key . "=@" . $file['tmp_name'];
                if ($file['type']) $arg .= ";type=" . $file['type'];
                $arg .= ";filename=" . $file['name'];
                $cmd .= " -F " . escapeshellarg($arg);
            }
        }

        // Pass text fields via temp files so multiline/UTF-8 values are never
        // truncated by shell quoting.  Use curl "<file" syntax (not "@") so curl
        // sends the file contents as the field value, not as a file upload.
        foreach ($_POST as $key => $val) {
            $tf = tempnam(sys_get_temp_dir(), 'proxy_field_');
            file_put_contents($tf, $val);
            $tmpFiles[] = $tf;
            $cmd .= " -F " . escapeshellarg("$key=<$tf");
        }
    }
    // 2. JSON Body (application/json)
    else {
        $input = file_get_contents('php://input');
        if (strlen($input) > 0) {
            // Write to temp file to avoid command-line length / escaping issues
            $tf = tempnam(sys_get_temp_dir(), 'proxy_input_');
            file_put_contents($tf, $input);
            $tmpFiles[] = $tf;
            $cmd .= " --data-binary @" . escapeshellarg($tf);
        }
    }
}

$cmd .= " " . escapeshellarg($url) . " 2>&1";

// Execute
$output = shell_exec($cmd);

// Cleanup all temp files
foreach ($tmpFiles as $tf) {
    if (file_exists($tf)) {
        unlink($tf);
    }
}

emit_curl_response($output);
?>
