<?php
$dbFile = "/var/www/database/worktime.sqlite";

$pdo = new PDO("sqlite:" . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec("
    CREATE TABLE IF NOT EXISTS day_status (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id TEXT NOT NULL,
        work_date TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'normal',
        UNIQUE(user_id, work_date)
    )
");

$userId = $_GET["user_id"] ?? $_POST["user_id"] ?? "mustafa";
$month = $_GET["month"] ?? $_POST["month"] ?? date("Y-m");

if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date("Y-m");
}

$targetHoursPerDay = 8.0;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";

    if ($action === "update_time") {
        $id = (int)$_POST["id"];
        $punchIn = str_replace("T", " ", $_POST["punch_in"]);
        $punchOut = str_replace("T", " ", $_POST["punch_out"]);

        if (strlen($punchIn) === 16) $punchIn .= ":00";
        if (strlen($punchOut) === 16) $punchOut .= ":00";

        $stmt = $pdo->prepare("
            UPDATE work_times
            SET punch_in = ?, punch_out = ?
            WHERE id = ? AND user_id = ?
        ");
        $stmt->execute([$punchIn, $punchOut, $id, $userId]);
    }

    if ($action === "delete_time") {
        $id = (int)$_POST["id"];

        $stmt = $pdo->prepare("
            DELETE FROM work_times
            WHERE id = ? AND user_id = ?
        ");
        $stmt->execute([$id, $userId]);
    }

    if ($action === "update_day_status") {
        $workDate = $_POST["work_date"];
        $status = $_POST["status"];

        if (!in_array($status, ["normal", "vacation", "sick"])) {
            $status = "normal";
        }

        $stmt = $pdo->prepare("
            INSERT INTO day_status(user_id, work_date, status)
            VALUES (?, ?, ?)
            ON CONFLICT(user_id, work_date)
            DO UPDATE SET status = excluded.status
        ");
        $stmt->execute([$userId, $workDate, $status]);
    }

    header("Location: work_report.php?user_id=" . urlencode($userId) . "&month=" . urlencode($month));
    exit;
}

$monthStart = $month . "-01";
$monthEnd = date("Y-m-d", strtotime($monthStart . " +1 month"));

$stmt = $pdo->prepare("
    SELECT
        id,
        date(punch_in) AS work_date,
        punch_in,
        punch_out,
        time(punch_in) AS in_time,
        time(punch_out) AS out_time,
        ROUND((julianday(punch_out) - julianday(punch_in)) * 24, 2) AS worked_hours
    FROM work_times
    WHERE user_id = ?
    AND punch_out IS NOT NULL
    AND date(punch_in) >= ?
    AND date(punch_in) < ?
    ORDER BY punch_in ASC
");
$stmt->execute([$userId, $monthStart, $monthEnd]);

$workData = [];

foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $date = $row["work_date"];
    $workData[$date][] = $row;
}

$stmt = $pdo->prepare("
    SELECT work_date, status
    FROM day_status
    WHERE user_id = ?
    AND work_date >= ?
    AND work_date < ?
");
$stmt->execute([$userId, $monthStart, $monthEnd]);

$dayStatuses = [];

foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $dayStatuses[$row["work_date"]] = $row["status"];
}

$totalWorked = 0;
$totalExpected = 0;
$totalBalance = 0;

$daysInMonth = (int)date("t", strtotime($monthStart));
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Work Time Report</title>

    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 40px;
        }

        h1, h2 {
            text-align: center;
        }

        .top-bar {
            margin-bottom: 20px;
            text-align: center;
        }

        input, button, select {
            padding: 6px 8px;
            font-size: 14px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 25px;
        }

        th, td {
            border: 1px solid #333;
            padding: 8px;
            text-align: center;
            vertical-align: middle;
        }

        th {
            background: #eee;
        }

        .plus {
            color: green;
            font-weight: bold;
        }

        .minus {
            color: red;
            font-weight: bold;
        }

        .empty-day {
            color: #777;
            background: #fafafa;
        }

        .punch-link {
            display: inline-block;
            margin: 3px;
            padding: 4px 8px;
            background: #e3f2fd;
            border: 1px solid #1976d2;
            border-radius: 4px;
            cursor: pointer;
        }

        .punch-link:hover {
            background: #bbdefb;
        }

        .summary {
            margin-top: 30px;
            font-size: 18px;
            text-align: right;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 999;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
        }

        .modal-content {
            background: white;
            margin: 10% auto;
            padding: 25px;
            width: 420px;
            border-radius: 8px;
        }

        .modal-content h3 {
            margin-top: 0;
        }

        .modal-row {
            margin-bottom: 15px;
        }

        .modal-row label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }

        .modal-actions {
            display: flex;
            justify-content: space-between;
            margin-top: 20px;
        }

        .save-button {
            background: #1976d2;
            color: white;
            border: none;
            cursor: pointer;
        }

        .delete-button {
            background: #d32f2f;
            color: white;
            border: none;
            cursor: pointer;
        }

        .cancel-button {
            background: #777;
            color: white;
            border: none;
            cursor: pointer;
        }

        @media print {
            .top-bar,
            .modal,
            button {
                display: none !important;
            }

            body {
                margin: 15mm;
            }

            select {
                border: none;
                background: transparent;
                appearance: none;
            }

            .punch-link {
                border: none;
                background: transparent;
                padding: 0;
                margin: 0;
            }
        }
    </style>
</head>
<body>

<h1>Work Time Report</h1>
<h2>User: <?= htmlspecialchars($userId) ?> — Month: <?= htmlspecialchars($month) ?></h2>

<div class="top-bar">
    <form method="get">
        <input type="hidden" name="user_id" value="<?= htmlspecialchars($userId) ?>">

        <label>
            Select month:
            <input type="month" name="month" value="<?= htmlspecialchars($month) ?>">
        </label>

        <button type="submit">Show</button>
        <button type="button" onclick="window.print()">Print Sheet</button>
    </form>
</div>

<table>
    <thead>
        <tr>
            <th>Date</th>
            <th>Weekday</th>
            <th>Status</th>
            <th>Punch Times</th>
            <th>Worked Hours</th>
            <th>Expected Hours</th>
            <th>Balance</th>
        </tr>
    </thead>

    <tbody>
        <?php for ($day = 1; $day <= $daysInMonth; $day++): ?>
            <?php
                $date = sprintf("%s-%02d", $month, $day);
                $weekday = date("l", strtotime($date));
                $sessions = $workData[$date] ?? [];
                $status = $dayStatuses[$date] ?? "normal";

                $dayNumber = (int)date("N", strtotime($date));
                $isWorkday = $dayNumber <= 5;

                $expected = $isWorkday ? $targetHoursPerDay : 0.0;
                $worked = 0.0;

                foreach ($sessions as $session) {
                    $worked += (float)$session["worked_hours"];
                }

                if ($status === "vacation" || $status === "sick") {
                    $workedForBalance = $expected;
                } else {
                    $workedForBalance = $worked;
                }

                $balance = $workedForBalance - $expected;

                $totalWorked += $workedForBalance;
                $totalExpected += $expected;
                $totalBalance += $balance;

                $balanceClass = $balance >= 0 ? "plus" : "minus";
                $balanceText = ($balance >= 0 ? "+" : "") . number_format($balance, 2);
            ?>

            <tr class="<?= count($sessions) > 0 ? "" : "empty-day" ?>">
                <td><?= htmlspecialchars($date) ?></td>
                <td><?= htmlspecialchars($weekday) ?></td>

                <td>
                    <form method="post">
                        <input type="hidden" name="action" value="update_day_status">
                        <input type="hidden" name="user_id" value="<?= htmlspecialchars($userId) ?>">
                        <input type="hidden" name="month" value="<?= htmlspecialchars($month) ?>">
                        <input type="hidden" name="work_date" value="<?= htmlspecialchars($date) ?>">

                        <select name="status" onchange="this.form.submit()">
                            <option value="normal" <?= $status === "normal" ? "selected" : "" ?>>Normal</option>
                            <option value="vacation" <?= $status === "vacation" ? "selected" : "" ?>>Vacation</option>
                            <option value="sick" <?= $status === "sick" ? "selected" : "" ?>>Sick leave</option>
                        </select>
                    </form>
                </td>

                <td>
                    <?php if (count($sessions) > 0): ?>
                        <?php foreach ($sessions as $session): ?>
                            <span
                                class="punch-link"
                                onclick="openEditModal(
                                    '<?= (int)$session["id"] ?>',
                                    '<?= htmlspecialchars(str_replace(' ', 'T', substr($session["punch_in"], 0, 16))) ?>',
                                    '<?= htmlspecialchars(str_replace(' ', 'T', substr($session["punch_out"], 0, 16))) ?>'
                                )"
                            >
                                <?= htmlspecialchars($session["in_time"]) ?>
                                -
                                <?= htmlspecialchars($session["out_time"]) ?>
                            </span>
                        <?php endforeach; ?>
                    <?php else: ?>
                        -
                    <?php endif; ?>
                </td>

                <td><?= number_format($worked, 2) ?></td>
                <td><?= number_format($expected, 2) ?></td>

                <td class="<?= $balanceClass ?>">
                    <?= $balanceText ?>
                </td>
            </tr>
        <?php endfor; ?>
    </tbody>
</table>

<div class="summary">
    <p><strong>Total worked:</strong> <?= number_format($totalWorked, 2) ?> hours</p>
    <p><strong>Total expected:</strong> <?= number_format($totalExpected, 2) ?> hours</p>

    <p>
        <strong>Total balance:</strong>
        <span class="<?= $totalBalance >= 0 ? "plus" : "minus" ?>">
            <?= ($totalBalance >= 0 ? "+" : "") . number_format($totalBalance, 2) ?> hours
        </span>
    </p>
</div>

<div id="editModal" class="modal">
    <div class="modal-content">
        <h3>Edit Punch Time</h3>

        <form method="post" id="editForm">
            <input type="hidden" name="id" id="editId">
            <input type="hidden" name="user_id" value="<?= htmlspecialchars($userId) ?>">
            <input type="hidden" name="month" value="<?= htmlspecialchars($month) ?>">

            <div class="modal-row">
                <label>Punch In</label>
                <input type="datetime-local" name="punch_in" id="editPunchIn" required>
            </div>

            <div class="modal-row">
                <label>Punch Out</label>
                <input type="datetime-local" name="punch_out" id="editPunchOut" required>
            </div>

            <div class="modal-actions">
                <button type="submit" name="action" value="update_time" class="save-button">
                    Save
                </button>

                <button
                    type="submit"
                    name="action"
                    value="delete_time"
                    class="delete-button"
                    onclick="return confirm('Delete this punch pair?')"
                >
                    Delete
                </button>

                <button type="button" class="cancel-button" onclick="closeEditModal()">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditModal(id, punchIn, punchOut) {
    document.getElementById("editId").value = id;
    document.getElementById("editPunchIn").value = punchIn;
    document.getElementById("editPunchOut").value = punchOut;
    document.getElementById("editModal").style.display = "block";
}

function closeEditModal() {
    document.getElementById("editModal").style.display = "none";
}

window.onclick = function(event) {
    const modal = document.getElementById("editModal");
    if (event.target === modal) {
        closeEditModal();
    }
}
</script>

</body>
</html>
