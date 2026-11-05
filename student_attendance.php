<?php
session_start();
require_once __DIR__ . '/config/database.php';

// التحقق من تسجيل الدخول
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// السماح للطلاب فقط
if (($_SESSION['role'] ?? '') !== 'student') {
    http_response_code(403);
    exit('غير مصرح لك بالوصول إلى هذه الصفحة.');
}

$userId = (int) $_SESSION['user_id'];
$fullName = $_SESSION['full_name'] ?? 'الطالب';

try {
    $sql = "
        SELECT
            at.id,
            at.session_date,
            at.attendance_status,
            at.notes,
            c.course_code,
            c.course_name
        FROM attendance at
        INNER JOIN enrollments e
            ON e.id = at.enrollment_id
        INNER JOIN courses c
            ON c.id = e.course_id
        WHERE e.student_id = :student_id
        ORDER BY at.session_date DESC, at.id DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['student_id' => $userId]);
    $records = $stmt->fetchAll();

} catch (PDOException $e) {
    error_log($e->getMessage());
    $records = [];
    $error = 'حدث خطأ أثناء تحميل سجل الحضور. يرجى المحاولة لاحقا.';
}

// حساب الإحصائيات
$stats = [
    'present' => 0,
    'absent' => 0,
    'late' => 0,
    'excused' => 0
];

foreach ($records as $record) {
    $status = $record['attendance_status'];

    if (isset($stats[$status])) {
        $stats[$status]++;
    }
}

$totalRecords = count($records);
$attended = $stats['present'] + $stats['late'];

$attendancePercentage = $totalRecords > 0
    ? round(($attended / $totalRecords) * 100, 2)
    : 0;

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function statusText($status) {
    $statuses = [
        'present' => 'حاضر',
        'absent' => 'غائب',
        'late' => 'متأخر',
        'excused' => 'غياب بعذر'
    ];

    return $statuses[$status] ?? $status;
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>الحضور والغياب</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 25px;
            font-family: Arial, sans-serif;
            background: #f3f6fb;
            color: #243247;
        }

        .container {
            max-width: 1050px;
            margin: auto;
        }

        .header, .card {
            background: white;
            padding: 24px;
            border-radius: 12px;
            margin-bottom: 22px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        h1 {
            color: #183b66;
            margin-top: 0;
        }

        .welcome {
            color: #64748b;
        }

        .back {
            display: inline-block;
            margin-top: 15px;
            padding: 10px 18px;
            background: #183b66;
            color: white;
            text-decoration: none;
            border-radius: 7px;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-bottom: 22px;
        }

        .stat {
            background: #edf3fa;
            padding: 18px;
            border-radius: 10px;
            text-align: center;
        }

        .stat span {
            display: block;
            color: #64748b;
            margin-bottom: 10px;
        }

        .stat strong {
            font-size: 25px;
            color: #183b66;
        }

        .percentage {
            background: #e5f6eb;
            color: #16723b;
            padding: 18px;
            border-radius: 10px;
            margin-bottom: 22px;
        }

        .percentage strong {
            font-size: 25px;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            min-width: 650px;
            border-collapse: collapse;
        }

        th, td {
            padding: 14px;
            text-align: right;
            border-bottom: 1px solid #e5eaf1;
        }

        th {
            background: #edf3fa;
            color: #183b66;
        }

        .empty {
            text-align: center;
            padding: 35px 15px;
            color: #64748b;
            background: #f8fafc;
            border-radius: 8px;
        }

        .error {
            color: #b42318;
            padding: 15px;
        }

        @media (max-width: 700px) {
            body {
                padding: 12px;
            }

            .header, .card {
                padding: 16px;
            }

            .stats {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>

<body>

<div class="container">

    <div class="header">
        <h1>الحضور والغياب</h1>

        <div class="welcome">
            مرحبا، <?= e($fullName) ?>
        </div>

        <a class="back" href="dashboard.php">
            العودة إلى لوحة التحكم
        </a>
    </div>

    <div class="card">

        <h2>إحصائيات الحضور</h2>

        <?php if (isset($error)): ?>

            <div class="error"><?= e($error) ?></div>

        <?php elseif ($totalRecords > 0): ?>

            <div class="stats">

                <div class="stat">
                    <span>الحضور</span>
                    <strong><?= $stats['present'] ?></strong>
                </div>

                <div class="stat">
                    <span>الغياب</span>
                    <strong><?= $stats['absent'] ?></strong>
                </div>

                <div class="stat">
                    <span>التأخير</span>
                    <strong><?= $stats['late'] ?></strong>
                </div>

                <div class="stat">
                    <span>الغياب بعذر</span>
                    <strong><?= $stats['excused'] ?></strong>
                </div>

            </div>

            <div class="percentage">
                نسبة الحضور (الحضور والتأخير):
                <br>
                <strong><?= e($attendancePercentage) ?>%</strong>
            </div>

            <h2>سجل الحضور التفصيلي</h2>

            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>المقرر</th>
                            <th>تاريخ المحاضرة</th>
                            <th>الحالة</th>
                            <th>الملاحظات</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($records as $record): ?>
                            <tr>
                                <td>
                                    <?= e($record['course_code']) ?>
                                    -
                                    <?= e($record['course_name']) ?>
                                </td>

                                <td><?= e($record['session_date']) ?></td>

                                <td>
                                    <?= e(statusText($record['attendance_status'])) ?>
                                </td>

                                <td>
                                    <?= e($record['notes'] ?? 'لا توجد ملاحظات') ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php else: ?>

            <div class="empty">
                لا توجد سجلات حضور أو غياب مسجلة حاليا.
                <br><br>
                ستظهر سجلاتك هنا عند قيام عضو هيئة التدريس بتسجيل الحضور.
            </div>

        <?php endif; ?>

    </div>

</div>

</body>
</html>
