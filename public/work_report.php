<?php
$dbFile = "/var/www/database/worktime.sqlite";

$pdo = new PDO("sqlite:" . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$userId = $_GET["user_id"] ?? $_POST["user_id"] ?? "mustafa";
$month = $_GET["month"] ?? $_POST["month"] ?? date("Y-m");

if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date("Y-m");
}

$targetHoursPerDay = 8.0;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";

    if ($action === "delete") {
        $id = (int)$_POST["id"];

        $stmt = $pdo->prepare("
            DELETE FROM work_times
            WHERE id = ? AND user_id = ?
        ");
        $stmt->execute([$id, $userId]);
    }

    if ($action === "update") {
        $id = (int)$_POST["id"];
        $punchIn = str_replace("T", " ", $_POST["punch_in"]);
        $punchOut = str_replace("T", " ", $_POST["punch_out"]);

        if (strlen($punchIn) === 16) {
            $punchIn .= ":00";
        }

        if (strlen($punchOut) === 16) {
            $punchOut .= ":00";
        }

        $stmt = $pdo->prepare("
            UPDATE work_times
            SET punch_in = ?, punch_out = ?
            WHERE id = ? AND user_id = ?
        ");
        $stmt->execute([$punchIn, $punchOut, $id, $userId]);
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

    if (!isset($workData[$date])) {
        $workData[$date] = [];
    }

    $workData[$date][] = $row;
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

        input, button {
            padding: 6px 8px;
            font-size: 14px;
        }

        .print-button {
            margin-left: 10px;
            cursor: pointer;
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

        .session-form {
            margin-bottom: 8px;
            white-space: nowrap;
        }

        .session-form:last-child {
            margin-bottom: 0;
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

        .summary {
            margin-top: 30px;
            font-size: 18px;
            text-align: right;
        }

        @media print {
            .top-bar,
            .save-button,
            .delete-button {
                display: none;
            }

            body {
                margin: 15mm;
            }

            input {
                border: none;
                background: transparent;
                padding: 0;
                font-size: 12px;
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
        <button type="button" class="print-button" onclick="window.print()">Print Sheet</button>
    </form>
</div>

<table>
    <thead>
        <tr>
            <th>Date</th>
            <th>Weekday</th>
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

                $worked = 0.0;

                foreach ($sessions as $session) {
                    $worked += (float)$session["worked_hours"];
                }

                $dayNumber = (int)date("N", strtotime($date));
                $expected = $dayNumber <= 5 ? $targetHoursPerDay : 0.0;

                $balance = $worked - $expected;

                $totalWorked += $worked;
                $totalExpected += $expected;
                $totalBalance += $balance;

                $balanceClass = $balance >= 0 ? "plus" : "minus";
                $balanceText = ($balance >= 0 ? "+" : "") . number_format($balance, 2);
            ?>

            <tr class="<?= count($sessions) > 0 ? "" : "empty-day" ?>">
                <td><?= htmlspecialchars($date) ?></td>
                <td><?= htmlspecialchars($weekday) ?></td>

                <td>
                    <?php if (count($sessions) > 0): ?>
                        <?php foreach ($sessions as $session): ?>
                            <form method="post" class="session-form">
                                <input type="hidden" name="id" value="<?= (int)$session["id"] ?>">
                                <input type="hidden" name="user_id" value="<?= htmlspecialchars($userId) ?>">
                                <input type="hidden" name="month" value="<?= htmlspecialchars($month) ?>">

                                <input
                                    type="datetime-local"
                                    name="punch_in"
                                    value="<?= htmlspecialchars(str_replace(' ', 'T', substr($session["punch_in"], 0, 16))) ?>"
                                    required
                                >

                                —

                                <input
                                    type="datetime-local"
                                    name="punch_out"
                                    value="<?= htmlspecialchars(str_replace(' ', 'T', substr($session["punch_out"], 0, 16))) ?>"
                                    required
                                >

                                <button type="submit" name="action" value="update" class="save-button">
                                    Save
                                </button>

                                <button
                                    type="submit"
                                    name="action"
                                    value="delete"
                                    class="delete-button"
                                    onclick="return confirm('Delete this punch time?')"
                                >
                                    Delete
                                </button>
                            </form>
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

</body>
</html><?php
$dbFile = "/var/www/database/worktime.sqlite";

$pdo = new PDO("sqlite:" . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$userId = $_GET["user_id"] ?? "mustafa";
$month = $_GET["month"] ?? date("Y-m");

if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date("Y-m");
}

$targetHoursPerDay = 8.0;

$monthStart = $month . "-01";
$monthEnd = date("Y-m-d", strtotime($monthStart . " +1 month"));

$stmt = $pdo->prepare("
    SELECT
        date(punch_in) AS work_date,
        MIN(time(punch_in)) AS first_punch_in,
        MAX(time(punch_out)) AS last_punch_out,
        ROUND(SUM((julianday(punch_out) - julianday(punch_in)) * 24), 2) AS worked_hours,
        GROUP_CONCAT(
            time(punch_in) || ' - ' || time(punch_out),
            '<br>'
        ) AS punch_times
    FROM work_times
    WHERE user_id = ?
    AND punch_out IS NOT NULL
    AND date(punch_in) >= ?
    AND date(punch_in) < ?
    GROUP BY date(punch_in)
");

$stmt->execute([$userId, $monthStart, $monthEnd]);

$workData = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $workData[$row["work_date"]] = $row;
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

        input, button {
            padding: 8px 12px;
            font-size: 16px;
        }

        .print-button {
            margin-left: 10px;
            cursor: pointer;
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
        }

        .summary {
            margin-top: 30px;
            font-size: 18px;
            text-align: right;
        }

        @media print {
            .top-bar {
                display: none;
            }

            body {
                margin: 15mm;
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
        <button type="button" class="print-button" onclick="window.print()">Print Sheet</button>
    </form>
</div>

<table>
    <thead>
        <tr>
            <th>Date</th>
            <th>Weekday</th>
            <th>First Punch In</th>
            <th>Last Punch Out</th>
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

                $row = $workData[$date] ?? null;

                $worked = $row ? (float)$row["worked_hours"] : 0.0;

                // Count Monday-Friday as workdays, weekend expected hours = 0
                $dayNumber = (int)date("N", strtotime($date));
                $expected = $dayNumber <= 5 ? $targetHoursPerDay : 0.0;

                $balance = $worked - $expected;

                $totalWorked += $worked;
                $totalExpected += $expected;
                $totalBalance += $balance;

                $balanceClass = $balance >= 0 ? "plus" : "minus";
                $balanceText = ($balance >= 0 ? "+" : "") . number_format($balance, 2);
            ?>

            <tr class="<?= $row ? "" : "empty-day" ?>">
                <td><?= htmlspecialchars($date) ?></td>
                <td><?= htmlspecialchars($weekday) ?></td>
                <td><?= $row ? htmlspecialchars($row["first_punch_in"]) : "-" ?></td>
                <td><?= $row ? htmlspecialchars($row["last_punch_out"]) : "-" ?></td>
                <td><?= $row ? $row["punch_times"] : "-" ?></td>
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

</body>
</html>
