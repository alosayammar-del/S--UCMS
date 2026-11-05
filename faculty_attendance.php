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

$selectedCourse = (int) ($_GET['course_id'] ?? $_POST['course_id'] ?? 0);
$selectedDate = $_GET['date'] ?? $_POST['session_date'] ?? date('Y-m-d');

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$courses = [];
$students = [];

try {

    // جلب المقررات المسندة إلى عضو هيئة التدريس
    $stmt = $pdo->prepare("
        SELECT DISTINCT
            c.id,
            c.course_code,
            c.course_name
        FROM course_instructors ci
        INNER JOIN courses c
            ON c.id = ci.course_id
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
    }

    // حفظ سجلات الحضور
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        if (!hash_equals(
            $_SESSION['csrf_token'],
            $_POST['csrf_token'] ?? ''
        )) {
            throw new Exception(
                'انتهت صلاحية الطلب. يرجى تحديث الصفحة والمحاولة مجددا.'
            );
        }

        if (!$courseExists) {
            throw new Exception(
                'المقرر المحدد غير مسند إلى حسابك.'
            );
        }

        $dateObject = DateTime::createFromFormat('Y-m-d', $selectedDate);

        if (
            !$dateObject ||
            $dateObject->format('Y-m-d') !== $selectedDate
        ) {
            throw new Exception('تاريخ المحاضرة غير صحيح.');
        }

        $attendanceData = $_POST['attendance'] ?? [];

        if (empty($attendanceData)) {
            throw new Exception('لم يتم تحديد حالات الحضور.');
        }

        $allowedStatuses = [
            'present',
            'absent',
            'late',
            'excused'
        ];

        $pdo->beginTransaction();

        foreach ($attendanceData as $enrollmentId => $data) {

            $enrollmentId = (int)$enrollmentId;

            $status = $data['status'] ?? '';
            $notes = trim($data['notes'] ?? '');

            if (!in_array($status, $allowedStatuses, true)) {
                continue;
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

            // التحقق من وجود سجل سابق
            $existing = $pdo->prepare("
                SELECT id
                FROM attendance
                WHERE enrollment_id = ?
                  AND session_date = ?
                LIMIT 1
            ");

            $existing->execute([
                $enrollmentId,
                $selectedDate
            ]);

            $existingRecord = $existing->fetch(PDO::FETCH_ASSOC);

            if ($existingRecord) {

                // تحديث سجل الحضور السابق
                $update = $pdo->prepare("
                    UPDATE attendance
                    SET attendance_status = ?,
                        notes = ?
                    WHERE id = ?
                ");

                $update->execute([
                    $status,
                    $notes,
                    $existingRecord['id']
                ]);

            } else {

                // إنشاء سجل حضور جديد
                $insert = $pdo->prepare("
                    INSERT INTO attendance
                        (enrollment_id, session_date, attendance_status, notes)
                    VALUES (?, ?, ?, ?)
                ");

                $insert->execute([
                    $enrollmentId,
                    $selectedDate,
                    $status,
                    $notes
                ]);
            }
        }

        $pdo->commit();

        $success = 'تم حفظ سجلات الحضور بنجاح.';
    }

    // جلب الطلاب المسجلين في المقرر
    if ($selectedCourse > 0) {

        $stmt = $pdo->prepare("
            SELECT
                e.id AS enrollment_id,
                st.student_number,
                u.full_name,
                a.attendance_status,
                a.notes
            FROM enrollments e
            INNER JOIN students st
                ON st.user_id = e.student_id
            INNER JOIN users u
                ON u.id = st.user_id
            LEFT JOIN attendance a
                ON a.enrollment_id = e.id
                AND a.session_date = ?
            WHERE e.course_id = ?
            ORDER BY u.full_name
        ");

        $stmt->execute([
            $selectedDate,
            $selectedCourse
        ]);

        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (Exception $e) {

    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $error = $e->getMessage();

} catch (PDOException $e) {

    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $error = 'حدث خطأ أثناء تنفيذ العملية. يرجى المحاولة مرة أخرى.';
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>إدارة الحضور | S-UCMS</title>

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
            min-width: 200px;
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
            min-width: 750px;
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
        <h2>إدارة الحضور</h2>
        <p>متابعة حضور الطلاب وتسجيل حالات الحضور للمقررات الدراسية.</p>
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

    <section class="card">
        <h3>اختيار المقرر وتاريخ المحاضرة</h3>

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
                <label for="session_date">تاريخ المحاضرة</label>

                <input
                    type="date"
                    name="date"
                    id="session_date"
                    value="<?= e($selectedDate) ?>"
                    required
                >
            </div>

            <button type="submit">عرض الطلاب</button>

        </form>
    </section>

    <?php if (empty($courses)): ?>

        <div class="empty-state">
            <h3>لا توجد مقررات مسندة إليك</h3>
            <p>لا يمكن تسجيل الحضور قبل إسناد مقرر إلى حسابك.</p>
        </div>

    <?php elseif (empty($students)): ?>

        <div class="empty-state">
            <h3>لا يوجد طلاب مسجلون في هذا المقرر</h3>
            <p>لم يتم العثور على طلاب مسجلين في المقرر المحدد.</p>
        </div>

    <?php else: ?>

        <section class="card">
            <h3>سجل حضور الطلاب</h3>

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
                    name="session_date"
                    value="<?= e($selectedDate) ?>"
                >

                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>رقم الطالب</th>
                                <th>اسم الطالب</th>
                                <th>حالة الحضور</th>
                                <th>ملاحظات</th>
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
                                        <select
                                            name="attendance[<?= (int)$student['enrollment_id'] ?>][status]"
                                            required
                                        >
                                            <option value="present"
                                                <?= ($student['attendance_status'] ?? '') === 'present' ? 'selected' : '' ?>>
                                                حاضر
                                            </option>

                                            <option value="absent"
                                                <?= ($student['attendance_status'] ?? '') === 'absent' ? 'selected' : '' ?>>
                                                غائب
                                            </option>

                                            <option value="late"
                                                <?= ($student['attendance_status'] ?? '') === 'late' ? 'selected' : '' ?>>
                                                متأخر
                                            </option>

                                            <option value="excused"
                                                <?= ($student['attendance_status'] ?? '') === 'excused' ? 'selected' : '' ?>>
                                                بعذر
                                            </option>
                                        </select>
                                    </td>

                                    <td>
                                        <input
                                            type="text"
                                            name="attendance[<?= (int)$student['enrollment_id'] ?>][notes]"
                                            value="<?= e($student['notes']) ?>"
                                            placeholder="ملاحظات اختيارية"
                                        >
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <button type="submit" class="save-button">
                    حفظ سجلات الحضور
                </button>

            </form>
        </section>

    <?php endif; ?>

</main>

<footer>
    S-UCMS - Smart University Campus Management System
</footer>

</body>
</html>