<?php
session_start();

require_once __DIR__ . '/config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$role = $_SESSION['role'] ?? '';

if ($role !== 'faculty') {
    header('Location: dashboard.php');
    exit;
}

try {
    $userStmt = $pdo->prepare("
        SELECT id, full_name, email, status
        FROM users
        WHERE id = :user_id AND role = 'faculty'
        LIMIT 1
    ");
    $userStmt->execute(['user_id' => $userId]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || $user['status'] !== 'active') {
        session_destroy();
        header('Location: index.php');
        exit;
    }

    $facultyStmt = $pdo->prepare("
        SELECT employee_number, department, academic_title
        FROM faculty_members
        WHERE user_id = :user_id
        LIMIT 1
    ");
    $facultyStmt->execute(['user_id' => $userId]);
    $faculty = $facultyStmt->fetch(PDO::FETCH_ASSOC);

    $coursesStmt = $pdo->prepare("
        SELECT
            c.id,
            c.course_code,
            c.course_name,
            c.credit_hours,
            ci.semester,
            ci.academic_year,
            ci.assigned_at
        FROM course_instructors ci
        INNER JOIN courses c ON c.id = ci.course_id
        WHERE ci.faculty_user_id = :faculty_id
        ORDER BY ci.academic_year DESC, ci.semester, c.course_code
    ");
    $coursesStmt->execute(['faculty_id' => $userId]);
    $courses = $coursesStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log($e->getMessage());
    $error = 'تعذر تحميل المقررات حاليا. يرجى المحاولة مرة أخرى.';
}

function e($value) {
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مقرراتي الدراسية | S-UCMS</title>

    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: Tahoma, Arial, sans-serif;
        }

        body {
            background: #f4f6fa;
            color: #243247;
            min-height: 100vh;
        }

        .header {
            background: #173b63;
            color: white;
            padding: 22px 6%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }

        .header h2 {
            font-size: 21px;
        }

        .header a {
            color: white;
            text-decoration: none;
            background: #285782;
            padding: 10px 17px;
            border-radius: 8px;
        }

        .container {
            max-width: 1150px;
            margin: 35px auto;
            padding: 0 20px;
        }

        .welcome {
            margin-bottom: 25px;
        }

        .welcome h1 {
            font-size: 27px;
            margin-bottom: 10px;
            color: #173b63;
        }

        .welcome p {
            color: #68778a;
            line-height: 1.8;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 28px;
        }

        .info-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 3px 12px rgba(0,0,0,.05);
        }

        .info-card span {
            display: block;
            color: #778397;
            font-size: 14px;
            margin-bottom: 10px;
        }

        .info-card strong {
            font-size: 17px;
            color: #173b63;
        }

        .section {
            background: white;
            padding: 24px;
            border-radius: 13px;
            box-shadow: 0 3px 12px rgba(0,0,0,.05);
        }

        .section h2 {
            color: #173b63;
            margin-bottom: 20px;
            font-size: 20px;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 700px;
        }

        th, td {
            padding: 15px 12px;
            text-align: right;
            border-bottom: 1px solid #edf0f4;
        }

        th {
            background: #f1f5f9;
            color: #173b63;
            font-size: 14px;
        }

        td {
            font-size: 14px;
        }

        .course-code {
            color: #1765a0;
            font-weight: bold;
        }

        .empty, .error {
            padding: 25px;
            text-align: center;
            border-radius: 9px;
            line-height: 1.8;
        }

        .empty {
            background: #f5f8fc;
            color: #68778a;
        }

        .error {
            background: #fff1f0;
            color: #b42318;
            margin-bottom: 20px;
        }

        .footer {
            text-align: center;
            padding: 25px;
            color: #8993a2;
            font-size: 13px;
        }

        @media (max-width: 600px) {
            .header {
                padding: 18px 20px;
            }

            .welcome h1 {
                font-size: 23px;
            }

            .section {
                padding: 16px;
            }
        }
    </style>
</head>

<body>

<header class="header">
    <h2>S-UCMS | بوابة عضو هيئة التدريس</h2>
    <a href="dashboard.php">العودة إلى لوحة التحكم</a>
</header>

<main class="container">

    <section class="welcome">
        <h1>مقرراتي الدراسية</h1>
        <p>عرض المقررات الدراسية المسندة إليك والفصول الأكاديمية المرتبطة بها.</p>
    </section>

    <?php if (isset($error)): ?>
        <div class="error"><?= e($error) ?></div>
    <?php else: ?>

        <section class="info-grid">

            <div class="info-card">
                <span>اسم عضو هيئة التدريس</span>
                <strong><?= e($user['full_name']) ?></strong>
            </div>

            <div class="info-card">
                <span>الرقم الوظيفي</span>
                <strong><?= e($faculty['employee_number'] ?? 'غير مسجل') ?></strong>
            </div>

            <div class="info-card">
                <span>القسم الأكاديمي</span>
                <strong><?= e($faculty['department'] ?? 'غير محدد') ?></strong>
            </div>

            <div class="info-card">
                <span>إجمالي المقررات المسندة</span>
                <strong><?= count($courses) ?> مقرر</strong>
            </div>

        </section>

        <section class="section">
            <h2>قائمة المقررات المسندة إليك</h2>

            <?php if (empty($courses)): ?>

                <div class="empty">
                    لا توجد مقررات دراسية مسندة إليك حاليا.
                </div>

            <?php else: ?>

                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>رمز المقرر</th>
                                <th>اسم المقرر</th>
                                <th>الساعات المعتمدة</th>
                                <th>الفصل الدراسي</th>
                                <th>السنة الأكاديمية</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($courses as $index => $course): ?>
                                <tr>
                                    <td><?= $index + 1 ?></td>
                                    <td class="course-code"><?= e($course['course_code']) ?></td>
                                    <td><?= e($course['course_name']) ?></td>
                                    <td><?= e($course['credit_hours']) ?></td>
                                    <td><?= e($course['semester']) ?></td>
                                    <td><?= e($course['academic_year']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <?php endif; ?>
        </section>

    <?php endif; ?>

</main>

<footer class="footer">
    نظام إدارة الحرم الجامعي الذكي والمتكامل S-UCMS
</footer>

</body>
</html>
