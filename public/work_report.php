<?php
$dbFile = "/var/www/database/worktime.sqlite";

$pdo = new PDO("sqlite:" . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$userId = $_GET["user_id"] ?? "mustafa";
$targetHoursPerDay = 8.0;

$stmt = $pdo->prepare("
    SELECT
        user_id,
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
    GROUP BY user_id, date(punch_in)
    ORDER BY work_date ASC
");

$stmt->execute([$userId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalWorked = 0;
$totalBalance = 0;
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

        .print-button {
            padding: 10px 20px;
            font-size: 16px;
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
<h2>User: <?= htmlspecialchars($userId) ?></h2>

<div class="top-bar">
    <button class="print-button" onclick="window.print()">Print Sheet</button>
</div>

<table>
    <thead>
        <tr>
            <th>Date</th>
            <th>First Punch In</th>
            <th>Last Punch Out</th>
            <th>Punch Times</th>
            <th>Worked Hours</th>
            <th>Expected Hours</th>
            <th>Balance</th>
        </tr>
    </thead>

    <tbody>
        <?php foreach ($rows as $row): ?>
            <?php
                $worked = (float)$row["worked_hours"];
                $balance = $worked - $targetHoursPerDay;

                $totalWorked += $worked;
                $totalBalance += $balance;

                $balanceClass = $balance >= 0 ? "plus" : "minus";
                $balanceText = ($balance >= 0 ? "+" : "") . number_format($balance, 2);
            ?>

            <tr>
                <td><?= htmlspecialchars($row["work_date"]) ?></td>
                <td><?= htmlspecialchars($row["first_punch_in"]) ?></td>
                <td><?= htmlspecialchars($row["last_punch_out"]) ?></td>
                <td><?= $row["punch_times"] ?></td>
                <td><?= number_format($worked, 2) ?></td>
                <td><?= number_format($targetHoursPerDay, 2) ?></td>
                <td class="<?= $balanceClass ?>">
                    <?= $balanceText ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<div class="summary">
    <p><strong>Total worked:</strong> <?= number_format($totalWorked, 2) ?> hours</p>

    <p>
        <strong>Total balance:</strong>
        <span class="<?= $totalBalance >= 0 ? "plus" : "minus" ?>">
            <?= ($totalBalance >= 0 ? "+" : "") . number_format($totalBalance, 2) ?> hours
        </span>
    </p>
</div>

</body>
</html>
