<?php
// Load configuration
$config = json_decode(file_get_contents('config.json'), true);
$uploadLimitBytes = $config['upload_limit_mb'] * 1024 * 1024;
$randomizeNames = $config['randomize_names'];
$allowedTypes = $config['allowed_file_types'];
$enableLogging = $config['enable_logging'];
$logFile = $config['log_file'];

// Function to log data
function logData($message) {
    global $enableLogging, $logFile;
    if ($enableLogging) {
        $logEntry = date('Y-m-d H:i:s') . ' - ' . $message . PHP_EOL;
        file_put_contents($logFile, $logEntry, FILE_APPEND);
    }
}

// Handle file uploads
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $fileType = mime_content_type($_FILES['image']['tmp_name']);
        $fileSize = $_FILES['image']['size'];
        $originalName = $_FILES['image']['name'];
        $uploadTime = date('Y-m-d H:i:s');

        if (!in_array($fileType, $allowedTypes)) {
            echo "<div class='error'>Invalid file type. Only images are allowed.</div>";
        } elseif ($fileSize > $uploadLimitBytes) {
            echo "<div class='error'>File exceeds the upload limit of {$config['upload_limit_mb']} MB.</div>";
        } else {
            $currentDir = dirname($_SERVER['SCRIPT_NAME']);
            $uploadDir = __DIR__ . '/uploads/';

            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $extension = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
            $fileName = $randomizeNames
                ? substr(bin2hex(random_bytes(4)), 0, 8) . '.' . $extension
                : basename($_FILES['image']['name']);

            $uploadFile = $uploadDir . $fileName;

            if (move_uploaded_file($_FILES['image']['tmp_name'], $uploadFile)) {
                $deleteOption = $_POST['delete_option'] ?? 'time';
                if ($deleteOption === 'time') {
                    $maxTime = 24 * 60 * 60;
                    $deleteTime = isset($_POST['delete_time']) ? min(intval($_POST['delete_time']) * 60 * 60, $maxTime) : 1 * 60 * 60;
                    $expiration = time() + $deleteTime;
                    file_put_contents($uploadFile . '.txt', $expiration);
                } elseif ($deleteOption === 'view') {
                    file_put_contents($uploadFile . '.txt', 'view');
                }

                $imageLink = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . $currentDir . '/?' . http_build_query(['img' => $fileName]);
                echo "<div class='success'>File uploaded successfully. <br>Access your file: <a href='$imageLink' target='_blank'>$imageLink</a></div>";

                // Log the upload event
                $userIP = $_SERVER['REMOTE_ADDR'];
                logData("IP: $userIP, Original Name: $originalName, File Name: $fileName, Type: $fileType, Size: $fileSize bytes, Uploaded at: $uploadTime, Link: $imageLink");
            } else {
                echo "<div class='error'>File upload failed.</div>";
            }
        }
    }
}

// Handle image view detection
if (isset($_GET['img'])) {
    $imageName = basename($_GET['img']);
    $imageFile = __DIR__ . '/uploads/' . $imageName;
    $metaFile = $imageFile . '.txt';

    if (file_exists($imageFile) && file_exists($metaFile)) {
        $deleteOption = file_get_contents($metaFile);
        header('Content-Type: ' . mime_content_type($imageFile));
        readfile($imageFile);

        // Log the view event
        $viewTime = date('Y-m-d H:i:s');
        $userIP = $_SERVER['REMOTE_ADDR'];
        logData("IP: $userIP, File Name: $imageName, Viewed at: $viewTime");

        if ($deleteOption === 'view') {
            unlink($imageFile);
            unlink($metaFile);
        }
        exit;
    } else {
        echo "Image not found.";
    }
}

// Periodically check and delete expired images
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['img'])) {
    $uploadDir = __DIR__ . '/uploads/';
    $now = time();
    foreach (glob($uploadDir . '*') as $file) {
        if (pathinfo($file, PATHINFO_EXTENSION) !== 'txt') {
            $metaFile = $file . '.txt';
            if (file_exists($metaFile)) {
                $expiration = file_get_contents($metaFile);
                if ($expiration !== 'view' && $now > intval($expiration)) {
                    unlink($file);
                    unlink($metaFile);
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
    <title>Cocus Image Uploader</title>
    <style>
        :root {
            --bg-color: #0f0f0f;
            --text-color: #e0e0e0;
            --accent-color: #7c7c7c;
            --hover-color: #4a9eff;
            --card-bg: #1a1a1a;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: var(--bg-color);
            color: var(--text-color);
            font-family: monospace;
            line-height: 1.6;
            padding: 2rem 1rem;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .form-container {
            background-color: var(--card-bg);
            padding: 20px;
            border-radius: 8px;
            max-width: 400px;
            width: 100%;
            text-align: center;
            box-shadow: 0 0 10px #000;
        }

        input, select, button {
            width: 100%;
            padding: 10px;
            margin: 10px 0;
            background-color: #222;
            color: var(--text-color);
            border: 1px solid #444;
            border-radius: 4px;
        }

        button {
            background-color: var(--hover-color);
            color: #fff;
            border: none;
            cursor: pointer;
        }

        button:hover {
            background-color: #2c85ff;
        }

        h2 {
            margin-bottom: 1rem;
        }

        .success, .error {
            padding: 12px;
            border-radius: 2px;
            margin-top: 1rem;
            margin-bottom: 1rem;
            font-size: 0.95rem;
            text-align: left;
        }

        .success {
            background-color: #1a4f2d;
            color: #a0e6b0;
        }

        .error {
            background-color: #4f1a1a;
            color: #e6a0a0;
        }

        a {
            color: var(--hover-color);
            margin-top: 1rem;
            font-size: 0.8rem;
            text-decoration: none;
        }

        a:hover {
            text-decoration: underline;
        }

        input[name="delete_time"] {
            transition: opacity 0.2s ease;
        }
    </style>
</head>
<body>
    <div class="form-container">
        <h2>Cocus Image Uploader</h2>
        <form action="" method="post" enctype="multipart/form-data">
            <input type="file" name="image" required><br>
            <label for="delete_option">Delete after:</label>
            <select name="delete_option" id="delete_option">
                <option value="time">Time (hours)</option>
                <option value="view">First View</option>
            </select><br>
            <input type="number" name="delete_time" min="1" max="24" placeholder="Hours (if time selected)"><br>
            <button type="submit">Upload</button>
        </form>
    </div>
    <a href="https://github.com/nixietab/cocus" target="_blank">Made with freedom</a>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const deleteOption = document.getElementById('delete_option');
        const deleteTimeInput = document.querySelector('input[name="delete_time"]');

        function toggleDeleteTime() {
            if (deleteOption.value === 'view') {
                deleteTimeInput.style.display = 'none';
            } else {
                deleteTimeInput.style.display = 'block';
            }
        }

        deleteOption.addEventListener('change', toggleDeleteTime);
        toggleDeleteTime();
    });
    </script>
</body>
</html>
