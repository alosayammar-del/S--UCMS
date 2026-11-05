<?php
session_start();

require_once __DIR__ . '/config/database.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'administrator') {
    header('Location: index.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirectWithMessage($message, $type = 'success') {
    $_SESSION['flash_message'] = $message;
    $_SESSION['flash_type'] = $type;
    header('Location: admin_courses.php');
    exit;
}

$message = $_SESSION['flash_message'] ?? '';
$messageType = $_SESSION['flash_type'] ?? 'success';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($csrfToken, $_POST['csrf_token'] ?? '')) {
            redirectWithMessage('انتهت صلاحية الطلب، حاول مرة أخرى.', 'error');
        }

        $action = $_POST['action'] ?? '';

        if ($action === 'add_course' || $action === 'edit_course') {
            $courseId = (int) ($_POST['course_id'] ?? 0);
            $courseCode = trim($_POST['course_code'] ?? '');
            $courseName = trim($_POST['course_name'] ?? '');
            $creditHours = (int) ($_POST['credit_hours'] ?? 0);
            $description = trim($_POST['description'] ?? '');

            if ($courseCode === '' || $courseName === '' || $creditHours < 1 || $creditHours > 10) {
                redirectWithMessage('يرجى إدخال بيانات المقرر بشكل صحيح.', 'error');
            }

            if ($action === 'add_course') {
                $stmt = $pdo->prepare("
                    INSERT INTO courses
                    (course_code, course_name, credit_hours, description)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$courseCode, $courseName, $creditHours, $description]);

                redirectWithMessage('تمت إضافة المقرر بنجاح.');
            } else {
                if ($courseId <= 0) {
                    redirectWithMessage('رقم المقرر غير صحيح.', 'error');
                }

                $stmt = $pdo->prepare("
                    UPDATE courses
                    SET course_code = ?, course_name = ?,
                        credit_hours = ?, description = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $courseCode,
                    $courseName,
                    $creditHours,
                    $description,
                    $courseId
                ]);

                redirectWithMessage('تم تحديث بيانات المقرر بنجاح.');
            }
        }

        if ($action === 'delete_course') {
            $courseId = (int) ($_POST['course_id'] ?? 0);

            $stmt = $pdo->prepare("
                SELECT
                    (SELECT COUNT(*) FROM enrollments WHERE course_id = ?) +
                    (SELECT COUNT(*) FROM schedules WHERE course_id = ?) +
                    (SELECT COUNT(*) FROM course_instructors WHERE course_id = ?) +
                    (SELECT COUNT(*) FROM assignments WHERE course_id = ?)
                    AS related_count
            ");
            $stmt->execute([$courseId, $courseId, $courseId, $courseId]);
            $relatedCount = (int) $stmt->fetchColumn();

            if ($relatedCount > 0) {
                redirectWithMessage(
                    'لا يمكن حذف المقرر لوجود تسجيلات أو جداول أو تكليفات مرتبطة به.',
                    'error'
                );
            }

            $stmt = $pdo->prepare("DELETE FROM courses WHERE id = ?");
            $stmt->execute([$courseId]);

            redirectWithMessage('تم حذف المقرر بنجاح.');
        }

        if ($action === 'assign_faculty') {
            $courseId = (int) ($_POST['course_id'] ?? 0);
            $facultyId = (int) ($_POST['faculty_user_id'] ?? 0);
            $semester = trim($_POST['semester'] ?? '');
            $academicYear = trim($_POST['academic_year'] ?? '');

            if ($courseId <= 0 || $facultyId <= 0 || $semester === '' || $academicYear === '') {
                redirectWithMessage('يرجى استكمال بيانات إسناد المقرر.', 'error');
            }

            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM courses
                WHERE id = ?
            ");
            $stmt->execute([$courseId]);

            if (!$stmt->fetchColumn()) {
                redirectWithMessage('المقرر المحدد غير موجود.', 'error');
            }

            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM users u
                INNER JOIN faculty_members f ON f.user_id = u.id
                WHERE u.id = ? AND u.role = 'faculty' AND u.status = 'active'
            ");
            $stmt->execute([$facultyId]);

            if (!$stmt->fetchColumn()) {
                redirectWithMessage('عضو هيئة التدريس غير موجود أو حسابه غير نشط.', 'error');
            }

            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM course_instructors
                WHERE course_id = ?
                  AND faculty_user_id = ?
                  AND semester = ?
                  AND academic_year = ?
            ");
            $stmt->execute([$courseId, $facultyId, $semester, $academicYear]);

            if ($stmt->fetchColumn() > 0) {
                redirectWithMessage('هذا العضو مسند إليه المقرر بالفعل في الفصل نفسه.', 'error');
            }

            $stmt = $pdo->prepare("
                INSERT INTO course_instructors
                (course_id, faculty_user_id, semester, academic_year)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$courseId, $facultyId, $semester, $academicYear]);

            redirectWithMessage('تم إسناد المقرر إلى عضو هيئة التدريس بنجاح.');
        }

        if ($action === 'remove_faculty') {
            $assignmentId = (int) ($_POST['assignment_id'] ?? 0);

            $stmt = $pdo->prepare("
                DELETE FROM course_instructors
                WHERE id = ?
            ");
            $stmt->execute([$assignmentId]);

            redirectWithMessage('تم إلغاء إسناد عضو هيئة التدريس.');
        }

        if ($action === 'enroll_student') {
            $courseId = (int) ($_POST['course_id'] ?? 0);
            $studentId = (int) ($_POST['student_id'] ?? 0);
            $semester = trim($_POST['semester'] ?? '');
            $academicYear = trim($_POST['academic_year'] ?? '');

            if ($courseId <= 0 || $studentId <= 0 || $semester === '' || $academicYear === '') {
                redirectWithMessage('يرجى استكمال بيانات تسجيل الطالب.', 'error');
            }

            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM courses
                WHERE id = ?
            ");
            $stmt->execute([$courseId]);

            if (!$stmt->fetchColumn()) {
                redirectWithMessage('المقرر المحدد غير موجود.', 'error');
            }

            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM users u
                INNER JOIN students s ON s.user_id = u.id
                WHERE u.id = ? AND u.role = 'student' AND u.status = 'active'
            ");
            $stmt->execute([$studentId]);

            if (!$stmt->fetchColumn()) {
                redirectWithMessage('الطالب غير موجود أو حسابه غير نشط.', 'error');
            }

            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM enrollments
                WHERE student_id = ?
                  AND course_id = ?
                  AND semester = ?
                  AND academic_year = ?
            ");
            $stmt->execute([$studentId, $courseId, $semester, $academicYear]);

            if ($stmt->fetchColumn() > 0) {
                redirectWithMessage('الطالب مسجل في هذا المقرر بالفعل خلال الفصل نفسه.', 'error');
            }

            $stmt = $pdo->prepare("
                INSERT INTO enrollments
                (student_id, course_id, semester, academic_year, status)
                VALUES (?, ?, ?, ?, 'enrolled')
            ");
            $stmt->execute([$studentId, $courseId, $semester, $academicYear]);

            redirectWithMessage('تم تسجيل الطالب في المقرر بنجاح.');
        }

        if ($action === 'cancel_enrollment') {
            $enrollmentId = (int) ($_POST['enrollment_id'] ?? 0);

            $stmt = $pdo->prepare("
                UPDATE enrollments
                SET status = 'dropped'
                WHERE id = ?
            ");
            $stmt->execute([$enrollmentId]);

            redirectWithMessage('تم إلغاء تسجيل الطالب.');
        }
    }

    $totalCourses = (int) $pdo->query("
        SELECT COUNT(*) FROM courses
    ")->fetchColumn();

    $totalEnrollments = (int) $pdo->query("
        SELECT COUNT(*) FROM enrollments
        WHERE status = 'enrolled'
    ")->fetchColumn();

    $totalInstructors = (int) $pdo->query("
        SELECT COUNT(*) FROM course_instructors
    ")->fetchColumn();

    $totalHours = (int) $pdo->query("
        SELECT COALESCE(SUM(credit_hours), 0) FROM courses
    ")->fetchColumn();

    $courses = $pdo->query("
        SELECT
            c.*,
            (SELECT COUNT(*) FROM enrollments e
             WHERE e.course_id = c.id AND e.status = 'enrolled') AS student_count,
            (SELECT COUNT(*) FROM course_instructors ci
             WHERE ci.course_id = c.id) AS instructor_count
        FROM courses c
        ORDER BY c.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $facultyMembers = $pdo->query("
        SELECT u.id, u.full_name, f.employee_number, f.department
        FROM users u
        INNER JOIN faculty_members f ON f.user_id = u.id
        WHERE u.role = 'faculty' AND u.status = 'active'
        ORDER BY u.full_name
    ")->fetchAll(PDO::FETCH_ASSOC);

    $students = $pdo->query("
        SELECT u.id, u.full_name, s.student_number, s.department
        FROM users u
        INNER JOIN students s ON s.user_id = u.id
        WHERE u.role = 'student' AND u.status = 'active'
        ORDER BY u.full_name
    ")->fetchAll(PDO::FETCH_ASSOC);

    $assignments = $pdo->query("
        SELECT
            ci.id,
            ci.course_id,
            ci.faculty_user_id,
            ci.semester,
            ci.academic_year,
            c.course_code,
            c.course_name,
            u.full_name AS faculty_name
        FROM course_instructors ci
        INNER JOIN courses c ON c.id = ci.course_id
        INNER JOIN users u ON u.id = ci.faculty_user_id
        ORDER BY ci.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $enrollments = $pdo->query("
        SELECT
            e.id,
            e.semester,
            e.academic_year,
            e.status,
            c.course_code,
            c.course_name,
            u.full_name AS student_name,
            s.student_number
        FROM enrollments e
        INNER JOIN courses c ON c.id = e.course_id
        INNER JOIN students s ON s.user_id = e.student_id
        INNER JOIN users u ON u.id = s.user_id
        ORDER BY e.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $ex) {
    error_log($ex->getMessage());
    $pageError = 'حدث خطأ أثناء تنفيذ العملية. تحقق من بيانات قاعدة البيانات.';
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة المقررات والتسجيل | S-UCMS</title>

    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: Tahoma, Arial, sans-serif;
            background: #f4f6fa;
            color: #263247;
            padding: 25px;
        }

        .container {
            max-width: 1250px;
            margin: auto;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 25px;
        }

        h1 {
            font-size: 26px;
            color: #172b4d;
            margin-bottom: 8px;
        }

        .subtitle {
            color: #718096;
            font-size: 14px;
        }

        .back {
            text-decoration: none;
            color: white;
            background: #263f70;
            padding: 11px 18px;
            border-radius: 8px;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 25px;
        }

        .stat, .section {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 3px 12px rgba(0,0,0,.04);
        }

        .stat span {
            display: block;
            color: #718096;
            font-size: 14px;
            margin-bottom: 12px;
        }

        .stat strong {
            font-size: 27px;
            color: #263f70;
        }

        .section {
            margin-bottom: 22px;
        }

        .section h2 {
            font-size: 19px;
            color: #263f70;
            margin-bottom: 18px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
        }

        .field label {
            display: block;
            font-size: 13px;
            margin-bottom: 8px;
            color: #475569;
        }

        input, select, textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #dce2eb;
            border-radius: 7px;
            background: white;
            font-family: inherit;
            font-size: 14px;
        }

        textarea {
            min-height: 85px;
            resize: vertical;
        }

        .full {
            grid-column: 1 / -1;
        }

        button {
            border: 0;
            border-radius: 7px;
            padding: 11px 17px;
            font-family: inherit;
            cursor: pointer;
            font-size: 13px;
        }

        .primary {
            background: #263f70;
            color: white;
        }

        .danger {
            background: #fee2e2;
            color: #b91c1c;
        }

        .edit {
            background: #e0ecff;
            color: #1d4ed8;
        }

        .success {
            background: #dcfce7;
            color: #166534;
            padding: 13px;
            border-radius: 8px;
            margin-bottom: 18px;
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
            padding: 13px;
            border-radius: 8px;
            margin-bottom: 18px;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 750px;
        }

        th, td {
            padding: 13px 10px;
            text-align: right;
            border-bottom: 1px solid #edf0f5;
            font-size: 13px;
        }

        th {
            background: #f7f9fc;
            color: #475569;
        }

        .badge {
            display: inline-block;
            background: #e0ecff;
            color: #1d4ed8;
            padding: 5px 9px;
            border-radius: 20px;
            font-size: 12px;
        }

        .actions {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .search {
            margin-bottom: 15px;
        }

        .empty {
            text-align: center;
            padding: 25px;
            color: #94a3b8;
        }

        @media(max-width: 800px) {
            body { padding: 14px; }
            .stats { grid-template-columns: repeat(2, 1fr); }
            .form-grid { grid-template-columns: 1fr; }
            .full { grid-column: auto; }
            h1 { font-size: 22px; }
        }
    </style>
</head>

<body>
<div class="container">

    <div class="header">
        <div>
            <h1>إدارة المقررات والتسجيل</h1>
            <p class="subtitle">إدارة المقررات الأكاديمية وإسنادها لأعضاء هيئة التدريس وتسجيل الطلاب.</p>
        </div>
        <a class="back" href="dashboard.php">العودة إلى لوحة التحكم</a>
    </div>

    <?php if (!empty($message)): ?>
        <div class="<?= $messageType === 'error' ? 'error' : 'success' ?>">
            <?= e($message) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($pageError)): ?>
        <div class="error"><?= e($pageError) ?></div>
    <?php endif; ?>

    <div class="stats">
        <div class="stat">
            <span>إجمالي المقررات</span>
            <strong><?= $totalCourses ?? 0 ?></strong>
        </div>
        <div class="stat">
            <span>تسجيلات الطلاب النشطة</span>
            <strong><?= $totalEnrollments ?? 0 ?></strong>
        </div>
        <div class="stat">
            <span>إسنادات أعضاء هيئة التدريس</span>
            <strong><?= $totalInstructors ?? 0 ?></strong>
        </div>
        <div class="stat">
            <span>إجمالي الساعات المعتمدة</span>
            <strong><?= $totalHours ?? 0 ?></strong>
        </div>
    </div>

    <section class="section" id="courseForm">
        <h2 id="courseFormTitle">إضافة مقرر أكاديمي جديد</h2>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" id="courseAction" value="add_course">
            <input type="hidden" name="course_id" id="courseId">

            <div class="form-grid">
                <div class="field">
                    <label>رمز المقرر</label>
                    <input type="text" name="course_code" id="courseCode" required placeholder="مثال: IS103">
                </div>

                <div class="field">
                    <label>اسم المقرر</label>
                    <input type="text" name="course_name" id="courseName" required placeholder="اسم المقرر">
                </div>

                <div class="field">
                    <label>عدد الساعات المعتمدة</label>
                    <input type="number" name="credit_hours" id="creditHours" min="1" max="10" required>
                </div>

                <div class="field full">
                    <label>وصف المقرر</label>
                    <textarea name="description" id="courseDescription" placeholder="وصف مختصر للمقرر"></textarea>
                </div>

                <div class="full">
                    <button type="submit" class="primary" id="courseSubmit">إضافة المقرر</button>
                    <button type="button" class="edit" onclick="resetCourseForm()">إلغاء التعديل</button>
                </div>
            </div>
        </form>
    </section>

    <section class="section">
        <h2>قائمة المقررات الأكاديمية</h2>

        <input class="search" type="text" id="courseSearch"
               placeholder="ابحث برمز المقرر أو اسمه..." oninput="filterTable()">

        <div class="table-wrap">
            <table id="coursesTable">
                <thead>
                <tr>
                    <th>الرقم</th>
                    <th>رمز المقرر</th>
                    <th>اسم المقرر</th>
                    <th>الساعات</th>
                    <th>عدد الطلاب</th>
                    <th>أعضاء هيئة التدريس</th>
                    <th>الوصف</th>
                    <th>الإجراءات</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!empty($courses)): ?>
                    <?php foreach ($courses as $course): ?>
                        <tr>
                            <td><?= e($course['id']) ?></td>
                            <td><?= e($course['course_code']) ?></td>
                            <td><?= e($course['course_name']) ?></td>
                            <td><?= e($course['credit_hours']) ?></td>
                            <td><?= e($course['student_count']) ?></td>
                            <td><?= e($course['instructor_count']) ?></td>
                            <td><?= e($course['description']) ?></td>
                            <td>
                                <div class="actions">
                                    <button type="button" class="edit"
                                        onclick='editCourse(<?= json_encode([
                                            $course["id"],
                                            $course["course_code"],
                                            $course["course_name"],
                                            $course["credit_hours"],
                                            $course["description"]
                                        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                                        تعديل
                                    </button>

                                    <form method="POST" onsubmit="return confirm('هل أنت متأكد من حذف هذا المقرر؟');">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                        <input type="hidden" name="action" value="delete_course">
                                        <input type="hidden" name="course_id" value="<?= e($course['id']) ?>">
                                        <button type="submit" class="danger">حذف</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="8" class="empty">لا توجد مقررات حاليا.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="section">
        <h2>إسناد مقرر إلى عضو هيئة التدريس</h2>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="assign_faculty">

            <div class="form-grid">
                <div class="field">
                    <label>المقرر</label>
                    <select name="course_id" required>
                        <option value="">اختر المقرر</option>
                        <?php foreach (($courses ?? []) as $course): ?>
                            <option value="<?= e($course['id']) ?>">
                                <?= e($course['course_code'] . ' - ' . $course['course_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label>عضو هيئة التدريس</label>
                    <select name="faculty_user_id" required>
                        <option value="">اختر عضو هيئة التدريس</option>
                        <?php foreach (($facultyMembers ?? []) as $faculty): ?>
                            <option value="<?= e($faculty['id']) ?>">
                                <?= e($faculty['full_name'] . ' - ' . $faculty['employee_number']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label>الفصل الدراسي</label>
                    <select name="semester" required>
                        <option value="">اختر الفصل</option>
                        <option value="الأول">الأول</option>
                        <option value="الثاني">الثاني</option>
                        <option value="الصيفي">الصيفي</option>
                    </select>
                </div>

                <div class="field">
                    <label>العام الأكاديمي</label>
                    <input type="text" name="academic_year" required placeholder="مثال: 2026-2027">
                </div>

                <div class="full">
                    <button type="submit" class="primary">إسناد المقرر</button>
                </div>
            </div>
        </form>
    </section>

    <section class="section">
        <h2>أعضاء هيئة التدريس المسند إليهم المقررات</h2>

        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>المقرر</th>
                    <th>عضو هيئة التدريس</th>
                    <th>الفصل الدراسي</th>
                    <th>العام الأكاديمي</th>
                    <th>الإجراء</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!empty($assignments)): ?>
                    <?php foreach ($assignments as $assignment): ?>
                        <tr>
                            <td><?= e($assignment['course_code'] . ' - ' . $assignment['course_name']) ?></td>
                            <td><?= e($assignment['faculty_name']) ?></td>
                            <td><?= e($assignment['semester']) ?></td>
                            <td><?= e($assignment['academic_year']) ?></td>
                            <td>
                                <form method="POST" onsubmit="return confirm('هل تريد إلغاء هذا الإسناد؟');">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                    <input type="hidden" name="action" value="remove_faculty">
                                    <input type="hidden" name="assignment_id" value="<?= e($assignment['id']) ?>">
                                    <button type="submit" class="danger">إلغاء الإسناد</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="5" class="empty">لا توجد إسنادات حاليا.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="section">
        <h2>تسجيل طالب في مقرر</h2>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="enroll_student">

            <div class="form-grid">
                <div class="field">
                    <label>الطالب</label>
                    <select name="student_id" required>
                        <option value="">اختر الطالب</option>
                        <?php foreach (($students ?? []) as $student): ?>
                            <option value="<?= e($student['id']) ?>">
                                <?= e($student['full_name'] . ' - ' . $student['student_number']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label>المقرر</label>
                    <select name="course_id" required>
                        <option value="">اختر المقرر</option>
                        <?php foreach (($courses ?? []) as $course): ?>
                            <option value="<?= e($course['id']) ?>">
                                <?= e($course['course_code'] . ' - ' . $course['course_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label>الفصل الدراسي</label>
                    <select name="semester" required>
                        <option value="">اختر الفصل</option>
                        <option value="الأول">الأول</option>
                        <option value="الثاني">الثاني</option>
                        <option value="الصيفي">الصيفي</option>
                    </select>
                </div>

                <div class="field">
                    <label>العام الأكاديمي</label>
                    <input type="text" name="academic_year" required placeholder="مثال: 2026-2027">
                </div>

                <div class="full">
                    <button type="submit" class="primary">تسجيل الطالب</button>
                </div>
            </div>
        </form>
    </section>

    <section class="section">
        <h2>سجل تسجيل الطلاب في المقررات</h2>

        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>الرقم</th>
                    <th>رقم الطالب</th>
                    <th>اسم الطالب</th>
                    <th>المقرر</th>
                    <th>الفصل الدراسي</th>
                    <th>العام الأكاديمي</th>
                    <th>الحالة</th>
                    <th>الإجراء</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!empty($enrollments)): ?>
                    <?php foreach ($enrollments as $enrollment): ?>
                        <tr>
                            <td><?= e($enrollment['id']) ?></td>
                            <td><?= e($enrollment['student_number']) ?></td>
                            <td><?= e($enrollment['student_name']) ?></td>
                            <td><?= e($enrollment['course_code'] . ' - ' . $enrollment['course_name']) ?></td>
                            <td><?= e($enrollment['semester']) ?></td>
                            <td><?= e($enrollment['academic_year']) ?></td>
                            <td>
                                <span class="badge">
                                    <?= $enrollment['status'] === 'enrolled' ? 'مسجل' : 'ملغى' ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($enrollment['status'] === 'enrolled'): ?>
                                    <form method="POST" onsubmit="return confirm('هل تريد إلغاء تسجيل الطالب؟');">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                        <input type="hidden" name="action" value="cancel_enrollment">
                                        <input type="hidden" name="enrollment_id" value="<?= e($enrollment['id']) ?>">
                                        <button type="submit" class="danger">إلغاء التسجيل</button>
                                    </form>
                                <?php else: ?>
                                    <span>—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="8" class="empty">لا توجد تسجيلات حاليا.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

</div>

<script>
function editCourse(data) {
    document.getElementById('courseFormTitle').textContent = 'تعديل بيانات المقرر';
    document.getElementById('courseAction').value = 'edit_course';
    document.getElementById('courseId').value = data[0];
    document.getElementById('courseCode').value = data[1];
    document.getElementById('courseName').value = data[2];
    document.getElementById('creditHours').value = data[3];
    document.getElementById('courseDescription').value = data[4] || '';
    document.getElementById('courseSubmit').textContent = 'حفظ التعديلات';

    document.getElementById('courseForm').scrollIntoView({
        behavior: 'smooth'
    });
}

function resetCourseForm() {
    document.getElementById('courseFormTitle').textContent = 'إضافة مقرر أكاديمي جديد';
    document.getElementById('courseAction').value = 'add_course';
    document.getElementById('courseId').value = '';
    document.getElementById('courseCode').value = '';
    document.getElementById('courseName').value = '';
    document.getElementById('creditHours').value = '';
    document.getElementById('courseDescription').value = '';
    document.getElementById('courseSubmit').textContent = 'إضافة المقرر';
}

function filterTable() {
    const input = document.getElementById('courseSearch').value.toLowerCase();
    const rows = document.querySelectorAll('#coursesTable tbody tr');

    rows.forEach(row => {
        row.style.display = row.innerText.toLowerCase().includes(input)
            ? ''
            : 'none';
    });
}
</script>

</body>
</html>
