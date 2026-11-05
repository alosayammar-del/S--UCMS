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
            c.course_code,
            c.course_name,
            c.credit_hours,
            e.semester,
            e.academic_year,
            e.status
        FROM enrollments e
        INNER JOIN courses c ON c.id = e.course_id
        WHERE e.student_id = :student_id
        ORDER BY c.course_code
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['student_id' => $userId]);
    $courses = $stmt->fetchAll();

} catch (PDOException $e) {
    error_log($e->getMessage());
    $courses = [];
    $error = 'حدث خطأ أثناء تحميل المقررات. يرجى المحاولة لاحقا.';
}

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function statusText($status) {
    $statuses = [
        'enrolled' => 'مسجل',
        'completed' => 'مكتمل',
        'withdrawn' => 'منسحب'
    ];

    return $statuses[$status] ?? $status;
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>المقررات الدراسية</title>

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
            max-width: 1000px;
            margin: auto;
        }

        .header {
            background: #ffffff;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 22px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        h1 {
            margin: 0 0 12px;
            color: #183b66;
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

        .card {
            background: white;
            padding: 22px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 650px;
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

        .status {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            background: #e5f6eb;
            color: #16723b;
        }

        .empty {
            text-align: center;
            padding: 30px;
            color: #64748b;
        }

        .error {
            color: #b42318;
            padding: 15px;
        }

        @media (max-width: 600px) {
            body {
                padding: 12px;
            }

            .header, .card {
                padding: 16px;
            }
        }
    </style>
</head>

<body>

<div class="container">

    <div class="header">
        <h1>المقررات الدراسية</h1>
        <div class="welcome">
            مرحبا، <?= e($fullName) ?>
        </div>

        <a class="back" href="dashboard.php">العودة إلى لوحة التحكم</a>
    </div>

    <div class="card">

        <h2>قائمة المقررات المسجلة</h2>

        <?php if (isset($error)): ?>

            <div class="error"><?= e($error) ?></div>

        <?php elseif (count($courses) > 0): ?>

            <table>
                <thead>
                    <tr>
                        <th>رمز المقرر</th>
                        <th>اسم المقرر</th>
                        <th>الساعات</th>
                        <th>الفصل الدراسي</th>
                        <th>العام الأكاديمي</th>
                        <th>حالة التسجيل</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($courses as $course): ?>
                        <tr>
                            <td><?= e($course['course_code']) ?></td>
                            <td><?= e($course['course_name']) ?></td>
                            <td><?= e($course['credit_hours']) ?></td>
                            <td><?= e($course['semester']) ?></td>
                            <td><?= e($course['academic_year']) ?></td>
                            <td>
                                <span class="status">
                                    <?= e(statusText($course['status'])) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

        <?php else: ?>

            <div class="empty">
                لا توجد مقررات مسجلة في حسابك حاليا.
            </div>

        <?php endif; ?>

    </div>

</div>

</body>
</html>
