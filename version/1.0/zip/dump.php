<?php
// Zpracování POST požadavku pro vytvoření zálohy
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['backup'])) {
    /**
     * Připojí se k databázi.
     */
    $host = $_POST['host'];
    $username = $_POST['username'];
    $password = $_POST['password'];
    $database = $_POST['database'];

    $connection = new mysqli($host, $username, $password, $database);
    if ($connection->connect_error) {
        die("Nelze se připojit k databázi: " . $connection->connect_error);
    }

    $result = $connection->query("SHOW TABLES");
    $tables = [];
    while ($row = $result->fetch_row()) {
        $tables[] = $row[0];
    }

    $dump = "";
    foreach ($tables as $table) {
        $dump .= "DROP TABLE IF EXISTS `$table`;\n";
        $createTable = $connection->query("SHOW CREATE TABLE `$table`")->fetch_row()[1];
        $dump .= $createTable . ";\n";

        $rows = $connection->query("SELECT * FROM `$table`");
        while ($row = $rows->fetch_assoc()) {
            $vals = array_map([$connection, 'real_escape_string'], array_values($row));
            $vals = array_map(function($item){return "'$item'";}, $vals);
            $dump .= "INSERT INTO `$table` VALUES (" . implode(',', $vals) . ");\n";
        }
        $dump .= "\n";
    }

    file_put_contents('db_backup.sql', $dump);

    $connection->close();
    $successMessage = "Záloha databáze byla úspěšně vytvořena.";
}

// Zpracování POST požadavku pro smazání zálohy
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete'])) {
    if (file_exists('db_backup.sql')) {
        unlink('db_backup.sql');
        $deleteMessage = "Soubor zálohy byl úspěšně smazán.";
    } else {
        $deleteMessage = "Soubor zálohy neexistuje.";
    }
}
?>

<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <title>Zálohování a mazání databáze</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            color: #333;
            max-width: 600px;
            margin: 40px auto;
            padding: 20px;
        }
        form, .message {
            margin: 20px auto;
            padding: 20px;
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            text-align: center;
        }
        label, .download-link {
            display: block;
            margin-top: 10px;
            margin-bottom: 20px;
        }
        input[type="text"],
        input[type="password"],
        button, .download-link {
            width: 80%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
            margin: 0 auto;
        }
        button, .download-link {
            background-color: #4CAF50;
            color: white;
            font-size: 16px;
            border: none;
            cursor: pointer;
            text-decoration: none;
            display: block;
            margin-top: 10px;
        }
        button:hover, .download-link:hover {
            background-color: #45a049;
        }
    </style>
</head>

<body>
    <h1>Zálohování MySQL databáze</h1>
    <form method="post">
        <label for="host">Hostitel:</label>
        <input type="text" id="host" name="host" required>
        <label for="username">Uživatelské jméno:</label>
        <input type="text" id="username" name="username" required>
        <label for="password">Heslo:</label>
        <input type="password" id="password" name="password" required>
        <label for="database">Název databáze:</label>
        <input type="text" id="database" name="database" required>
        <br>
        <br>
        <button type="submit" name="backup">Vytvořit zálohu</button>
    </form>
    <?php
    if (isset($successMessage)) {
        echo "<div class='message'>$successMessage</div>";
        echo "<a href='db_backup.sql' download class='download-link'>Stáhnout zálohu</a>";
    }
    if (isset($deleteMessage)) {
        echo "<div class='message'>$deleteMessage</div>";
    }
    ?>
    <form method="post">
        <button type="submit" name="delete">Smazat zálohu</button>
    </form>
</body>
</html>
