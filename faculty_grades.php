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

$success = '';
$error = '';

$selectedCourse = (int)($_GET['course_id'] ?? $_POST['course_id'] ?? 0);
$assessmentType = $_GET['assessment_type'] ?? $_POST['assessment_type'] ?? 'اختبار';
$assessmentDate = $_GET['assessment_date'] ?? $_POST['assessment_date'] ?? date('Y-m-d');
$maxScore = $_POST['max_score'] ?? '20';

$courses = [];
$students = [];

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$allowedTypes = ['اختبار', 'واجب', 'أعمال فصلية', 'اختبار نهائي'];

try {

    // جلب المقررات المسندة إلى عضو هيئة التدريس
    $stmt = $pdo->prepare("
        SELECT DISTINCT
            c.id,
            c.course_code,
            c.course_name
        FROM course_instructors ci
        INNER JOIN courses c ON c.id = ci.course_id
        WHERE ci.faculty_user_id = ?
        ORDER BY c.course_name
    ");

    $stmt->execute([$facultyId]);
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // التحقق من المقرر المحدد
    $courseExists = false;

    foreach ($courses as $course) {
        if ((int)$course['id'] === $selectedCourse) {
            $courseExists = true;
            break;
        }
    }

    if (!$courseExists && !empty($courses)) {
        $selectedCourse = (int)$courses[0]['id'];
        $courseExists = true;
    }

    // حفظ الدرجات
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        if (!hash_equals(
            $_SESSION['csrf_token'],
            $_POST['csrf_token'] ?? ''
        )) {
            throw new Exception('انتهت صلاحية الطلب. يرجى تحديث الصفحة.');
        }

        if (!$courseExists) {
            throw new Exception('المقرر المحدد غير مسند إلى حسابك.');
        }

        if (!in_array($assessmentType, $allowedTypes, true)) {
            throw new Exception('نوع التقييم غير صحيح.');
        }

        $dateObject = DateTime::createFromFormat('Y-m-d', $assessmentDate);

        if (
            !$dateObject ||
            $dateObject->format('Y-m-d') !== $assessmentDate
        ) {
            throw new Exception('تاريخ التقييم غير صحيح.');
        }

        if (!is_numeric($maxScore) || (float)$maxScore <= 0) {
            throw new Exception('الدرجة الكاملة يجب أن تكون أكبر من صفر.');
        }

        $maxScoreValue = (float)$maxScore;
        $gradeData = $_POST['grades'] ?? [];

        if (empty($gradeData)) {
            throw new Exception('لم يتم إدخال أي درجات.');
        }

        $pdo->beginTransaction();

        foreach ($gradeData as $enrollmentId => $scoreValue) {

            $enrollmentId = (int)$enrollmentId;

            if ($scoreValue === '') {
                continue;
            }

            if (!is_numeric($scoreValue)) {
                throw new Exception('إحدى الدرجات المدخلة غير صحيحة.');
            }

            $score = (float)$scoreValue;

            if ($score < 0 || $score > $maxScoreValue) {
                throw new Exception(
                    'يجب أن تكون الدرجة بين صفر والدرجة الكاملة.'
                );
            }

            // التأكد من أن تسجيل الطالب تابع لمقرر عضو هيئة التدريس
            $check = $pdo->prepare("
                SELECT e.id
                FROM enrollments e
                INNER JOIN course_instructors ci
                    ON ci.course_id = e.course_id
                WHERE e.id = ?
                  AND e.course_id = ?
                  AND ci.faculty_user_id = ?
                LIMIT 1
            ");

            $check->execute([
                $enrollmentId,
                $selectedCourse,
                $facultyId
            ]);

            if (!$check->fetch()) {
                continue;
            }

            // البحث عن درجة سابقة لنفس التقييم والتاريخ
            $existing = $pdo->prepare("
                SELECT id
                FROM grades
                WHERE enrollment_id = ?
                  AND assessment_type = ?
                  AND assessment_date = ?
                  AND assignment_id IS NULL
                ORDER BY id DESC
                LIMIT 1
            ");

            $existing->execute([
                $enrollmentId,
                $assessmentType,
                $assessmentDate
            ]);

            $existingGrade = $existing->fetch(PDO::FETCH_ASSOC);

            if ($existingGrade) {

                // تحديث الدرجة السابقة
                $update = $pdo->prepare("
                    UPDATE grades
                    SET score = ?,
                        max_score = ?
                    WHERE id = ?
                ");

                $update->execute([
                    $score,
                    $maxScoreValue,
                    $existingGrade['id']
                ]);

            } else {

                // إضافة درجة جديدة
                $insert = $pdo->prepare("
                    INSERT INTO grades
                        (
                            enrollment_id,
                            assignment_id,
                            assessment_type,
                            score,
                            max_score,
                            assessment_date
                        )
                    VALUES (?, NULL, ?, ?, ?, ?)
                ");

                $insert->execute([
                    $enrollmentId,
                    $assessmentType,
                    $score,
                    $maxScoreValue,
                    $assessmentDate
                ]);
            }
        }

        $pdo->commit();

        $success = 'تم حفظ الدرجات بنجاح.';
    }

    // جلب الطلاب ودرجات التقييم المحدد
    if ($selectedCourse > 0) {

        $stmt = $pdo->prepare("
            SELECT
                e.id AS enrollment_id,
                st.student_number,
                u.full_name,
                g.score,
                g.max_score
            FROM enrollments e

            INNER JOIN students st
                ON st.user_id = e.student_id

            INNER JOIN users u
                ON u.id = st.user_id

            LEFT JOIN (
                SELECT
                    enrollment_id,
                    MAX(id) AS max_id
                FROM grades
                WHERE assessment_type = ?
                  AND assessment_date = ?
                  AND assignment_id IS NULL
                GROUP BY enrollment_id
            ) latest
                ON latest.enrollment_id = e.id

            LEFT JOIN grades g
                ON g.id = latest.max_id

            WHERE e.course_id = ?

            ORDER BY u.full_name
        ");

        $stmt->execute([
            $assessmentType,
            $assessmentDate,
            $selectedCourse
        ]);

        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (PDOException $e) {

    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $error = 'حدث خطأ في قاعدة البيانات: ' . $e->getMessage();

} catch (Exception $e) {

    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $error = $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>الدرجات والتقييمات | S-UCMS</title>

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

        .card {
            background: white;
            border-radius: 12px;
            padding: 22px;
            margin-bottom: 22px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.04);
        }

        .card h3 {
            margin: 0 0 18px;
            color: #172b4d;
            font-size: 17px;
        }

        .filters {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: end;
        }

        .field {
            flex: 1;
            min-width: 190px;
        }

        label {
            display: block;
            margin-bottom: 9px;
            font-size: 13px;
            font-weight: bold;
        }

        input,
        select {
            width: 100%;
            padding: 12px;
            border: 1px solid #dce3ed;
            border-radius: 8px;
            background: white;
            font-family: inherit;
            font-size: 13px;
        }

        button {
            border: none;
            border-radius: 8px;
            padding: 12px 22px;
            background: #245a9b;
            color: white;
            font-family: inherit;
            font-size: 13px;
            cursor: pointer;
        }

        button:hover {
            background: #194779;
        }

        .save-button {
            margin-top: 20px;
            background: #16845b;
        }

        .save-button:hover {
            background: #116b49;
        }

        .table-container {
            overflow-x: auto;
        }

        table {
            width: 100%;
            min-width: 650px;
            border-collapse: collapse;
        }

        th {
            background: #eaf0f8;
            color: #172b4d;
            padding: 15px 10px;
            font-size: 13px;
            text-align: center;
        }

        td {
            padding: 13px 10px;
            text-align: center;
            border-bottom: 1px solid #edf0f5;
            font-size: 13px;
        }

        tr:last-child td {
            border-bottom: none;
        }

        .message {
            padding: 14px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .success {
            background: #e7f7ef;
            color: #16734b;
        }

        .error {
            background: #fff0ef;
            color: #b42318;
            overflow-wrap: anywhere;
        }

        .empty-state {
            text-align: center;
            padding: 35px 20px;
            color: #718096;
            background: white;
            border-radius: 12px;
            line-height: 1.8;
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
        <h2>الدرجات والتقييمات</h2>
        <p>إدارة درجات الطلاب ومتابعة نتائج التقييمات الأكاديمية.</p>
    </div>

    <?php if ($success): ?>
        <div class="message success">
            <?= e($success) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="message error">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <?php if (empty($courses)): ?>

        <div class="empty-state">
            <h3>لا توجد مقررات مسندة إليك</h3>
            <p>لا يمكن إدارة الدرجات قبل إسناد مقرر إلى حسابك.</p>
        </div>

    <?php else: ?>

        <section class="card">
            <h3>إعدادات التقييم</h3>

            <form method="GET" class="filters">

                <div class="field">
                    <label for="course_id">المقرر الدراسي</label>

                    <select name="course_id" id="course_id" required>
                        <?php foreach ($courses as $course): ?>
                            <option
                                value="<?= (int)$course['id'] ?>"
                                <?= $selectedCourse === (int)$course['id'] ? 'selected' : '' ?>
                            >
                                <?= e($course['course_code']) ?>
                                - <?= e($course['course_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="assessment_type">نوع التقييم</label>

                    <select name="assessment_type" id="assessment_type">
                        <?php foreach ($allowedTypes as $type): ?>
                            <option
                                value="<?= e($type) ?>"
                                <?= $assessmentType === $type ? 'selected' : '' ?>
                            >
                                <?= e($type) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="assessment_date">تاريخ التقييم</label>

                    <input
                        type="date"
                        name="assessment_date"
                        id="assessment_date"
                        value="<?= e($assessmentDate) ?>"
                        required
                    >
                </div>

                <button type="submit">عرض الطلاب</button>

            </form>
        </section>

        <?php if (empty($students)): ?>

            <div class="empty-state">
                <h3>لا يوجد طلاب مسجلون في هذا المقرر</h3>
                <p>لم يتم العثور على طلاب في المقرر المحدد.</p>
            </div>

        <?php else: ?>

            <section class="card">
                <h3>إدخال درجات الطلاب</h3>

                <form method="POST">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e($_SESSION['csrf_token']) ?>"
                    >

                    <input
                        type="hidden"
                        name="course_id"
                        value="<?= (int)$selectedCourse ?>"
                    >

                    <input
                        type="hidden"
                        name="assessment_type"
                        value="<?= e($assessmentType) ?>"
                    >

                    <input
                        type="hidden"
                        name="assessment_date"
                        value="<?= e($assessmentDate) ?>"
                    >

                    <div class="field" style="max-width:300px; margin-bottom:20px;">
                        <label for="max_score">الدرجة الكاملة للتقييم</label>

                        <input
                            type="number"
                            name="max_score"
                            id="max_score"
                            min="0.01"
                            step="0.01"
                            value="<?= e($maxScore) ?>"
                            required
                        >
                    </div>

                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>الرقم الجامعي</th>
                                    <th>اسم الطالب</th>
                                    <th>الدرجة</th>
                                    <th>الدرجة الكاملة</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach ($students as $student): ?>
                                    <tr>
                                        <td>
                                            <?= e($student['student_number']) ?>
                                        </td>

                                        <td>
                                            <?= e($student['full_name']) ?>
                                        </td>

                                        <td>
                                            <input
                                                type="number"
                                                name="grades[<?= (int)$student['enrollment_id'] ?>]"
                                                min="0"
                                                max="<?= e($maxScore) ?>"
                                                step="0.01"
                                                value="<?= e($student['score']) ?>"
                                                placeholder="أدخل الدرجة"
                                            >
                                        </td>

                                        <td>
                                            <?= e($maxScore) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <button type="submit" class="save-button">
                        حفظ الدرجات
                    </button>

                </form>
            </section>

        <?php endif; ?>

    <?php endif; ?>

</main>

<footer>
    S-UCMS - Smart University Campus Management System
</footer>

</body>
</html>