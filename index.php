<?php
session_start();

$config = json_decode(file_get_contents('config.json'), true);
$uploadLimitBytes = $config['upload_limit_mb'] * 1024 * 1024;
$randomizeNames = $config['randomize_names'];
$allowedTypes = $config['allowed_file_types'];
$enableLogging = $config['enable_logging'];
$logFile = $config['log_file'];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!isset($_SESSION['last_upload'])) {
    $_SESSION['last_upload'] = 0;
    $_SESSION['upload_count'] = 0;
}

function logData($message) {
    global $enableLogging, $logFile;
    if ($enableLogging) {
        $sanitized = preg_replace('/[^\x20-\x7E]/', '', $message);
        $logEntry = date('Y-m-d H:i:s') . ' - ' . $sanitized . PHP_EOL;
        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
}

function sanitizeFilename($filename) {
    $filename = preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);
    $filename = preg_replace('/\.+/', '.', $filename);
    return substr($filename, 0, 255);
}

function validateImageFile($tmpPath, $allowedTypes) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $tmpPath);
    finfo_close($finfo);
    
    if (!in_array($mimeType, $allowedTypes)) {
        return false;
    }
    
    $imageInfo = @getimagesize($tmpPath);
    if ($imageInfo === false) {
        return false;
    }
    
    return true;
}

$uploadMessage = '';
$uploadError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $uploadError = 'Invalid request. Please try again.';
    }
    elseif (time() - $_SESSION['last_upload'] < 60 && $_SESSION['upload_count'] >= 5) {
        $uploadError = 'Too many uploads. Please wait a moment.';
    }
    elseif (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $fileSize = $_FILES['image']['size'];
        $originalName = basename($_FILES['image']['name']);
        $uploadTime = date('Y-m-d H:i:s');
        $tmpPath = $_FILES['image']['tmp_name'];

        if (!validateImageFile($tmpPath, $allowedTypes)) {
            $uploadError = 'Invalid file type. Only images are allowed.';
        } elseif ($fileSize > $uploadLimitBytes) {
            $uploadError = "File exceeds the upload limit of {$config['upload_limit_mb']} MB.";
        } else {
            $currentDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
            $uploadDir = __DIR__ . '/uploads/';

            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
                file_put_contents($uploadDir . '.htaccess', "Options -Indexes\nRemoveHandler .php .phtml .php3\nRemoveType .php .phtml .php3");
                file_put_contents($uploadDir . 'index.php', '<?php http_response_code(403); ?>');
            }

            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            $fileName = $randomizeNames
                ? bin2hex(random_bytes(16)) . '.' . $extension
                : sanitizeFilename($originalName);

            $uploadFile = $uploadDir . $fileName;

            if (file_exists($uploadFile)) {
                $uploadError = 'File conflict. Please try again.';
            } elseif (move_uploaded_file($tmpPath, $uploadFile)) {
                chmod($uploadFile, 0644);
                
                $deleteOption = $_POST['delete_option'] ?? 'time';
                if ($deleteOption === 'time') {
                    $maxTime = 24 * 60 * 60;
                    $deleteTime = isset($_POST['delete_time']) ? min(max(intval($_POST['delete_time']), 1), 24) * 60 * 60 : 1 * 60 * 60;
                    $expiration = time() + $deleteTime;
                    file_put_contents($uploadFile . '.meta', $expiration, LOCK_EX);
                } elseif ($deleteOption === 'view') {
                    file_put_contents($uploadFile . '.meta', 'view', LOCK_EX);
                }
                
                chmod($uploadFile . '.meta', 0644);

                $imageLink = ($_SERVER['HTTPS'] ?? 'off') === 'on' ? 'https://' : 'http://';
                $imageLink .= $_SERVER['HTTP_HOST'] . $currentDir . '/?' . http_build_query(['img' => $fileName]);
                
                $uploadMessage = "File uploaded successfully.<br><a href='$imageLink' target='_blank' class='link'>$imageLink</a>";

                $userIP = $_SERVER['REMOTE_ADDR'];
                logData("UPLOAD - IP: $userIP, File: $fileName, Type: {$extension}, Size: $fileSize bytes");
                
                if (time() - $_SESSION['last_upload'] >= 60) {
                    $_SESSION['upload_count'] = 1;
                } else {
                    $_SESSION['upload_count']++;
                }
                $_SESSION['last_upload'] = time();
            } else {
                $uploadError = 'File upload failed.';
            }
        }
    } elseif (isset($_FILES['image'])) {
        $uploadError = 'Upload error occurred.';
    }
}

if (isset($_GET['img'])) {
    $imageName = preg_replace('/[^a-zA-Z0-9._-]/', '', basename($_GET['img']));
    $imageFile = __DIR__ . '/uploads/' . $imageName;
    $metaFile = $imageFile . '.meta';

    if (file_exists($imageFile) && file_exists($metaFile)) {
        $deleteOption = file_get_contents($metaFile);
        
        header('Content-Type: ' . mime_content_type($imageFile));
        header('X-Content-Type-Options: nosniff');
        header('Content-Security-Policy: default-src \'none\'; img-src \'self\'; style-src \'unsafe-inline\';');
        readfile($imageFile);

        $viewTime = date('Y-m-d H:i:s');
        $userIP = $_SERVER['REMOTE_ADDR'];
        logData("VIEW - IP: $userIP, File: $imageName");

        if ($deleteOption === 'view') {
            unlink($imageFile);
            unlink($metaFile);
        }
        exit;
    } else {
        http_response_code(404);
        echo "Image not found.";
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['img'])) {
    $uploadDir = __DIR__ . '/uploads/';
    $now = time();
    if (is_dir($uploadDir)) {
        foreach (glob($uploadDir . '*') as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) !== 'meta') {
                $metaFile = $file . '.meta';
                if (file_exists($metaFile)) {
                    $expiration = file_get_contents($metaFile);
                    if ($expiration !== 'view' && is_numeric($expiration) && $now > intval($expiration)) {
                        @unlink($file);
                        @unlink($metaFile);
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cocus | Temporary File Hosting</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: #0f0f0f;
            color: #e0e0e0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            line-height: 1.6;
            padding: 20px;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .container {
            background: #1a1a1a;
            padding: 40px;
            border-radius: 2px;
            max-width: 500px;
            width: 100%;
            box-shadow: 0 4px 6px rgba(0,0,0,0.3);
        }

        h1 {
            font-size: 24px;
            font-weight: 400;
            margin-bottom: 10px;
            color: #e0e0e0;
        }

        .subtitle {
            font-size: 14px;
            color: #888;
            margin-bottom: 30px;
        }

        form {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        label {
            font-size: 13px;
            color: #999;
            font-weight: 500;
        }

        input[type="file"] {
            padding: 8px;
            border: 1px solid #333;
            border-radius: 2px;
            font-size: 14px;
            background: #222;
            color: #e0e0e0;
        }

        input[type="file"]:hover {
            border-color: #444;
        }

        select, input[type="number"] {
            padding: 10px;
            border: 1px solid #333;
            border-radius: 2px;
            font-size: 14px;
            background: #222;
            color: #e0e0e0;
        }

        select:focus, input[type="number"]:focus, input[type="file"]:focus {
            outline: none;
            border-color: #4a9eff;
        }

        button {
            padding: 12px;
            background: #4a9eff;
            color: #fff;
            border: none;
            border-radius: 2px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.2s;
        }

        button:hover {
            background: #3a8eef;
        }

        button:active {
            background: #2a7edf;
        }

        .message {
            padding: 12px 15px;
            border-radius: 2px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .success {
            background: #1a3a1a;
            color: #90ee90;
            border: 1px solid #2a5a2a;
        }

        .error {
            background: #3a1a1a;
            color: #ff9090;
            border: 1px solid #5a2a2a;
        }

        .link {
            color: #4a9eff;
            text-decoration: none;
            word-break: break-all;
        }

        .link:hover {
            text-decoration: underline;
        }

        footer {
            margin-top: 30px;
            font-size: 12px;
            color: #666;
        }

        footer a {
            color: #4a9eff;
            text-decoration: none;
        }

        footer a:hover {
            text-decoration: underline;
        }

        .hidden {
            display: none;
        }

        @media (max-width: 600px) {
            .container {
                padding: 30px 20px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Cocus</h1>
        <p class="subtitle">Temporary file hosting</p>
        
        <?php if ($uploadMessage): ?>
            <div class="message success"><?php echo $uploadMessage; ?></div>
        <?php endif; ?>
        
        <?php if ($uploadError): ?>
            <div class="message error"><?php echo htmlspecialchars($uploadError); ?></div>
        <?php endif; ?>

        <form action="" method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            
            <div class="form-group">
                <label for="image">Select file</label>
                <input type="file" name="image" id="image" required accept="image/*">
            </div>

            <div class="form-group">
                <label for="delete_option">Delete after</label>
                <select name="delete_option" id="delete_option">
                    <option value="time">Time period</option>
                    <option value="view">First view</option>
                </select>
            </div>

            <div class="form-group" id="time-group">
                <label for="delete_time">Hours until deletion</label>
                <input type="number" name="delete_time" id="delete_time" min="1" max="24" value="1" placeholder="1-24 hours">
            </div>

            <button type="submit">Upload</button>
        </form>
    </div>

    <footer>
        <a href="https://github.com/nixietab/cocus" target="_blank">cocus</a> | simple temporary file hosting
    </footer>

    <script>
        const deleteOption = document.getElementById('delete_option');
        const timeGroup = document.getElementById('time-group');

        function toggleTimeInput() {
            if (deleteOption.value === 'view') {
                timeGroup.classList.add('hidden');
            } else {
                timeGroup.classList.remove('hidden');
            }
        }

        deleteOption.addEventListener('change', toggleTimeInput);
        toggleTimeInput();
    </script>
</body>
</html>
