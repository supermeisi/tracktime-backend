<?php

header("Content-Type: application/json");

$dbFile = "/var/www/database/worktime.sqlite";

try {
    $pdo = new PDO("sqlite:" . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS work_times (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id TEXT NOT NULL,
            punch_in TEXT NOT NULL,
            punch_out TEXT,
            created_at TEXT NOT NULL
        )
    ");

    $input = json_decode(file_get_contents("php://input"), true);

    if (!$input) {
        echo json_encode([
            "success" => false,
            "message" => "Invalid JSON"
        ]);
        exit;
    }

    $action = $input["action"] ?? "";
    $userId = $input["user_id"] ?? "default_user";
    $now = date("Y-m-d H:i:s");

    if ($action === "punch_in") {

        // Check if user is already punched in
        $stmt = $pdo->prepare("
            SELECT * FROM work_times
            WHERE user_id = ?
            AND punch_out IS NULL
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $openShift = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($openShift) {
            echo json_encode([
                "success" => false,
                "message" => "You are already punched in.",
                "data" => $openShift
            ]);
            exit;
        }

        $stmt = $pdo->prepare("
            INSERT INTO work_times(user_id, punch_in, created_at)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$userId, $now, $now]);

        echo json_encode([
            "success" => true,
            "message" => "Punch in successful.",
            "punch_in" => $now
        ]);
        exit;
    }

    if ($action === "punch_out") {

        // Find latest open shift
        $stmt = $pdo->prepare("
            SELECT * FROM work_times
            WHERE user_id = ?
            AND punch_out IS NULL
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $openShift = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$openShift) {
            echo json_encode([
                "success" => false,
                "message" => "You are not punched in."
            ]);
            exit;
        }

        $stmt = $pdo->prepare("
            UPDATE work_times
            SET punch_out = ?
            WHERE id = ?
        ");
        $stmt->execute([$now, $openShift["id"]]);

        echo json_encode([
            "success" => true,
            "message" => "Punch out successful.",
            "punch_in" => $openShift["punch_in"],
            "punch_out" => $now
        ]);
        exit;
    }

    if ($action === "status") {

        $stmt = $pdo->prepare("
            SELECT * FROM work_times
            WHERE user_id = ?
            AND punch_out IS NULL
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $openShift = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            "success" => true,
            "punched_in" => $openShift ? true : false,
            "data" => $openShift
        ]);
        exit;
    }

    echo json_encode([
        "success" => false,
        "message" => "Unknown action."
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}
