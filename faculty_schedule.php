<?php
session_start();

require_once __DIR__ . '/config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

if (($_SESSION['role'] ?? '') !== 'faculty') {
    header('Location: dashboard.php');
    exit;
}

$facultyId = (int) $_SESSION['user_id'];
$fullName = $_SESSION['full_name'] ?? 'عضو هيئة التدريس';

$error = '';
$schedules = [];

try {
    $sql = "
        SELECT
            s.id,
            s.semester,
            s.academic_year,
            s.day_of_week,
            s.start_time,
            s.end_time,
            c.course_code,
            c.course_name,
            cl.room_code,
            cl.building
        FROM schedules s
        INNER JOIN courses c
            ON s.course_id = c.id
        LEFT JOIN classrooms cl
            ON s.classroom_id = cl.id
        WHERE s.faculty_user_id = :faculty_id
        ORDER BY
            s.academic_year DESC,
            s.semester,
            s.day_of_week,
            s.start_time
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['faculty_id' => $facultyId]);
    $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error = 'تعذر تحميل الجداول الدراسية حاليا.';
}

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>الجداول الدراسية | S-UCMS</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Tahoma, Arial, sans-serif;
            background: #f3f6fb;
            color: #263445;
        }

        header {
            background: #172b4d;
            color: white;
            padding: 22px 7%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        header h1 {
            margin: 0;
            font-size: 22px;
        }

        header p {
            margin: 8px 0 0;
            color: #d5deeb;
            font-size: 13px;
        }

        .back-link {
            color: white;
            text-decoration: none;
            background: #29466f;
            padding: 10px 17px;
            border-radius: 8px;
            font-size: 13px;
        }

        .container {
            width: 86%;
            max-width: 1200px;
            margin: 35px auto;
        }

        .page-title {
            margin-bottom: 22px;
        }

        .page-title h2 {
            margin: 0 0 9px;
            color: #172b4d;
            font-size: 23px;
        }

        .page-title p {
            margin: 0;
            color: #718096;
            font-size: 14px;
        }

        .info-card {
            background: white;
            border-radius: 12px;
            padding: 22px;
            margin-bottom: 22px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.04);
        }

        .info-card h3 {
            margin: 0 0 12px;
            color: #172b4d;
            font-size: 17px;
        }

        .info-card p {
            margin: 0;
            color: #64748b;
            font-size: 14px;
        }

        .table-container {
            background: white;
            border-radius: 12px;
            overflow-x: auto;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.04);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 750px;
        }

        th {
            background: #eaf0f8;
            color: #172b4d;
            padding: 16px 12px;
            font-size: 13px;
            text-align: center;
            white-space: nowrap;
        }

        td {
            padding: 15px 12px;
            text-align: center;
            border-bottom: 1px solid #edf0f5;
            font-size: 13px;
        }

        tr:last-child td {
            border-bottom: none;
        }

        tr:hover td {
            background: #f8faff;
        }

        .course-code {
            color: #245a9b;
            font-weight: bold;
        }

        .empty-state,
        .error-state {
            background: white;
            padding: 35px 20px;
            text-align: center;
            border-radius: 12px;
            color: #718096;
            line-height: 1.8;
        }

        .error-state {
            color: #b42318;
        }

        footer {
            text-align: center;
            padding: 25px;
            color: #8793a4;
            font-size: 12px;
        }

        @media (max-width: 600px) {
            header {
                padding: 20px;
            }

            .container {
                width: 94%;
                margin: 25px auto;
            }

            header h1 {
                font-size: 18px;
            }
        }
    </style>
</head>

<body>

<header>
    <div>
        <h1>نظام إدارة الحرم الجامعي الذكي</h1>
        <p>بوابة عضو هيئة التدريس</p>
    </div>

    <a class="back-link" href="dashboard.php">
        العودة إلى لوحة التحكم
    </a>
</header>

<main class="container">

    <div class="page-title">
        <h2>الجداول الدراسية</h2>
        <p>عرض مواعيد المحاضرات والمقررات والقاعات الدراسية المسندة إليك.</p>
    </div>

    <section class="info-card">
        <h3>مرحبا، <?= e($fullName) ?></h3>
        <p>يمكنك الاطلاع على تفاصيل جدولك الدراسي من خلال هذه الصفحة.</p>
    </section>

    <?php if ($error): ?>

        <div class="error-state">
            <?= e($error) ?>
        </div>

    <?php elseif (empty($schedules)): ?>

        <div class="empty-state">
            <h3>لا توجد جداول دراسية حاليا</h3>
            <p>لم يتم العثور على محاضرات مسندة إلى حسابك.</p>
        </div>

    <?php else: ?>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>المقرر</th>
                        <th>رمز المقرر</th>
                        <th>اليوم</th>
                        <th>وقت البداية</th>
                        <th>وقت النهاية</th>
                        <th>القاعة</th>
                        <th>المبنى</th>
                        <th>الفصل الدراسي</th>
                        <th>العام الأكاديمي</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($schedules as $schedule): ?>
                        <tr>
                            <td><?= e($schedule['course_name']) ?></td>
                            <td class="course-code">
                                <?= e($schedule['course_code']) ?>
                            </td>
                            <td><?= e($schedule['day_of_week']) ?></td>
                            <td><?= e($schedule['start_time']) ?></td>
                            <td><?= e($schedule['end_time']) ?></td>
                            <td><?= e($schedule['room_code'] ?: 'غير محدد') ?></td>
                            <td><?= e($schedule['building'] ?: 'غير محدد') ?></td>
                            <td><?= e($schedule['semester']) ?></td>
                            <td><?= e($schedule['academic_year']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    <?php endif; ?>

</main>

<footer>
    S-UCMS - Smart University Campus Management System
</footer>

</body>
</html>