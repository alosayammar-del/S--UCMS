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
            g.id,
            g.assessment_type,
            g.score,
            g.max_score,
            g.assessment_date,
            c.course_code,
            c.course_name,
            a.title AS assignment_title
        FROM grades g
        INNER JOIN enrollments e
            ON e.id = g.enrollment_id
        INNER JOIN courses c
            ON c.id = e.course_id
        LEFT JOIN assignments a
            ON a.id = g.assignment_id
        WHERE e.student_id = :student_id
        ORDER BY g.assessment_date DESC, g.id DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['student_id' => $userId]);
    $grades = $stmt->fetchAll();

} catch (PDOException $e) {
    error_log($e->getMessage());
    $grades = [];
    $error = 'حدث خطأ أثناء تحميل الدرجات. يرجى المحاولة لاحقا.';
}

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$totalScore = 0;
$totalMaxScore = 0;

foreach ($grades as $grade) {
    $totalScore += (float) $grade['score'];
    $totalMaxScore += (float) $grade['max_score'];
}

$overallPercentage = $totalMaxScore > 0
    ? round(($totalScore / $totalMaxScore) * 100, 2)
    : 0;
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>الدرجات والتقييمات</title>

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

        .header, .card {
            background: #fff;
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

        .summary {
            background: #edf3fa;
            padding: 18px;
            border-radius: 10px;
            margin-bottom: 22px;
        }

        .summary strong {
            color: #183b66;
            font-size: 24px;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            min-width: 700px;
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
        <h1>الدرجات والتقييمات</h1>

        <div class="welcome">
            مرحبا، <?= e($fullName) ?>
        </div>

        <a class="back" href="dashboard.php">
            العودة إلى لوحة التحكم
        </a>
    </div>

    <div class="card">

        <h2>السجل الأكاديمي للدرجات</h2>

        <?php if (isset($error)): ?>

            <div class="error"><?= e($error) ?></div>

        <?php elseif (count($grades) > 0): ?>

            <div class="summary">
                إجمالي النسبة بناء على التقييمات المسجلة:
                <br>
                <strong><?= e($overallPercentage) ?>%</strong>
                <br>
                <?= e($totalScore) ?> من <?= e($totalMaxScore) ?>
            </div>

            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>المقرر</th>
                            <th>نوع التقييم</th>
                            <th>اسم الواجب</th>
                            <th>الدرجة</th>
                            <th>النسبة</th>
                            <th>تاريخ التقييم</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($grades as $grade): ?>
                            <?php
                                $percentage = (float) $grade['max_score'] > 0
                                    ? round(
                                        ((float) $grade['score'] /
                                        (float) $grade['max_score']) * 100,
                                        2
                                    )
                                    : 0;
                            ?>

                            <tr>
                                <td>
                                    <?= e($grade['course_code']) ?>
                                    -
                                    <?= e($grade['course_name']) ?>
                                </td>

                                <td><?= e($grade['assessment_type']) ?></td>

                                <td>
                                    <?= e($grade['assignment_title'] ?? 'تقييم عام') ?>
                                </td>

                                <td>
                                    <?= e($grade['score']) ?>
                                    /
                                    <?= e($grade['max_score']) ?>
                                </td>

                                <td><?= e($percentage) ?>%</td>

                                <td><?= e($grade['assessment_date']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php else: ?>

            <div class="empty">
                لا توجد درجات مسجلة حاليا.
                <br><br>
                ستظهر درجاتك هنا عند قيام عضو هيئة التدريس بإدخالها.
            </div>

        <?php endif; ?>

    </div>

</div>

</body>
</html>
