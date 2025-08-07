<!DOCTYPE html>
<html>
<head>
    <title>Directory Listing</title>
    <style>
        body {
            font-family: sans-serif;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 8px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background-color: #f2f2f2;
        }
        a {
            text-decoration: none;
            color: #007bff;
        }
        a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <h1>Directory Listing of /</h1>
    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Type</th>
                <th>Size (bytes)</th>
                <th>Last Modified</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $dir = ".";
            $files = scandir($dir);

            foreach($files as $file) {
                if ($file !== "." && $file !== "..") {
                    $filePath = $dir . '/' . $file;
                    $isDir = is_dir($filePath);
                    echo "<tr>";
                    echo "<td><a href=\"$file\">$file</a></td>";
                    echo "<td>" . ($isDir ? "Directory" : "File") . "</td>";
                    echo "<td>" . ($isDir ? "-" : filesize($filePath)) . "</td>";
                    echo "<td>" . date("Y-m-d H:i:s", filemtime($filePath)) . "</td>";
                    echo "</tr>";
                }
            }
            ?>
        </tbody>
    </table>
</body>
</html>
