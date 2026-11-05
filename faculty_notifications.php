<?php
session_start();

require_once __DIR__ . '/config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

if (($_SESSION['role'] ?? '') !== 'faculty') {
    http_response_code(403);
    exit('غير مصرح لك بالوصول إلى هذه الصفحة.');
}

$facultyId = (int) $_SESSION['user_id'];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

$success = '';
$error = '';
$courses = [];

try {
    $coursesStmt = $pdo->prepare("
        SELECT DISTINCT c.id, c.course_code, c.course_name
        FROM course_instructors ci
        INNER JOIN courses c ON c.id = ci.course_id
        WHERE ci.faculty_user_id = ?
        ORDER BY c.course_name
    ");

    $coursesStmt->execute([$facultyId]);
    $courses = $coursesStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error = 'تعذر تحميل المقررات الدراسية.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';
    $courseId = (int) ($_POST['course_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if (!hash_equals($csrfToken, $token)) {

        $error = 'انتهت صلاحية الطلب. أعد تحميل الصفحة وحاول مرة أخرى.';

    } elseif ($courseId <= 0 || $title === '' || $message === '') {

        $error = 'يرجى تعبئة جميع الحقول.';

    } elseif (mb_strlen($title) > 200) {

        $error = 'عنوان الإعلان يجب ألا يتجاوز 200 حرف.';

    } else {

        try {
            // التحقق من أن المقرر مسند إلى عضو هيئة التدريس
            $checkCourse = $pdo->prepare("
                SELECT id
                FROM course_instructors
                WHERE course_id = ?
                  AND faculty_user_id = ?
                LIMIT 1
            ");

            $checkCourse->execute([$courseId, $facultyId]);

            if (!$checkCourse->fetch()) {

                $error = 'هذا المقرر غير مسند إليك.';

            } else {

                // جلب الطلاب المسجلين في المقرر
                $studentsStmt = $pdo->prepare("
                    SELECT DISTINCT e.student_id
                    FROM enrollments e
                    WHERE e.course_id = ?
                      AND e.status = 'enrolled'
                ");

                $studentsStmt->execute([$courseId]);
                $studentIds = $studentsStmt->fetchAll(PDO::FETCH_COLUMN);

                if (empty($studentIds)) {

                    $error = 'لا يوجد طلاب مسجلون في هذا المقرر.';

                } else {

                    $insertStmt = $pdo->prepare("
                        INSERT INTO notifications
                        (
                            user_id,
                            title,
                            message,
                            notification_type,
                            related_entity_type,
                            related_entity_id,
                            is_read
                        )
                        VALUES (?, ?, ?, 'announcement', 'course', ?, 0)
                    ");

                    $pdo->beginTransaction();

                    foreach ($studentIds as $studentId) {

                        $insertStmt->execute([
                            (int) $studentId,
                            $title,
                            $message,
                            $courseId
                        ]);
                    }

                    $pdo->commit();

                    $success = 'تم إرسال الإعلان بنجاح إلى '
                        . count($studentIds) . ' طالب.';

                    $_POST = [];
                }

            }

        } catch (PDOException $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = 'حدث خطأ أثناء إرسال الإعلان. حاول مرة أخرى.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>الإعلانات والتنبيهات</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 25px;
            font-family: Tahoma, Arial, sans-serif;
            background: #f4f6fb;
            color: #253047;
        }

        .container {
            max-width: 850px;
            margin: 30px auto;
        }

        .header {
            margin-bottom: 25px;
        }

        .header h1 {
            margin-bottom: 8px;
            color: #1e3152;
            font-size: 25px;
        }

        .header p {
            color: #718096;
            line-height: 1.8;
        }

        .card {
            background: #fff;
            padding: 28px;
            border-radius: 14px;
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.05);
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 9px;
            font-weight: bold;
        }

        input,
        select,
        textarea {
            width: 100%;
            padding: 13px;
            border: 1px solid #dce2ec;
            border-radius: 8px;
            font-family: inherit;
            font-size: 15px;
            background: #fff;
        }

        textarea {
            min-height: 150px;
            resize: vertical;
            line-height: 1.8;
        }

        input:focus,
        select:focus,
        textarea:focus {
            outline: none;
            border-color: #3569b7;
        }

        button {
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: 8px;
            background: #285da8;
            color: #fff;
            font-family: inherit;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
        }

        button:hover {
            background: #1e4d91;
        }

        .message {
            padding: 14px;
            margin-bottom: 20px;
            border-radius: 8px;
            line-height: 1.8;
        }

        .success {
            background: #e8f7ee;
            color: #207344;
        }

        .error {
            background: #fdecec;
            color: #a83232;
        }

        .back {
            display: inline-block;
            margin-top: 20px;
            color: #285da8;
            text-decoration: none;
            font-weight: bold;
        }

        @media (max-width: 600px) {
            body {
                padding: 15px;
            }

            .card {
                padding: 20px;
            }
        }
    </style>
</head>

<body>

<div class="container">

    <div class="header">
        <h1>الإعلانات والتنبيهات</h1>

        <p>
            إرسال الإعلانات والتنبيهات الأكاديمية إلى الطلاب
            المسجلين في المقررات المسندة إليك.
        </p>
    </div>

    <div class="card">

        <?php if ($success): ?>

            <div class="message success">
                <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
            </div>

        <?php endif; ?>

        <?php if ($error): ?>

            <div class="message error">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>

        <?php endif; ?>

        <?php if (!empty($courses)): ?>

            <form method="POST">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"
                >

                <div class="form-group">

                    <label for="course_id">المقرر الدراسي</label>

                    <select name="course_id" id="course_id" required>

                        <option value="">اختر المقرر</option>

                        <?php foreach ($courses as $course): ?>

                            <option
                                value="<?= (int) $course['id'] ?>"
                                <?= (
                                    isset($_POST['course_id']) &&
                                    (int) $_POST['course_id'] === (int) $course['id']
                                ) ? 'selected' : '' ?>
                            >
                                <?= htmlspecialchars(
                                    $course['course_code'] . ' - ' . $course['course_name'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div class="form-group">

                    <label for="title">عنوان الإعلان</label>

                    <input
                        type="text"
                        name="title"
                        id="title"
                        maxlength="200"
                        required
                        value="<?= htmlspecialchars(
                            $_POST['title'] ?? '',
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>"
                        placeholder="أدخل عنوان الإعلان"
                    >

                </div>

                <div class="form-group">

                    <label for="message">محتوى الإعلان</label>

                    <textarea
                        name="message"
                        id="message"
                        required
                        placeholder="اكتب تفاصيل الإعلان أو التنبيه هنا..."
                    ><?= htmlspecialchars(
                        $_POST['message'] ?? '',
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?></textarea>

                </div>

                <button type="submit">
                    إرسال الإعلان إلى الطلاب
                </button>

            </form>

        <?php else: ?>

            <div class="message error">
                لا توجد مقررات مسندة إليك حاليا.
            </div>

        <?php endif; ?>

    </div>

    <a href="dashboard.php" class="back">
        العودة إلى لوحة التحكم
    </a>

</div>

</body>
</html>