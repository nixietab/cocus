<?php
// Handle file uploads
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $currentDir = dirname($_SERVER['SCRIPT_NAME']);
        $uploadDir = __DIR__ . '/uploads/';

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $fileName = basename($_FILES['image']['name']);
        $uploadFile = $uploadDir . $fileName;

        if (move_uploaded_file($_FILES['image']['tmp_name'], $uploadFile)) {
            $deleteOption = $_POST['delete_option'];
            if ($deleteOption === 'time') {
                $maxTime = 24 * 60 * 60; // 24 hours in seconds
                $deleteTime = min(intval($_POST['delete_time']) * 60 * 60, $maxTime);
                $expiration = time() + $deleteTime;
                file_put_contents($uploadFile . '.txt', $expiration);
            } elseif ($deleteOption === 'view') {
                file_put_contents($uploadFile . '.txt', 'view');
            }

            $imageLink = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . $currentDir . '/?' . http_build_query(['img' => $fileName]);
            echo "<div class='success'>File uploaded successfully. <br>Access your file: <a href='$imageLink' target='_blank'>$imageLink</a></div>";
        } else {
            echo "<div class='error'>File upload failed.</div>";
        }
    }
}

// Handle image view detection
if (isset($_GET['img'])) {
    $imageName = basename($_GET['img']);
    $imageFile = __DIR__ . '/uploads/' . $imageName;
    $metaFile = $imageFile . '.txt';

    // Check if the image file exists
    if (file_exists($imageFile) && file_exists($metaFile)) {
        $deleteOption = file_get_contents($metaFile);

        // Serve the image
        header('Content-Type: ' . mime_content_type($imageFile));
        readfile($imageFile);

        // If the image should be deleted after the first view, delete it
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
    foreach (glob($uploadDir . '*') as $file) {
        if (pathinfo($file, PATHINFO_EXTENSION) !== 'txt') {
            $metaFile = $file . '.txt';
            if (file_exists($metaFile)) {
                $expiration = file_get_contents($metaFile);
                if ($expiration !== 'view' && time() > intval($expiration)) {
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
        body {
            background-color: #1c1c1c;
            color: #d4d4d4;
            font-family: 'Verdana', sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            height: 100vh;
            margin: 0;
        }
        .form-container {
            background-color: #2d2d2d;
            padding: 20px;
            border-radius: 10px;
            width: 300px;
            text-align: center;
        }
        input, select, button {
            width: 100%;
            padding: 10px;
            margin: 10px 0;
            background-color: #3c3c3c;
            color: #d4d4d4;
            border: 1px solid #444;
            border-radius: 5px;
            box-sizing: border-box;
        }
        button {
            background-color: #007acc;
            color: #fff;
            border: none;
            cursor: pointer;
            transition: background-color 0.3s ease;
        }
        button:hover {
            background-color: #005ea1;
        }
        h2 {
            margin-bottom: 20px;
            font-weight: normal;
        }
        .success, .error {
            margin: 20px 0;
            padding: 10px;
            border-radius: 4px;
            color: #ffffff;
        }
        .success {
            background-color: #007acc;
        }
        .error {
            background-color: #cc0000;
        }
        a {
            color: #61dafb;
            text-decoration: none;
            font-size: 12px;
            position: fixed;
            bottom: 10px;
        }
        a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="form-container">
        <h2>Cocus Image Uploader</h2>
        <form action="" method="post" enctype="multipart/form-data">
            <input type="file" name="image" required><br>
            <label for="delete_option">Delete after:</label>
            <select name="delete_option" id="delete_option" required>
                <option value="time">Time (hours)</option>
                <option value="view">First View</option>
            </select><br>
            <input type="number" name="delete_time" min="1" max="24" placeholder="Hours (if time selected)"><br>
            <button type="submit">Upload</button>
        </form>
    </div>
    <a href="https://github.com/nixietab/cocus" target="_blank">Made with freedom</a>
</body>
</html>
