<?php
session_start();

require_once __DIR__ . '/config/database.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'administrator') {
    header('Location: index.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirectMessage($message, $type = 'success') {
    $_SESSION['flash_message'] = $message;
    $_SESSION['flash_type'] = $type;
    header('Location: admin_schedules.php');
    exit;
}

$message = $_SESSION['flash_message'] ?? '';
$messageType = $_SESSION['flash_type'] ?? 'success';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        if (!hash_equals($csrfToken, $_POST['csrf_token'] ?? '')) {
            redirectMessage('انتهت صلاحية الطلب.', 'error');
        }

        $action = $_POST['action'] ?? '';

        // إضافة أو تعديل قاعة
        if ($action === 'add_classroom' || $action === 'edit_classroom') {
            $roomId = (int)($_POST['room_id'] ?? 0);
            $roomCode = trim($_POST['room_code'] ?? '');
            $building = trim($_POST['building'] ?? '');
            $capacity = (int)($_POST['capacity'] ?? 0);
            $status = trim($_POST['status'] ?? 'available');

            if ($roomCode === '' || $building === '' || $capacity < 1) {
                redirectMessage('يرجى إدخال بيانات القاعة بشكل صحيح.', 'error');
            }

            if ($action === 'add_classroom') {
                $stmt = $pdo->prepare("
                    INSERT INTO classrooms
                    (room_code, building, capacity, status)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$roomCode, $building, $capacity, $status]);

                redirectMessage('تمت إضافة القاعة بنجاح.');
            }

            $stmt = $pdo->prepare("
                UPDATE classrooms
                SET room_code = ?, building = ?, capacity = ?, status = ?
                WHERE id = ?
            ");
            $stmt->execute([$roomCode, $building, $capacity, $status, $roomId]);

            redirectMessage('تم تعديل بيانات القاعة بنجاح.');
        }

        // حذف قاعة
        if ($action === 'delete_classroom') {
            $roomId = (int)($_POST['room_id'] ?? 0);

            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM schedules WHERE classroom_id = ?
            ");
            $stmt->execute([$roomId]);

            if ((int)$stmt->fetchColumn() > 0) {
                redirectMessage('لا يمكن حذف القاعة لوجود جداول مرتبطة بها.', 'error');
            }

            $stmt = $pdo->prepare("DELETE FROM classrooms WHERE id = ?");
            $stmt->execute([$roomId]);

            redirectMessage('تم حذف القاعة بنجاح.');
        }

        // إضافة أو تعديل جدول دراسي
        if ($action === 'add_schedule' || $action === 'edit_schedule') {
            $scheduleId = (int)($_POST['schedule_id'] ?? 0);
            $courseId = (int)($_POST['course_id'] ?? 0);
            $facultyId = (int)($_POST['faculty_user_id'] ?? 0);
            $classroomId = (int)($_POST['classroom_id'] ?? 0);
            $semester = trim($_POST['semester'] ?? '');
            $academicYear = trim($_POST['academic_year'] ?? '');
            $day = trim($_POST['day_of_week'] ?? '');
            $startTime = trim($_POST['start_time'] ?? '');
            $endTime = trim($_POST['end_time'] ?? '');

            $days = [
                'الأحد', 'الاثنين', 'الثلاثاء',
                'الأربعاء', 'الخميس', 'الجمعة', 'السبت'
            ];

            if (
                $courseId <= 0 || $facultyId <= 0 || $classroomId <= 0 ||
                $semester === '' || $academicYear === '' ||
                !in_array($day, $days, true) ||
                $startTime === '' || $endTime === '' ||
                $startTime >= $endTime
            ) {
                redirectMessage('يرجى إدخال بيانات الجدول بشكل صحيح.', 'error');
            }

            // التحقق من إسناد المقرر إلى عضو هيئة التدريس
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM course_instructors
                WHERE course_id = ?
                  AND faculty_user_id = ?
                  AND semester = ?
                  AND academic_year = ?
            ");
            $stmt->execute([$courseId, $facultyId, $semester, $academicYear]);

            if ((int)$stmt->fetchColumn() === 0) {
                redirectMessage(
                    'يجب إسناد المقرر إلى عضو هيئة التدريس في الفصل والعام المحددين أولا.',
                    'error'
                );
            }

            // التحقق من وجود القاعة
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM classrooms WHERE id = ?
            ");
            $stmt->execute([$classroomId]);

            if ((int)$stmt->fetchColumn() === 0) {
                redirectMessage('القاعة المحددة غير موجودة.', 'error');
            }

            // التحقق من تعارض القاعة أو عضو هيئة التدريس
            $sql = "
                SELECT COUNT(*)
                FROM schedules
                WHERE day_of_week = ?
                  AND semester = ?
                  AND academic_year = ?
                  AND start_time < ?
                  AND end_time > ?
                  AND (
                      classroom_id = ?
                      OR faculty_user_id = ?
                  )
            ";

            $params = [
                $day,
                $semester,
                $academicYear,
                $endTime,
                $startTime,
                $classroomId,
                $facultyId
            ];

            if ($action === 'edit_schedule') {
                $sql .= " AND id <> ?";
                $params[] = $scheduleId;
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            if ((int)$stmt->fetchColumn() > 0) {
                redirectMessage(
                    'يوجد تعارض في وقت القاعة أو جدول عضو هيئة التدريس.',
                    'error'
                );
            }

            if ($action === 'add_schedule') {
                $stmt = $pdo->prepare("
                    INSERT INTO schedules
                    (course_id, faculty_user_id, classroom_id,
                     semester, academic_year, day_of_week,
                     start_time, end_time)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $stmt->execute([
                    $courseId, $facultyId, $classroomId,
                    $semester, $academicYear, $day,
                    $startTime, $endTime
                ]);

                redirectMessage('تمت إضافة الجدول الدراسي بنجاح.');
            }

            $stmt = $pdo->prepare("
                UPDATE schedules
                SET course_id = ?, faculty_user_id = ?, classroom_id = ?,
                    semester = ?, academic_year = ?, day_of_week = ?,
                    start_time = ?, end_time = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $courseId, $facultyId, $classroomId,
                $semester, $academicYear, $day,
                $startTime, $endTime, $scheduleId
            ]);

            redirectMessage('تم تعديل الجدول الدراسي بنجاح.');
        }

        // حذف جدول
        if ($action === 'delete_schedule') {
            $scheduleId = (int)($_POST['schedule_id'] ?? 0);

            $stmt = $pdo->prepare("DELETE FROM schedules WHERE id = ?");
            $stmt->execute([$scheduleId]);

            redirectMessage('تم حذف الجدول الدراسي بنجاح.');
        }
    }

    // الإحصائيات
    $totalSchedules = (int)$pdo->query("
        SELECT COUNT(*) FROM schedules
    ")->fetchColumn();

    $totalClassrooms = (int)$pdo->query("
        SELECT COUNT(*) FROM classrooms
    ")->fetchColumn();

    $totalCourses = (int)$pdo->query("
        SELECT COUNT(DISTINCT course_id) FROM schedules
    ")->fetchColumn();

    $totalFaculty = (int)$pdo->query("
        SELECT COUNT(DISTINCT faculty_user_id) FROM schedules
    ")->fetchColumn();

    // القاعات
    $classrooms = $pdo->query("
        SELECT * FROM classrooms ORDER BY id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // المقررات
    $courses = $pdo->query("
        SELECT id, course_code, course_name
        FROM courses
        ORDER BY course_name
    ")->fetchAll(PDO::FETCH_ASSOC);

    // أعضاء هيئة التدريس
    $facultyMembers = $pdo->query("
        SELECT u.id, u.full_name, f.employee_number
        FROM users u
        INNER JOIN faculty_members f ON f.user_id = u.id
        WHERE u.role = 'faculty' AND u.status = 'active'
        ORDER BY u.full_name
    ")->fetchAll(PDO::FETCH_ASSOC);

    // الجداول الدراسية
    $schedules = $pdo->query("
        SELECT
            sc.*,
            c.course_code,
            c.course_name,
            u.full_name AS faculty_name,
            cl.room_code,
            cl.building
        FROM schedules sc
        INNER JOIN courses c ON c.id = sc.course_id
        INNER JOIN users u ON u.id = sc.faculty_user_id
        INNER JOIN classrooms cl ON cl.id = sc.classroom_id
        ORDER BY
            FIELD(sc.day_of_week,
            'الأحد','الاثنين','الثلاثاء','الأربعاء','الخميس','الجمعة','السبت'),
            sc.start_time
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
<title>إدارة الجداول والقاعات | S-UCMS</title>

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

input, select {
    width: 100%;
    padding: 12px;
    border: 1px solid #dce2eb;
    border-radius: 7px;
    background: white;
    font-family: inherit;
    font-size: 14px;
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
    min-width: 850px;
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

.actions {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
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
        <h1>إدارة الجداول الدراسية والقاعات</h1>
        <p class="subtitle">
            تنظيم الجداول الأكاديمية وإدارة القاعات والتحقق من تعارض المواعيد.
        </p>
    </div>
    <a class="back" href="dashboard.php">العودة إلى لوحة التحكم</a>
</div>

<?php if ($message): ?>
<div class="<?= $messageType === 'error' ? 'error' : 'success' ?>">
    <?= e($message) ?>
</div>
<?php endif; ?>

<?php if (!empty($pageError)): ?>
<div class="error"><?= e($pageError) ?></div>
<?php endif; ?>

<div class="stats">
    <div class="stat">
        <span>إجمالي الجداول الدراسية</span>
        <strong><?= $totalSchedules ?? 0 ?></strong>
    </div>
    <div class="stat">
        <span>إجمالي القاعات</span>
        <strong><?= $totalClassrooms ?? 0 ?></strong>
    </div>
    <div class="stat">
        <span>المقررات المجدولة</span>
        <strong><?= $totalCourses ?? 0 ?></strong>
    </div>
    <div class="stat">
        <span>أعضاء هيئة التدريس</span>
        <strong><?= $totalFaculty ?? 0 ?></strong>
    </div>
</div>

<section class="section" id="classroomForm">
<h2 id="classroomTitle">إضافة قاعة دراسية جديدة</h2>

<form method="POST">
<input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
<input type="hidden" name="action" id="classroomAction" value="add_classroom">
<input type="hidden" name="room_id" id="roomId">

<div class="form-grid">
    <div class="field">
        <label>رمز القاعة</label>
        <input type="text" name="room_code" id="roomCode" required placeholder="مثال: A101">
    </div>

    <div class="field">
        <label>المبنى</label>
        <input type="text" name="building" id="building" required placeholder="اسم المبنى">
    </div>

    <div class="field">
        <label>السعة الاستيعابية</label>
        <input type="number" name="capacity" id="capacity" min="1" required>
    </div>

    <div class="field">
        <label>حالة القاعة</label>
        <select name="status" id="roomStatus">
            <option value="available">متاحة</option>
            <option value="unavailable">غير متاحة</option>
        </select>
    </div>

    <div class="full">
        <button type="submit" class="primary" id="classroomSubmit">إضافة القاعة</button>
        <button type="button" class="edit" onclick="resetClassroomForm()">إلغاء التعديل</button>
    </div>
</div>
</form>
</section>

<section class="section">
<h2>قائمة القاعات الدراسية</h2>

<div class="table-wrap">
<table>
<thead>
<tr>
    <th>الرقم</th>
    <th>رمز القاعة</th>
    <th>المبنى</th>
    <th>السعة</th>
    <th>الحالة</th>
    <th>الإجراءات</th>
</tr>
</thead>
<tbody>
<?php if (!empty($classrooms)): ?>
<?php foreach ($classrooms as $room): ?>
<tr>
    <td><?= e($room['id']) ?></td>
    <td><?= e($room['room_code']) ?></td>
    <td><?= e($room['building']) ?></td>
    <td><?= e($room['capacity']) ?></td>
    <td><?= e($room['status']) ?></td>
    <td>
        <div class="actions">
            <button type="button" class="edit"
                onclick='editClassroom(<?= json_encode([
                    $room["id"],
                    $room["room_code"],
                    $room["building"],
                    $room["capacity"],
                    $room["status"]
                ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                تعديل
            </button>

            <form method="POST" onsubmit="return confirm('هل تريد حذف هذه القاعة؟');">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="delete_classroom">
                <input type="hidden" name="room_id" value="<?= e($room['id']) ?>">
                <button type="submit" class="danger">حذف</button>
            </form>
        </div>
    </td>
</tr>
<?php endforeach; ?>
<?php else: ?>
<tr><td colspan="6" class="empty">لا توجد قاعات مسجلة حاليا.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</section>

<section class="section" id="scheduleForm">
<h2 id="scheduleTitle">إضافة جدول دراسي جديد</h2>

<form method="POST">
<input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
<input type="hidden" name="action" id="scheduleAction" value="add_schedule">
<input type="hidden" name="schedule_id" id="scheduleId">

<div class="form-grid">

    <div class="field">
        <label>المقرر</label>
        <select name="course_id" id="courseId" required>
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
        <select name="faculty_user_id" id="facultyId" required>
            <option value="">اختر عضو هيئة التدريس</option>
            <?php foreach (($facultyMembers ?? []) as $faculty): ?>
            <option value="<?= e($faculty['id']) ?>">
                <?= e($faculty['full_name'] . ' - ' . $faculty['employee_number']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="field">
        <label>القاعة الدراسية</label>
        <select name="classroom_id" id="classroomId" required>
            <option value="">اختر القاعة</option>
            <?php foreach (($classrooms ?? []) as $room): ?>
            <option value="<?= e($room['id']) ?>">
                <?= e($room['room_code'] . ' - ' . $room['building']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="field">
        <label>اليوم</label>
        <select name="day_of_week" id="day" required>
            <option value="">اختر اليوم</option>
            <option value="الأحد">الأحد</option>
            <option value="الاثنين">الاثنين</option>
            <option value="الثلاثاء">الثلاثاء</option>
            <option value="الأربعاء">الأربعاء</option>
            <option value="الخميس">الخميس</option>
            <option value="الجمعة">الجمعة</option>
            <option value="السبت">السبت</option>
        </select>
    </div>

    <div class="field">
        <label>الفصل الدراسي</label>
        <select name="semester" id="semester" required>
            <option value="">اختر الفصل</option>
            <option value="الأول">الأول</option>
            <option value="الثاني">الثاني</option>
            <option value="الصيفي">الصيفي</option>
        </select>
    </div>

    <div class="field">
        <label>العام الأكاديمي</label>
        <input type="text" name="academic_year" id="academicYear" required placeholder="2026-2027">
    </div>

    <div class="field">
        <label>وقت البداية</label>
        <input type="time" name="start_time" id="startTime" required>
    </div>

    <div class="field">
        <label>وقت النهاية</label>
        <input type="time" name="end_time" id="endTime" required>
    </div>

    <div class="full">
        <button type="submit" class="primary" id="scheduleSubmit">إضافة الجدول</button>
        <button type="button" class="edit" onclick="resetScheduleForm()">إلغاء التعديل</button>
    </div>

</div>
</form>
</section>

<section class="section">
<h2>قائمة الجداول الدراسية</h2>

<div class="table-wrap">
<table>
<thead>
<tr>
    <th>الرقم</th>
    <th>المقرر</th>
    <th>عضو هيئة التدريس</th>
    <th>القاعة</th>
    <th>اليوم</th>
    <th>الفصل</th>
    <th>العام الأكاديمي</th>
    <th>من</th>
    <th>إلى</th>
    <th>الإجراءات</th>
</tr>
</thead>
<tbody>
<?php if (!empty($schedules)): ?>
<?php foreach ($schedules as $schedule): ?>
<tr>
    <td><?= e($schedule['id']) ?></td>
    <td><?= e($schedule['course_code'] . ' - ' . $schedule['course_name']) ?></td>
    <td><?= e($schedule['faculty_name']) ?></td>
    <td><?= e($schedule['room_code'] . ' - ' . $schedule['building']) ?></td>
    <td><?= e($schedule['day_of_week']) ?></td>
    <td><?= e($schedule['semester']) ?></td>
    <td><?= e($schedule['academic_year']) ?></td>
    <td><?= e(substr($schedule['start_time'], 0, 5)) ?></td>
    <td><?= e(substr($schedule['end_time'], 0, 5)) ?></td>
    <td>
        <div class="actions">
            <button type="button" class="edit"
                onclick='editSchedule(<?= json_encode([
                    $schedule["id"],
                    $schedule["course_id"],
                    $schedule["faculty_user_id"],
                    $schedule["classroom_id"],
                    $schedule["day_of_week"],
                    $schedule["semester"],
                    $schedule["academic_year"],
                    substr($schedule["start_time"], 0, 5),
                    substr($schedule["end_time"], 0, 5)
                ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                تعديل
            </button>

            <form method="POST" onsubmit="return confirm('هل تريد حذف هذا الجدول؟');">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="delete_schedule">
                <input type="hidden" name="schedule_id" value="<?= e($schedule['id']) ?>">
                <button type="submit" class="danger">حذف</button>
            </form>
        </div>
    </td>
</tr>
<?php endforeach; ?>
<?php else: ?>
<tr><td colspan="10" class="empty">لا توجد جداول دراسية حاليا.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</section>

</div>

<script>
function editClassroom(data) {
    document.getElementById('classroomTitle').textContent = 'تعديل بيانات القاعة';
    document.getElementById('classroomAction').value = 'edit_classroom';
    document.getElementById('roomId').value = data[0];
    document.getElementById('roomCode').value = data[1];
    document.getElementById('building').value = data[2];
    document.getElementById('capacity').value = data[3];
    document.getElementById('roomStatus').value = data[4];
    document.getElementById('classroomSubmit').textContent = 'حفظ التعديلات';

    document.getElementById('classroomForm').scrollIntoView({behavior:'smooth'});
}

function resetClassroomForm() {
    document.getElementById('classroomTitle').textContent = 'إضافة قاعة دراسية جديدة';
    document.getElementById('classroomAction').value = 'add_classroom';
    document.getElementById('roomId').value = '';
    document.getElementById('roomCode').value = '';
    document.getElementById('building').value = '';
    document.getElementById('capacity').value = '';
    document.getElementById('roomStatus').value = 'available';
    document.getElementById('classroomSubmit').textContent = 'إضافة القاعة';
}

function editSchedule(data) {
    document.getElementById('scheduleTitle').textContent = 'تعديل الجدول الدراسي';
    document.getElementById('scheduleAction').value = 'edit_schedule';
    document.getElementById('scheduleId').value = data[0];
    document.getElementById('courseId').value = data[1];
    document.getElementById('facultyId').value = data[2];
    document.getElementById('classroomId').value = data[3];
    document.getElementById('day').value = data[4];
    document.getElementById('semester').value = data[5];
    document.getElementById('academicYear').value = data[6];
    document.getElementById('startTime').value = data[7];
    document.getElementById('endTime').value = data[8];
    document.getElementById('scheduleSubmit').textContent = 'حفظ التعديلات';

    document.getElementById('scheduleForm').scrollIntoView({behavior:'smooth'});
}

function resetScheduleForm() {
    document.getElementById('scheduleTitle').textContent = 'إضافة جدول دراسي جديد';
    document.getElementById('scheduleAction').value = 'add_schedule';
    document.getElementById('scheduleId').value = '';
    document.getElementById('courseId').value = '';
    document.getElementById('facultyId').value = '';
    document.getElementById('classroomId').value = '';
    document.getElementById('day').value = '';
    document.getElementById('semester').value = '';
    document.getElementById('academicYear').value = '';
    document.getElementById('startTime').value = '';
    document.getElementById('endTime').value = '';
    document.getElementById('scheduleSubmit').textContent = 'إضافة الجدول';
}
</script>

</body>
</html>
