<?php

session_start();

require_once __DIR__ . '/config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

if (($_SESSION['role'] ?? '') !== 'administrator') {
    http_response_code(403);
    exit('غير مصرح لك بالوصول إلى هذه الصفحة.');
}

$userId = (int) $_SESSION['user_id'];

$checkAdmin = $pdo->prepare(
    "SELECT id, role, status FROM users WHERE id = ? LIMIT 1"
);
$checkAdmin->execute([$userId]);
$admin = $checkAdmin->fetch();

if (
    !$admin ||
    $admin['role'] !== 'administrator' ||
    $admin['status'] !== 'active'
) {
    session_destroy();
    header('Location: index.php');
    exit;
}

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['schedules_csrf'])) {
    $_SESSION['schedules_csrf'] = bin2hex(random_bytes(32));
}

$error = '';
$success = '';

$days = [
    'Sunday' => 'الأحد',
    'Monday' => 'الاثنين',
    'Tuesday' => 'الثلاثاء',
    'Wednesday' => 'الأربعاء',
    'Thursday' => 'الخميس',
    'Friday' => 'الجمعة',
    'Saturday' => 'السبت'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['schedules_csrf'], $csrfToken)) {
        $error = 'انتهت صلاحية الطلب. يرجى تحديث الصفحة.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'add' || $action === 'update') {
            $id = (int) ($_POST['id'] ?? 0);
            $courseId = (int) ($_POST['course_id'] ?? 0);
            $facultyId = (int) ($_POST['faculty_user_id'] ?? 0);
            $classroomId = (int) ($_POST['classroom_id'] ?? 0);
            $semester = trim($_POST['semester'] ?? '');
            $academicYear = trim($_POST['academic_year'] ?? '');
            $day = $_POST['day_of_week'] ?? '';
            $startTime = $_POST['start_time'] ?? '';
            $endTime = $_POST['end_time'] ?? '';

            if (
                $courseId <= 0 ||
                $facultyId <= 0 ||
                $classroomId <= 0 ||
                $semester === '' ||
                $academicYear === '' ||
                !isset($days[$day]) ||
                $startTime === '' ||
                $endTime === ''
            ) {
                $error = 'يرجى تعبئة جميع الحقول المطلوبة.';
            } elseif (
                strlen($semester) > 50 ||
                strlen($academicYear) > 20
            ) {
                $error = 'تجاوز الفصل أو العام الأكاديمي الحد المسموح.';
            } elseif ($startTime >= $endTime) {
                $error = 'يجب أن يكون وقت النهاية بعد وقت البداية.';
            } elseif ($action === 'update' && $id <= 0) {
                $error = 'رقم الجدول غير صحيح.';
            } else {
                try {
                    $courseCheck = $pdo->prepare(
                        "SELECT id FROM courses WHERE id = ?"
                    );
                    $courseCheck->execute([$courseId]);

                    $facultyCheck = $pdo->prepare(
                        "SELECT fm.user_id
                         FROM faculty_members fm
                         INNER JOIN users u ON u.id = fm.user_id
                         WHERE fm.user_id = ? AND u.status = 'active'
                         AND u.role = 'faculty'"
                    );
                    $facultyCheck->execute([$facultyId]);

                    $roomCheck = $pdo->prepare(
                        "SELECT id FROM classrooms
                         WHERE id = ? AND status = 'available'"
                    );
                    $roomCheck->execute([$classroomId]);

                    if (!$courseCheck->fetch()) {
                        $error = 'المقرر المحدد غير موجود.';
                    } elseif (!$facultyCheck->fetch()) {
                        $error = 'عضو هيئة التدريس المحدد غير موجود أو غير نشط.';
                    } elseif (!$roomCheck->fetch()) {
                        $error = 'القاعة المحددة غير متاحة.';
                    } else {
                        $conflictSql = "
                            SELECT COUNT(*)
                            FROM schedules
                            WHERE day_of_week = ?
                            AND semester = ?
                            AND academic_year = ?
                            AND (
                                faculty_user_id = ?
                                OR classroom_id = ?
                            )
                            AND start_time < ?
                            AND end_time > ?
                        ";

                        $conflictParams = [
                            $day,
                            $semester,
                            $academicYear,
                            $facultyId,
                            $classroomId,
                            $endTime,
                            $startTime
                        ];

                        if ($action === 'update') {
                            $conflictSql .= " AND id <> ?";
                            $conflictParams[] = $id;
                        }

                        $conflictCheck = $pdo->prepare($conflictSql);
                        $conflictCheck->execute($conflictParams);

                        if ((int) $conflictCheck->fetchColumn() > 0) {
                            $error = 'يوجد تعارض في وقت المدرس أو القاعة ضمن اليوم والفصل والعام المحدد.';
                        } else {
                            if ($action === 'add') {
                                $stmt = $pdo->prepare(
                                    "INSERT INTO schedules
                                    (
                                        course_id,
                                        faculty_user_id,
                                        classroom_id,
                                        semester,
                                        academic_year,
                                        day_of_week,
                                        start_time,
                                        end_time
                                    )
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                                );

                                $stmt->execute([
                                    $courseId,
                                    $facultyId,
                                    $classroomId,
                                    $semester,
                                    $academicYear,
                                    $day,
                                    $startTime,
                                    $endTime
                                ]);

                                $success = 'تمت إضافة الجدول الدراسي بنجاح.';
                            } else {
                                $stmt = $pdo->prepare(
                                    "UPDATE schedules SET
                                        course_id = ?,
                                        faculty_user_id = ?,
                                        classroom_id = ?,
                                        semester = ?,
                                        academic_year = ?,
                                        day_of_week = ?,
                                        start_time = ?,
                                        end_time = ?
                                    WHERE id = ?"
                                );

                                $stmt->execute([
                                    $courseId,
                                    $facultyId,
                                    $classroomId,
                                    $semester,
                                    $academicYear,
                                    $day,
                                    $startTime,
                                    $endTime,
                                    $id
                                ]);

                                $success = 'تم تحديث الجدول الدراسي بنجاح.';
                            }
                        }
                    }
                } catch (PDOException $ex) {
                    error_log($ex->getMessage());
                    $error = 'حدث خطأ أثناء حفظ الجدول الدراسي.';
                }
            }
        } elseif ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);

            if ($id <= 0) {
                $error = 'رقم الجدول غير صحيح.';
            } else {
                try {
                    $stmt = $pdo->prepare(
                        "DELETE FROM schedules WHERE id = ?"
                    );
                    $stmt->execute([$id]);

                    $success = $stmt->rowCount() > 0
                        ? 'تم حذف الجدول الدراسي بنجاح.'
                        : 'الجدول غير موجود.';
                } catch (PDOException $ex) {
                    error_log($ex->getMessage());
                    $error = 'تعذر حذف الجدول الدراسي.';
                }
            }
        } else {
            $error = 'العملية المطلوبة غير صحيحة.';
        }

        $_SESSION['schedules_csrf'] = bin2hex(random_bytes(32));
    }
}

$courses = $pdo->query(
    "SELECT id, course_code, course_name
     FROM courses ORDER BY course_code"
)->fetchAll();

$facultyMembers = $pdo->query(
    "SELECT fm.user_id, fm.employee_number, fm.academic_title,
            u.full_name
     FROM faculty_members fm
     INNER JOIN users u ON u.id = fm.user_id
     WHERE u.role = 'faculty' AND u.status = 'active'
     ORDER BY u.full_name"
)->fetchAll();

$classrooms = $pdo->query(
    "SELECT id, room_code, building, capacity
     FROM classrooms
     WHERE status = 'available'
     ORDER BY room_code"
)->fetchAll();

$search = trim($_GET['search'] ?? '');

$sql = "
    SELECT
        s.*,
        c.course_code,
        c.course_name,
        u.full_name AS faculty_name,
        fm.employee_number,
        cr.room_code,
        cr.building
    FROM schedules s
    INNER JOIN courses c ON c.id = s.course_id
    INNER JOIN faculty_members fm ON fm.user_id = s.faculty_user_id
    INNER JOIN users u ON u.id = fm.user_id
    INNER JOIN classrooms cr ON cr.id = s.classroom_id
";

if ($search !== '') {
    $sql .= "
        WHERE c.course_code LIKE ?
        OR c.course_name LIKE ?
        OR u.full_name LIKE ?
        OR cr.room_code LIKE ?
    ";
}

$sql .= "
    ORDER BY
        FIELD(s.day_of_week,
            'Sunday','Monday','Tuesday','Wednesday',
            'Thursday','Friday','Saturday'
        ),
        s.start_time
";

$stmt = $pdo->prepare($sql);

if ($search !== '') {
    $term = '%' . $search . '%';
    $stmt->execute([$term, $term, $term, $term]);
} else {
    $stmt->execute();
}

$schedules = $stmt->fetchAll();

$totalSchedules = (int) $pdo->query(
    "SELECT COUNT(*) FROM schedules"
)->fetchColumn();

$editSchedule = null;

if (isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];

    if ($editId > 0) {
        $editStmt = $pdo->prepare(
            "SELECT * FROM schedules WHERE id = ? LIMIT 1"
        );
        $editStmt->execute([$editId]);
        $editSchedule = $editStmt->fetch();

        if (!$editSchedule) {
            $error = 'الجدول المطلوب تعديله غير موجود.';
        }
    }
}

$isEditing = (bool) $editSchedule;

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة الجداول الدراسية | S-UCMS</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Tahoma, Arial, sans-serif;
            background: #f4f7fb;
            color: #243247;
        }

        .header {
            background: #172b4d;
            color: white;
            padding: 22px 6%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .header h1 {
            margin: 0;
            font-size: 22px;
        }

        .header a {
            color: white;
            text-decoration: none;
            background: #29466f;
            padding: 10px 16px;
            border-radius: 8px;
        }

        .container {
            width: 92%;
            max-width: 1250px;
            margin: 30px auto;
        }

        .stat {
            background: white;
            padding: 22px;
            border-radius: 12px;
            margin-bottom: 24px;
            box-shadow: 0 3px 12px rgba(0,0,0,.04);
        }

        .stat span {
            color: #64748b;
            font-size: 14px;
        }

        .stat strong {
            display: block;
            font-size: 28px;
            margin-top: 10px;
            color: #176b87;
        }

        .panel {
            background: white;
            padding: 24px;
            border-radius: 12px;
            margin-bottom: 24px;
            box-shadow: 0 3px 12px rgba(0,0,0,.04);
        }

        .panel h2 {
            margin: 0 0 20px;
            font-size: 19px;
            color: #172b4d;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .field.full {
            grid-column: 1 / -1;
        }

        label {
            font-size: 14px;
            font-weight: bold;
        }

        input, select {
            width: 100%;
            padding: 12px;
            border: 1px solid #d7dfeb;
            border-radius: 8px;
            font-family: inherit;
            font-size: 14px;
            background: white;
        }

        button, .btn {
            border: 0;
            border-radius: 8px;
            padding: 11px 17px;
            font-family: inherit;
            font-size: 14px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }

        .primary {
            background: #176b87;
            color: white;
        }

        .secondary {
            background: #e8edf5;
            color: #243247;
        }

        .edit {
            background: #e8f2ff;
            color: #245a9a;
        }

        .delete {
            background: #fff0f0;
            color: #c0392b;
        }

        .actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .message {
            padding: 13px 16px;
            border-radius: 8px;
            margin-bottom: 18px;
            font-size: 14px;
        }

        .success {
            background: #e8f7ee;
            color: #176b3a;
        }

        .error {
            background: #fff0f0;
            color: #a52a2a;
        }

        .search-form {
            display: flex;
            gap: 10px;
            margin-bottom: 18px;
        }

        .search-form input {
            flex: 1;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1000px;
        }

        th, td {
            padding: 13px 11px;
            text-align: right;
            border-bottom: 1px solid #edf0f5;
            font-size: 13px;
        }

        th {
            background: #f5f8fc;
            color: #52647d;
        }

        .empty {
            text-align: center;
            padding: 30px;
            color: #78869a;
        }

        @media (max-width: 650px) {
            .form-grid {
                grid-template-columns: 1fr;
            }

            .field.full {
                grid-column: auto;
            }

            .panel {
                padding: 17px;
            }

            .search-form {
                flex-direction: column;
            }

            .header {
                padding: 18px 4%;
            }

            .header h1 {
                font-size: 18px;
            }
        }
    </style>
</head>

<body>

<header class="header">
    <h1>إدارة الجداول الدراسية</h1>
    <a href="dashboard.php">العودة إلى لوحة التحكم</a>
</header>

<main class="container">

    <section class="stat">
        <span>إجمالي الجداول الدراسية</span>
        <strong><?= e($totalSchedules) ?></strong>
    </section>

    <?php if ($success !== ''): ?>
        <div class="message success"><?= e($success) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="message error"><?= e($error) ?></div>
    <?php endif; ?>

    <section class="panel">
        <h2><?= $isEditing ? 'تعديل جدول دراسي' : 'إضافة جدول دراسي جديد' ?></h2>

        <form method="POST" action="manage_schedules.php<?= $isEditing ? '?edit=' . (int) $editSchedule['id'] : '' ?>">

            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['schedules_csrf']) ?>">
            <input type="hidden" name="action" value="<?= $isEditing ? 'update' : 'add' ?>">

            <?php if ($isEditing): ?>
                <input type="hidden" name="id" value="<?= (int) $editSchedule['id'] ?>">
            <?php endif; ?>

            <div class="form-grid">

                <div class="field">
                    <label for="course_id">المقرر الدراسي</label>
                    <select name="course_id" id="course_id" required>
                        <option value="">اختر المقرر</option>
                        <?php foreach ($courses as $course): ?>
                            <option
                                value="<?= (int) $course['id'] ?>"
                                <?= (int) ($editSchedule['course_id'] ?? 0) === (int) $course['id'] ? 'selected' : '' ?>
                            >
                                <?= e($course['course_code'] . ' - ' . $course['course_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="faculty_user_id">عضو هيئة التدريس</label>
                    <select name="faculty_user_id" id="faculty_user_id" required>
                        <option value="">اختر عضو هيئة التدريس</option>
                        <?php foreach ($facultyMembers as $faculty): ?>
                            <option
                                value="<?= (int) $faculty['user_id'] ?>"
                                <?= (int) ($editSchedule['faculty_user_id'] ?? 0) === (int) $faculty['user_id'] ? 'selected' : '' ?>
                            >
                                <?= e($faculty['full_name'] . ' - ' . $faculty['employee_number']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="classroom_id">القاعة الدراسية</label>
                    <select name="classroom_id" id="classroom_id" required>
                        <option value="">اختر القاعة</option>
                        <?php foreach ($classrooms as $room): ?>
                            <option
                                value="<?= (int) $room['id'] ?>"
                                <?= (int) ($editSchedule['classroom_id'] ?? 0) === (int) $room['id'] ? 'selected' : '' ?>
                            >
                                <?= e($room['room_code'] . ' - ' . $room['building']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="semester">الفصل الدراسي</label>
                    <input
                        type="text"
                        id="semester"
                        name="semester"
                        maxlength="50"
                        required
                        value="<?= e($editSchedule['semester'] ?? '') ?>"
                        placeholder="مثال: الفصل الأول"
                    >
                </div>

                <div class="field">
                    <label for="academic_year">العام الأكاديمي</label>
                    <input
                        type="text"
                        id="academic_year"
                        name="academic_year"
                        maxlength="20"
                        required
                        value="<?= e($editSchedule['academic_year'] ?? '') ?>"
                        placeholder="مثال: 2026-2027"
                    >
                </div>

                <div class="field">
                    <label for="day_of_week">اليوم</label>
                    <select name="day_of_week" id="day_of_week" required>
                        <option value="">اختر اليوم</option>
                        <?php foreach ($days as $key => $label): ?>
                            <option
                                value="<?= e($key) ?>"
                                <?= ($editSchedule['day_of_week'] ?? '') === $key ? 'selected' : '' ?>
                            >
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="start_time">وقت البداية</label>
                    <input
                        type="time"
                        id="start_time"
                        name="start_time"
                        required
                        value="<?= e(isset($editSchedule['start_time']) ? substr($editSchedule['start_time'], 0, 5) : '') ?>"
                    >
                </div>

                <div class="field">
                    <label for="end_time">وقت النهاية</label>
                    <input
                        type="time"
                        id="end_time"
                        name="end_time"
                        required
                        value="<?= e(isset($editSchedule['end_time']) ? substr($editSchedule['end_time'], 0, 5) : '') ?>"
                    >
                </div>

                <div class="field full">
                    <div class="actions">
                        <button type="submit" class="primary">
                            <?= $isEditing ? 'حفظ التعديلات' : 'إضافة الجدول' ?>
                        </button>

                        <?php if ($isEditing): ?>
                            <a href="manage_schedules.php" class="btn secondary">إلغاء التعديل</a>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </form>
    </section>

    <section class="panel">
        <h2>قائمة الجداول الدراسية</h2>

        <form method="GET" action="manage_schedules.php" class="search-form">
            <input
                type="search"
                name="search"
                value="<?= e($search) ?>"
                placeholder="ابحث باسم المقرر أو المدرس أو القاعة..."
            >
            <button type="submit" class="primary">بحث</button>
            <a href="manage_schedules.php" class="btn secondary">عرض الكل</a>
        </form>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>المقرر</th>
                        <th>عضو هيئة التدريس</th>
                        <th>القاعة</th>
                        <th>الفصل الدراسي</th>
                        <th>العام الأكاديمي</th>
                        <th>اليوم</th>
                        <th>من</th>
                        <th>إلى</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (count($schedules) > 0): ?>
                        <?php foreach ($schedules as $index => $schedule): ?>
                            <tr>
                                <td><?= $index + 1 ?></td>
                                <td>
                                    <?= e($schedule['course_code']) ?>
                                    - <?= e($schedule['course_name']) ?>
                                </td>
                                <td><?= e($schedule['faculty_name']) ?></td>
                                <td>
                                    <?= e($schedule['room_code']) ?>
                                    - <?= e($schedule['building']) ?>
                                </td>
                                <td><?= e($schedule['semester']) ?></td>
                                <td><?= e($schedule['academic_year']) ?></td>
                                <td><?= e($days[$schedule['day_of_week']] ?? $schedule['day_of_week']) ?></td>
                                <td><?= e(substr($schedule['start_time'], 0, 5)) ?></td>
                                <td><?= e(substr($schedule['end_time'], 0, 5)) ?></td>
                                <td>
                                    <div class="actions">
                                        <a
                                            class="btn edit"
                                            href="manage_schedules.php?edit=<?= (int) $schedule['id'] ?>"
                                        >تعديل</a>

                                        <form
                                            method="POST"
                                            action="manage_schedules.php"
                                            onsubmit="return confirm('هل أنت متأكد من حذف هذا الجدول؟');"
                                        >
                                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['schedules_csrf']) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int) $schedule['id'] ?>">
                                            <button type="submit" class="delete">حذف</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="10" class="empty">لا توجد جداول دراسية لعرضها.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

</main>

</body>
</html>
